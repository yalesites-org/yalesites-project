<?php

namespace Drupal\Tests\ys_beacon\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\ys_beacon\Service\SuspectTurnLog;

/**
 * Tests the update hook that drops the flagged-turn question/answer columns.
 *
 * This is the destructive, irreversible part of removing conversation text from
 * the flagged-turn log: dropping the columns is what deletes the text captured
 * while they existed, so leaving it untested would leave the one step that
 * cannot be undone as the one step nothing checks.
 *
 * Driven against a real schema rather than a mocked one, because the behavior
 * under test IS the schema change - and because the hook must be correct on
 * three different starting states: a table that still has the columns (a
 * multidev or a local checkout that ran update 10017 before the reversal), a
 * table that never had them (a site installed fresh from the current
 * hook_schema()), and no table at all.
 *
 * @group ys_beacon
 */
class BeaconSuspectTurnColumnDropUpdateTest extends KernelTestBase {

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

    // The module itself is not enabled (that would pull in its contrib AI and
    // search dependencies); the install file is loaded directly so the update
    // hook and hook_schema() are callable, matching SuspectTurnLogTest.
    require_once dirname(__DIR__, 3) . '/ys_beacon.install';
    $this->database = $this->container->get('database');
  }

  /**
   * Creates the flagged-turn table in its pre-reversal shape.
   *
   * The field list is spelled out here rather than read from hook_schema(),
   * which no longer describes it - this is the historical shape update 10017
   * produced before the columns were removed, and the fixture has to keep it
   * alive so the hook has something to drop.
   */
  protected function createTableWithTextColumns(): void {
    $schema = ys_beacon_schema()[SuspectTurnLog::TABLE];
    $schema['fields']['question'] = [
      'type' => 'text',
      'not null' => FALSE,
      'description' => 'The question as asked.',
    ];
    $schema['fields']['answer'] = [
      'type' => 'text',
      'not null' => FALSE,
      'description' => 'The answer as served.',
    ];

    $this->database->schema()->createTable(SuspectTurnLog::TABLE, $schema);
  }

  /**
   * The hook drops both text columns, and the text stored in them.
   */
  public function testDropsTheTextColumnsAndTheirContents(): void {
    $this->createTableWithTextColumns();
    $this->database->insert(SuspectTurnLog::TABLE)
      ->fields([
        'created' => 1785000000,
        'pattern' => 'ignore_instructions',
        'question' => 'SECRET-QUESTION',
        'answer' => 'SECRET-ANSWER',
      ])
      ->execute();

    $db_schema = $this->database->schema();
    $this->assertTrue($db_schema->fieldExists(SuspectTurnLog::TABLE, 'question'), 'The fixture really has the column.');

    ys_beacon_update_10018();

    $this->assertFalse($db_schema->fieldExists(SuspectTurnLog::TABLE, 'question'));
    $this->assertFalse($db_schema->fieldExists(SuspectTurnLog::TABLE, 'answer'));

    // The row survives - only the text is gone - so an operator keeps the
    // record that a turn was flagged and when.
    $rows = $this->database->select(SuspectTurnLog::TABLE, 's')
      ->fields('s', ['created', 'pattern'])
      ->execute()
      ->fetchAll();
    $this->assertCount(1, $rows);
    $this->assertSame('ignore_instructions', $rows[0]->pattern);

    // The created index has to survive the drop, because every read, the
    // per-day quota and the retention prune all filter on it. Worth asserting
    // rather than assuming: SQLite has no DROP COLUMN, so Drupal's schema layer
    // rebuilds the whole table from an introspected definition, and a rebuild
    // is exactly where an index goes missing.
    $this->assertTrue($db_schema->indexExists(SuspectTurnLog::TABLE, 'created'));
  }

  /**
   * Running the hook twice is safe, so a re-run cannot fail a deploy.
   */
  public function testIsSafeToRunTwice(): void {
    $this->createTableWithTextColumns();

    ys_beacon_update_10018();
    ys_beacon_update_10018();

    $this->assertFalse($this->database->schema()->fieldExists(SuspectTurnLog::TABLE, 'question'));
  }

  /**
   * A site installed fresh from the current schema is left alone.
   */
  public function testNoOpsWhenTheColumnsWereNeverCreated(): void {
    $this->database->schema()->createTable(
      SuspectTurnLog::TABLE,
      ys_beacon_schema()[SuspectTurnLog::TABLE]
    );

    ys_beacon_update_10018();

    $db_schema = $this->database->schema();
    $this->assertTrue($db_schema->tableExists(SuspectTurnLog::TABLE));
    $this->assertTrue($db_schema->fieldExists(SuspectTurnLog::TABLE, 'pattern'));
  }

  /**
   * A site with no flagged-turn table at all must not error.
   *
   * Ys_beacon is installed on a site by the core.extension diff, and installing
   * a module jumps its schema version straight to the newest update, so no
   * ys_beacon update hook runs there - but a hook that assumes its table exists
   * would still break any site where it does not.
   */
  public function testNoOpsWithoutTheTable(): void {
    $this->assertFalse($this->database->schema()->tableExists(SuspectTurnLog::TABLE));

    ys_beacon_update_10018();

    $this->assertFalse($this->database->schema()->tableExists(SuspectTurnLog::TABLE));
  }

}
