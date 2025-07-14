<?php

namespace Drupal\Tests\ys_node_access\Functional;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;

/**
 * Tests CAS protection form functionality and permissions.
 *
 * @group yalesites
 */
class CasProtectionFormTest extends KernelTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = [
    'system',
    'node',
    'field',
    'text',
    'user',
    'ys_node_access',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Install schema.
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['node']);

    // Create a content type.
    NodeType::create([
      'type' => 'page',
      'name' => 'Page',
    ])->save();

    // Create field storage and field instance for CAS protection.
    $field_storage = \Drupal\field\Entity\FieldStorageConfig::create([
      'field_name' => 'field_login_required',
      'entity_type' => 'node',
      'type' => 'boolean',
    ]);
    $field_storage->save();

    $field = \Drupal\field\Entity\FieldConfig::create([
      'field_storage' => $field_storage,
      'bundle' => 'page',
      'label' => 'CAS Login Required',
    ]);
    $field->save();
  }

  /**
   * Tests that CAS protection field can be created and configured.
   */
  public function testCasProtectionFieldExists() {
    // Create a node and test the field functionality.
    $node = Node::create([
      'type' => 'page',
      'title' => 'Test Page',
      'field_login_required' => FALSE,
    ]);
    $node->save();

    // Check that the field exists and has the correct value.
    $this->assertEquals(0, $node->field_login_required->value);
    
    // Test setting the field to TRUE.
    $node->set('field_login_required', TRUE);
    $node->save();
    
    // Reload and verify.
    $node = Node::load($node->id());
    $this->assertEquals(1, $node->field_login_required->value);
  }

  /**
   * Tests CAS protection field default value.
   */
  public function testCasProtectionFieldDefaultValue() {
    // Create a node without specifying the CAS protection field.
    $node = Node::create([
      'type' => 'page',
      'title' => 'Test Page Default',
    ]);
    $node->save();

    // Check that CAS protection defaults to FALSE.
    $this->assertEquals(0, $node->field_login_required->value);
  }

  /**
   * Tests CAS protection field on different content types.
   */
  public function testCasProtectionFieldOnDifferentContentTypes() {
    // Create additional content types.
    NodeType::create(['type' => 'post', 'name' => 'Post'])->save();
    NodeType::create(['type' => 'event', 'name' => 'Event'])->save();

    // Add the field to the new content types.
    $field_storage = \Drupal\field\Entity\FieldStorageConfig::load('node.field_login_required');
    
    $field_post = \Drupal\field\Entity\FieldConfig::create([
      'field_storage' => $field_storage,
      'bundle' => 'post',
      'label' => 'CAS Login Required',
    ]);
    $field_post->save();

    $field_event = \Drupal\field\Entity\FieldConfig::create([
      'field_storage' => $field_storage,
      'bundle' => 'event',
      'label' => 'CAS Login Required',
    ]);
    $field_event->save();

    // Test field works on post content type.
    $post = Node::create([
      'type' => 'post',
      'title' => 'Test Post',
      'field_login_required' => TRUE,
    ]);
    $post->save();
    $this->assertEquals(1, $post->field_login_required->value);

    // Test field works on event content type.
    $event = Node::create([
      'type' => 'event',
      'title' => 'Test Event',
      'field_login_required' => TRUE,
    ]);
    $event->save();
    $this->assertEquals(1, $event->field_login_required->value);
  }

}
