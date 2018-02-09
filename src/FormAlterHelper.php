<?php

namespace Drupal\workbench_access;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a helper for altering content edit forms.
 */
class FormAlterHelper implements ContainerInjectionInterface {

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * Constructs a new FormAlterHelper object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager, AccountInterface $currentUser) {
    $this->entityTypeManager = $entityTypeManager;
    $this->currentUser = $currentUser;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user')
    );
  }

  /**
   * Alters the given form.
   *
   * @param array $element
   *   Element to alter. May be the whole form, or a sub-form.
   * @param array $complete_form
   *   Complete form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Active form state data.
   * @param \Drupal\Core\Entity\EntityInterface
   *   The entity object that the form is modifying.
   *
   * @return array
   *   The altered element.
   */
  public function alterForm(array &$element, array &$complete_form, FormStateInterface &$form_state, ContentEntityInterface $entity) {
    $callback = FALSE;
    if (empty($entity)) {
      $entity = $form_state->getFormObject()->getEntity();
    }
    /** @var \Drupal\workbench_access\Entity\AccessSchemeInterface $access_scheme */
    foreach ($this->entityTypeManager->getStorage('access_scheme')->loadMultiple() as $access_scheme) {
      // If no access field is set, we do nothing.
      $callback = TRUE;
      $scheme = $access_scheme->getAccessScheme();
      if (!$scheme->applies($entity->getEntityTypeId(), $entity->bundle())) {
        continue;
      }
      // Load field data that can be edited.
      // If the user cannot access the form element or is a superuser, ignore.
      if (!$this->currentUser->hasPermission('bypass workbench access')) {
        $scheme->alterForm($access_scheme, $element, $form_state, $entity);
        // Add the options hidden from the user silently to the form.
        $options_diff = $scheme->disallowedOptions($element);
        if (!empty($options_diff)) {
          // @TODO: Potentially show this information to users with permission.
          $complete_form['workbench_access_disallowed'] += [
            '#tree' => TRUE,
            $access_scheme->id() => [
              '#type' => 'value',
              '#value' => $options_diff,
            ],
          ];
        }
      }
    }
    if ($callback) {
      // Call our submitEntity() method to merge in values.
      // @todo Add tests for this.
      $class = AccessControlHierarchyBase::class . '::submitEntity';
      // Account for all the submit buttons on the form.
      $buttons = ['submit', 'publish', 'unpublish'];
      foreach ($buttons as $button) {
        if (isset($complete_form['actions'][$button]['#submit'])) {
          array_unshift($complete_form['actions'][$button]['#submit'], $class);
        }
      }
    }
    return $element;
  }

}
