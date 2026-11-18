<?php

namespace Drupal\Tests\ys_node_access\Traits;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\NodeType;

/**
 * Builds the CAS-protected content type ys_node_access tests are written on.
 *
 * Every behaviour in this module keys off a node bundle that carries the
 * field_login_required boolean, so each test has to create one before it can
 * assert anything. Those copies drift silently: the field name, the entity
 * type and the field's type all have to agree with the module's own hook
 * implementations, and a copy that fell out of step would not fail loudly, it
 * would just stop exercising the gate.
 */
trait CasProtectedNodeTypeTrait {

  /**
   * Creates a node bundle carrying the field_login_required boolean.
   *
   * Mirrors how the field is attached to page/post/event/profile/resource in
   * production. Requires the node and user entity schemas to be installed
   * first; callers do that themselves because what else they need installed
   * differs per test.
   *
   * @param string $bundle
   *   Machine name of the content type to create.
   * @param string $label
   *   Human-readable name of the content type.
   */
  protected function createCasProtectedNodeType(string $bundle = 'protected_type', string $label = 'Protected type'): void {
    NodeType::create(['type' => $bundle, 'name' => $label])->save();
    $field_storage = FieldStorageConfig::create([
      'field_name' => 'field_login_required',
      'entity_type' => 'node',
      'type' => 'boolean',
    ]);
    $field_storage->save();
    FieldConfig::create([
      'field_storage' => $field_storage,
      'bundle' => $bundle,
      'label' => 'CAS Login Required',
    ])->save();
  }

}
