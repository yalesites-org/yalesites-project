<?php

namespace Drupal\Tests\ys_beacon\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Tests the fresh-install default system-instructions seed.
 *
 * Exercises _ys_beacon_seed_default_instructions() directly against the
 * versioned instructions table so the behavior can be verified without
 * standing up the module's full AI/search dependency graph.
 *
 * @group ys_beacon
 */
class SystemInstructionsSeedTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system'];

  /**
   * The active database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Load the install file (the module itself is not enabled here, so its
    // contrib dependencies are not required) and create the versioned table
    // from its hook_schema definition.
    require_once dirname(__DIR__, 3) . '/ys_beacon.install';
    $this->database = $this->container->get('database');
    $schema = ys_beacon_schema()['ys_beacon_system_instructions'];
    $this->database->schema()->createTable('ys_beacon_system_instructions', $schema);
  }

  /**
   * The default instructions are seeded as the active v1 on an empty table.
   */
  public function testSeedsDefaultOnFreshInstall(): void {
    _ys_beacon_seed_default_instructions($this->database);

    $rows = $this->database->select('ys_beacon_system_instructions', 's')
      ->fields('s')
      ->execute()
      ->fetchAll();

    $this->assertCount(1, $rows);
    $this->assertSame(1, (int) $rows[0]->version);
    $this->assertSame(1, (int) $rows[0]->is_active);
    $this->assertStringStartsWith('# YaleSites AI System Instruction', $rows[0]->instructions);
    // The seed must stay under the configured 4000-char instruction cap.
    $this->assertLessThan(4000, mb_strlen($rows[0]->instructions));
  }

  /**
   * The seed is skipped when versions already exist (migration/existing site).
   */
  public function testDoesNotSeedWhenVersionsExist(): void {
    $this->database->insert('ys_beacon_system_instructions')
      ->fields([
        'instructions' => 'Pre-existing instructions',
        'version' => 1,
        'created_by' => 1,
        'created_date' => 1234567890,
        'is_active' => 1,
        'notes' => NULL,
      ])
      ->execute();

    _ys_beacon_seed_default_instructions($this->database);

    $count = (int) $this->database->select('ys_beacon_system_instructions')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertSame(1, $count, 'No default row is added when a version already exists.');

    $instructions = $this->database->select('ys_beacon_system_instructions', 's')
      ->fields('s', ['instructions'])
      ->execute()
      ->fetchField();
    $this->assertSame('Pre-existing instructions', $instructions, 'The existing version is left untouched.');
  }

}
