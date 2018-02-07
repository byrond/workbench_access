<?php

namespace Drupal\Tests\workbench_access\Functional;

use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\Tests\BrowserTestBase;
use Drupal\workbench_access\WorkbenchAccessManagerInterface;
use Drupal\Tests\workbench_access\Traits\WorkbenchAccessTestTrait;

/**
 * Tests for the node form.
 *
 * @group workbench_access
 */
class NodeFormMenuTest extends BrowserTestBase {

  use WorkbenchAccessTestTrait;

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'workbench_access',
    'node',
    'menu_ui',
    'link',
    'menu_link_content',
    'options',
    'user',
    'system',
  ];

  /**
   * Tests that the user can see all valid options on the node form.
   */
  public function testNodeForm() {
    // Set up a content type and menu scheme.
    $node_type = $this->createContentType(['type' => 'page']);
    $scheme = $this->setUpMenuScheme(['page'], ['main']);
    $user_storage = \Drupal::service('workbench_access.user_section_storage');
    $role_storage = \Drupal::service('workbench_access.role_section_storage');

    // Set up an editor and log in as them.
    $editor = $this->setUpEditorUser();
    $this->drupalLogin($editor);

    // Set up some roles and menu links for this test.
    $staff_link = MenuLinkContent::create([
      'title' => 'Link 1',
      'link' => [['uri' => 'route:<front>']],
      'menu_name' => 'main',
    ]);
    $staff_link->save();
    $super_staff_link = MenuLinkContent::create([
      'title' => 'Link 2',
      'link' => [['uri' => 'route:<front>']],
      'menu_name' => 'main',
    ]);
    $super_staff_link->save();
    $base_link = MenuLinkContent::create([
      'title' => 'Link 3',
      'link' => [['uri' => 'route:<front>']],
      'menu_name' => 'main',
    ]);
    $base_link->save();

    // Add the user to the base section.
    $user_storage->addUser($scheme, $editor->id(), [$base_link->getPluginId()]);
    $expected = [$editor->id()];
    $existing_users = $user_storage->getEditors($scheme, $base_link->getPluginId());
    $this->assertEquals($expected, array_keys($existing_users));

    $expected = [$base_link->getPluginId()];
    $existing = $user_storage->getUserSections($scheme, $editor->id());
    $this->assertEquals($expected, $existing);

    $staff_rid = $this->createRole([], 'staff');
    $super_staff_rid = $this->createRole([], 'super_staff');
    // Set the role -> menu mapping. But don't add this user.
    $role_storage->addRole($scheme, $staff_rid, [$staff_link->getPluginId()]);
    $role_storage->addRole($scheme, $super_staff_rid, [$super_staff_link->getPluginId()]);

    $this->drupalGet('node/add/page');

    // Check data loading.
    $expected = [$base_link->getPluginId()];
    $existing = $user_storage->getUserSections($scheme, $editor->id());
    $this->assertEquals($expected, $existing);

    // Check form handling.
    $web_assert = $this->assertSession();
    $web_assert->optionExists('menu[menu_parent]', $base_link->label());
    // Assert we can't see the other options yet.
    $web_assert->optionNotExists('menu[menu_parent]', $staff_link->label());
    $web_assert->optionNotExists('menu[menu_parent]', $super_staff_link->label());

    // Add the staff role and check the option exists.
    $editor->addRole($staff_rid);
    $editor->save();
    $user_storage->resetCache($scheme, $editor->id());

    $expected = [
      $base_link->getPluginId(),
      $staff_link->getPluginId(),
    ];
    $existing = $user_storage->getUserSections($scheme, $editor->id());
    $this->assertEquals($expected, $existing);

    $this->drupalGet('node/add/page');
    $web_assert->optionExists('menu[menu_parent]', $base_link->label());
    $web_assert->optionExists('menu[menu_parent]', $staff_link->label());
    $web_assert->optionNotExists('menu[menu_parent]', $super_staff_link->label());

    // Add the super staff role and check both options exist.
    $editor->addRole($super_staff_rid);
    $editor->save();
    $user_storage->resetCache($scheme, $editor->id());

    $expected = [
      $base_link->getPluginId(),
      $staff_link->getPluginId(),
      $super_staff_link->getPluginId(),
    ];
    $existing = $user_storage->getUserSections($scheme, $editor->id());
    $this->assertEquals($expected, $existing);

    $this->drupalGet('node/add/page');
    $web_assert->optionExists('menu[menu_parent]', $base_link->label());
    $web_assert->optionExists('menu[menu_parent]', $staff_link->label());
    $web_assert->optionExists('menu[menu_parent]', $super_staff_link->label());
  }

}
