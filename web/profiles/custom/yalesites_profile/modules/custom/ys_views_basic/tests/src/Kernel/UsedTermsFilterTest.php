<?php

namespace Drupal\Tests\ys_views_basic\Kernel;

use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\ys_core\Kernel\YsKernelTestBase;
use Drupal\ys_views_basic\Service\ExposedTaxonomyFilterOptions;

/**
 * Tests the "only terms in use" lookup behind migrated filters (#1967).
 *
 * The predecessor Post Feed, Calendar List and Directory Views listed only
 * terms some content used. Migrated listings keep that through
 * ExposedTaxonomyFilterOptions::usedTermIds(), which reads taxonomy_index
 * and the publish and event dates the listing query filters on.
 *
 * @group yalesites
 */
class UsedTermsFilterTest extends YsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'taxonomy',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    // Also creates taxonomy_index (TermStorageSchema).
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('node', ['node_access']);
    // The two date columns the lookup reads, without the field storages.
    $schema = $this->container->get('database')->schema();
    $schema->createTable('node__field_publish_date', [
      'fields' => [
        'entity_id' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
        'field_publish_date_value' => ['type' => 'varchar', 'length' => 20],
      ],
    ]);
    $schema->createTable('node__field_event_date', [
      'fields' => [
        'entity_id' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
        'field_event_date_end_value' => ['type' => 'int', 'size' => 'big'],
      ],
    ]);
    foreach (['post', 'profile', 'event'] as $type) {
      NodeType::create(['type' => $type, 'name' => $type])->save();
    }
    foreach (['post_category', 'affiliation'] as $vid) {
      Vocabulary::create(['vid' => $vid, 'name' => $vid])->save();
    }
  }

  /**
   * Creates a term and returns its id.
   */
  private function term(string $vid, string $name): int {
    $term = Term::create(['vid' => $vid, 'name' => $name]);
    $term->save();
    return (int) $term->id();
  }

  /**
   * Creates a node of a type and indexes it against the given terms.
   */
  private function tag(string $type, array $tids, array $dates = []): void {
    $node = Node::create(['type' => $type, 'title' => $type]);
    $node->save();
    $database = $this->container->get('database');
    foreach ($dates as $table => $value) {
      $column = $table === 'node__field_publish_date' ? 'field_publish_date_value' : 'field_event_date_end_value';
      $database->insert($table)->fields(['entity_id' => $node->id(), $column => $value])->execute();
    }
    foreach ($tids as $tid) {
      $this->container->get('database')->insert('taxonomy_index')->fields([
        'nid' => $node->id(),
        'tid' => $tid,
        'status' => 1,
        'sticky' => 0,
        'created' => 0,
      ])->execute();
    }
  }

  /**
   * Only the candidate terms used by the given node type come back.
   */
  public function testUsedTermIds(): void {
    $used = $this->term('post_category', 'Resources');
    $unused = $this->term('post_category', 'Staff');
    $other_type = $this->term('post_category', 'Profile only');
    $other_vocab = $this->term('affiliation', 'End User Support');
    $today = ['node__field_publish_date' => date('Y-m-d')];
    $this->tag('post', [$used, $other_vocab], $today);
    $this->tag('post', [$used], $today);
    $this->tag('profile', [$other_type, $other_vocab]);
    // A post dated in the future is not on the listing yet.
    $this->tag('post', [$unused], ['node__field_publish_date' => date('Y-m-d', strtotime('+1 week'))]);

    $service = new ExposedTaxonomyFilterOptions(
      $this->container->get('entity_type.manager'),
      $this->container->get('logger.factory')->get('ys_views_basic'),
      $this->container->get('database'),
    );

    $categories = [$used => $used, $unused => $unused, $other_type => $other_type];
    $this->assertSame([$used => $used], $service->usedTermIds($categories, ['post']));
    $this->assertSame([$other_type => $other_type], $service->usedTermIds($categories, ['profile']));
    $this->assertSame([$other_vocab => $other_vocab], $service->usedTermIds([$other_vocab => $other_vocab], ['profile']));
    // Like PostPublishDate, only a post-only listing skips future posts.
    $all_three = [$used => $used, $unused => $unused, $other_type => $other_type];
    $this->assertSame($all_three, $service->usedTermIds($categories, ['post', 'profile']));
    $this->assertSame([], $service->usedTermIds([], ['post']));
  }

  /**
   * An event listing offers only types used in its time period.
   */
  public function testUsedTermIdsHonoursEventTimePeriod(): void {
    $upcoming = $this->term('post_category', 'Talks');
    $past = $this->term('post_category', 'Exhibitions');
    $this->tag('event', [$upcoming], ['node__field_event_date' => time() + 86400]);
    $this->tag('event', [$past], ['node__field_event_date' => time() - 86400]);
    $service = new ExposedTaxonomyFilterOptions(
      $this->container->get('entity_type.manager'),
      $this->container->get('logger.factory')->get('ys_views_basic'),
      $this->container->get('database'),
    );
    $both = [$upcoming => $upcoming, $past => $past];

    $this->assertSame([$upcoming => $upcoming], $service->usedTermIds($both, ['event'], 'future'));
    $this->assertSame([$past => $past], $service->usedTermIds($both, ['event'], 'past'));
    $this->assertSame($both, $service->usedTermIds($both, ['event'], 'all'));
  }

}
