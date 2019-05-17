<?php

namespace Drupal\workbench_access_protect\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Entity\EntityInterface;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Core\Routing\CurrentRouteMatch;
use Drupal\workbench_access\UserSectionStorage;
use Drupal\Core\Entity\EntityFieldManager;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\workbench_access\Entity\AccessSchemeInterface;

/**
 * Class TaxonomyDeleteAccessCheck.
 */
class DeleteAccessCheck implements DeleteAccessCheckInterface {

  /**
   * @var \Drupal\Core\Session\AccountInterface
   */
  private $account;

  /**
   * @var \Drupal\Core\Routing\CurrentRouteMatch;
   */
  private $route;

  /**
   * @var \Drupal\workbench_access\UserSectionStorage
   */
  private $userSectionStorage;

  /**
   * @var \Drupal\Core\Entity\EntityFieldManager
   */
  private $fieldManager;

  /**
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  private $entityTypeManager;

  /**
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  private $entityTypeBundleInfo;


  public function __construct(CurrentRouteMatch $route,
                              UserSectionStorage $userSectionStorage,
                              EntityFieldManager $fieldManager,
                              EntityTypeManager $entityTypeManager,
                              EntityTypeBundleInfoInterface $entityTypeBundleInfo) {

    $this->route = $route;
    $this->userSectionStorage = $userSectionStorage;
    $this->fieldManager = $fieldManager;
    $this->entityTypeManager = $entityTypeManager;
    $this->entityTypeBundleInfo = $entityTypeBundleInfo;

  }

  /**
   * This method is used to determine if it is OK to delete.
   *
   * The check is based on whether or not it is being actively used for access
   * control, and if content is assigned to it. If either of these statements
   * is true, then 'forbidden' will be returned to prevent the term
   * from being deleted.
   *
   * @return \Drupal\Core\Access\AccessResultAllowed|\Drupal\Core\Access\AccessResultForbidden
   *   Returns 'forbidden' if the term is being used for access control.
   *   Returns 'allowed' if the term is not being used for access control.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function access() {

    if ($this->isDeleteAllowed()) {
      return AccessResult::allowed();
    }

    return AccessResult::forbidden();
  }

  /**
   * @{inheritdoc}
   */
  public function isDeleteAllowed(EntityInterface $entity) {

    $retval = TRUE;

    if ($entity instanceof EntityInterface) {

      $hasAccessControlMembers = $this->doesTermHaveMembers($entity);
      $assigned_content = $this->isAssignedToContent($entity);

      /*
       * If this entity does not have users assigned to it for access
       * control, and the term is not assigned to any pieces of content,
       * it is OK to delete it.
       */
      if ($hasAccessControlMembers || $assigned_content) {

        if ($assigned_content) {
          $retval = FALSE;
        }

        if ($hasAccessControlMembers) {
          $retval = FALSE;
        }
      }

    }

    return $retval;
  }

  public function getBundles(EntityInterface $entity) {
    $bundle = $this->entityTypeManager->getDefinitions()[$entity->getEntityTypeId()]->get('bundle_of');
    $bundles = $this->entityTypeBundleInfo->getBundleInfo($bundle);
    return array_combine(array_keys($bundles), array_keys($bundles));

  }

  public function hasBundles(EntityInterface $entity) {
    if ($this->entityTypeManager->getDefinitions()[$entity->getEntityTypeId()]->get('bundle_of') == null) {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * {@inheritDoc}
   */
  public function isDeleteAllowedBundle($bundle, $entity){
    $bundle_of = $this->entityTypeManager->getDefinitions()[$entity->getEntityTypeId()]->get('bundle_of');
    $entity_id_key = $this->entityTypeManager->getDefinitions()[$entity->getEntityTypeId()]->get('entity_keys')['id'];
    $entities = $this->entityTypeManager->getStorage($bundle_of)->loadByProperties(
      [$entity_id_key => $bundle ]
    );

    /*
     * Cycle through the entities of this bundle. As soon as one is discovered
     * as being actively used for access control, we can deny delete.
     */
    foreach ($entities as $bundle) {
      if ($this->isDeleteAllowed($bundle) === FALSE) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Determines if this term has active members in it.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to inspect.
   *
   * @return bool
   *   TRUE if the term has members, FALSE otherwise.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  private function doesTermHaveMembers(EntityInterface $entity) {

    /** @var array $sections */
    $sections = $this->getActiveSections($entity);

    if (count($sections) > 0) {
      return TRUE;
    }

    return FALSE;
  }


  /**
   * Inspect the given taxonomy term.
   *
   * This will determine if there are any active users assigned to it.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to inspect.
   *
   * @return array
   *   An array of the users assigned to this section.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  private function getActiveSections(EntityInterface $entity) {
    /** @var \Drupal\workbench_access\UserSectionStorageInterface $sectionStorage */
    $sectionStorage = $this->userSectionStorage;

    $editors = array_reduce($this->entityTypeManager->getStorage('access_scheme')->loadMultiple(),
      function (array $editors, AccessSchemeInterface $scheme) use ($sectionStorage, $entity) {
      $editors += $sectionStorage->getEditors($scheme, $entity->id());
      return $editors;
    }, []);

    return $editors;
  }

  /**
   * Determine if tagged content exists.
   *
   * This method will determine if any entities exist in the system that are
   * tagged with the term.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The Entity to inspect.
   *
   * @return bool
   *   TRUE if content is assigned to this term.
   *   FALSE if content is not assigned to this term.
   *
   */
  private function isAssignedToContent(EntityInterface $entity) {

    $reference_fields = $this->getAllReferenceFields($entity);

    foreach ($reference_fields as $name => $fieldConfig) {
        // Get the entity reference and determine if it's a taxonomy.
        if ($fieldConfig instanceof FieldStorageConfig) {
          $entities = \Drupal::entityQuery($fieldConfig->get('entity_type'))
            ->condition($fieldConfig->get('field_name'), $entity->id())
            ->range(0, 1)
            ->execute();

          if (count($entities) > 0) {
            return TRUE;
          }
        }
    }


    return FALSE;

  }

  private function getAllReferenceFields(EntityInterface $entity) {
    // First, we are going to try to retrieve a cached instance.
    $found_fields = \Drupal::cache()->get('workbench_access_protect');

    if ($found_fields === FALSE) {
      $map = $this->fieldManager->getFieldMap();
      $found_fields = [];
      foreach ($map as $entity_type => $fields) {
        foreach ($fields as $name => $field) {
          if ($field['type'] === 'entity_reference') {
            // Get the entity reference and determine if it's a taxonomy.
            /** @var \Drupal\field\Entity\FieldStorageConfig $fieldConfig */
            $fieldConfig = FieldStorageConfig::loadByName($entity_type, $name);
            if ($fieldConfig !== NULL &&
                $fieldConfig->getSetting('target_type') === $entity->getEntityType()->id()) {
                $found_fields[$name] = $fieldConfig;
              }
            }
          }
        }
      }

    else {
      $found_fields = $found_fields->data;
    }

    \Drupal::cache()
      ->set('workbench_access_protect', $found_fields, \Drupal::time()->getRequestTime() + 60);

    return $found_fields;
  }

  public function isAccessControlled(EntityInterface $entity) {
    $schemes = $this->entityTypeManager->getStorage('access_scheme')->loadMultiple();
    /** @var \Drupal\workbench_access\Entity\AccessScheme $scheme */
    foreach ($schemes as $scheme) {
      foreach ($scheme->getAccessScheme()->getConfiguration() as $config) {
        if (in_array($entity->bundle(), $config)) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

}
