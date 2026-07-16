<?php

namespace Drupal\Tests\ys_node_access\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_node_access\NodeAccessManager;

/**
 * Tests the constants NodeAccessManager exposes to the rest of the module.
 *
 * NodeAccessManager holds no logic of its own -- it is a shared bag of
 * realm/grant-ID constants used by ys_node_access_node_grants() and
 * ys_node_access_node_access_records() in ys_node_access.module. This test
 * pins those values and the PUBLIC/PRIVATE ordering the grants logic relies
 * on (PUBLIC=0, PRIVATE=1), so an accidental renumbering is caught here
 * rather than surfacing as a silent node-access regression.
 *
 * @coversDefaultClass \Drupal\ys_node_access\NodeAccessManager
 * @group yalesites
 * @group ys_node_access
 */
class NodeAccessManagerTest extends UnitTestCase {

  /**
   * Tests the realm and grant ID constant values.
   *
   * @covers ::YS_NODE_ACCESS_REALM
   * @covers ::YS_NODE_ACCESS_GRANT_ID_PUBLIC
   * @covers ::YS_NODE_ACCESS_GRANT_ID_PRIVATE
   */
  public function testConstants() {
    $this->assertSame('ys_node_access', NodeAccessManager::YS_NODE_ACCESS_REALM);
    $this->assertSame(0, NodeAccessManager::YS_NODE_ACCESS_GRANT_ID_PUBLIC);
    $this->assertSame(1, NodeAccessManager::YS_NODE_ACCESS_GRANT_ID_PRIVATE);
  }

}
