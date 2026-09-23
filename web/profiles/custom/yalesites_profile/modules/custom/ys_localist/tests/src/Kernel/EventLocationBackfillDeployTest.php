<?php

namespace Drupal\Tests\ys_localist\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\ys_core\Kernel\YsKernelTestBase;

/**
 * Tests the backfill of native location fields on hand-authored events.
 *
 * The field_event_place and field_event_room fields are now Localist-owned and
 * only render on Localist events, so a hand-authored event that used them
 * would lose its location. ys_localist_deploy_10001() copies them into the
 * native field_event_address and field_address_additional_info fields.
 *
 * ys_localist itself is not installed: the hook only needs the fields, and
 * the module's migrate dependencies add nothing here.
 *
 * @group ys_localist
 */
class EventLocationBackfillDeployTest extends YsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'text',
    'filter',
    'taxonomy',
    'address',
  ];

  /**
   * The event_place term used by the fixtures.
   *
   * @var \Drupal\taxonomy\TermInterface
   */
  protected $place;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('node', ['node_access']);
    require_once dirname(__DIR__, 3) . '/ys_localist.deploy.php';

    NodeType::create(['type' => 'event', 'name' => 'Event'])->save();
    Vocabulary::create(['vid' => 'event_place', 'name' => 'Event place'])->save();

    $this->addField('taxonomy_term', 'event_place', 'field_address', 'address');
    $this->addField('node', 'event', 'field_localist_id', 'string');
    $this->addField('node', 'event', 'field_event_room', 'string');
    $this->addField('node', 'event', 'field_event_address', 'address');
    $this->addField('node', 'event', 'field_address_additional_info', 'text_long');
    $this->addField('node', 'event', 'field_event_place', 'entity_reference', ['target_type' => 'taxonomy_term']);

    $this->place = Term::create([
      'vid' => 'event_place',
      'name' => 'Sterling & Co',
      'field_address' => $this->address('120 High St'),
    ]);
    $this->place->save();
  }

  /**
   * Creates a field storage and instance.
   */
  protected function addField(string $entity_type, string $bundle, string $name, string $type, array $settings = []): void {
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => $entity_type,
      'type' => $type,
      'settings' => $settings,
    ])->save();
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => $entity_type,
      'bundle' => $bundle,
    ])->save();
  }

  /**
   * A US address value.
   */
  protected function address(string $line1): array {
    return [
      'country_code' => 'US',
      'address_line1' => $line1,
      'locality' => 'New Haven',
      'administrative_area' => 'CT',
      'postal_code' => '06511',
    ];
  }

  /**
   * Creates an event node.
   */
  protected function event(array $values): NodeInterface {
    $node = Node::create($values + ['type' => 'event', 'title' => 'Event']);
    $node->save();
    return $node;
  }

  /**
   * Runs the deploy hook to completion.
   */
  protected function runHook(): string {
    $sandbox = [];
    do {
      $result = ys_localist_deploy_10001($sandbox);
    } while (($sandbox['#finished'] ?? 1) < 1);
    return (string) $result;
  }

  /**
   * Reloads a node's default revision.
   */
  protected function reload(NodeInterface $node): NodeInterface {
    return \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($node->id());
  }

  /**
   * Place and room are copied onto a hand-authored event, drafts included.
   */
  public function testCopiesPlaceAndRoom(): void {
    $event = $this->event([
      'field_event_place' => $this->place->id(),
      'field_event_room' => 'Room <101>',
    ]);
    // A forward draft keeps the value when the editor later publishes it.
    $event->setNewRevision(TRUE);
    $event->isDefaultRevision(FALSE);
    $event->setTitle('Draft');
    $event->save();

    $this->runHook();

    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $latest = $storage->loadRevision($storage->getLatestRevisionId($event->id()));
    foreach ([$this->reload($event), $latest] as $revision) {
      $this->assertSame('120 High St', $revision->get('field_event_address')->address_line1);
      $this->assertSame('New Haven', $revision->get('field_event_address')->locality);
      $this->assertSame('<p>Sterling &amp; Co, Room &lt;101&gt;</p>', $revision->get('field_address_additional_info')->value);
      $this->assertSame('basic_html', $revision->get('field_address_additional_info')->format);
    }
    $this->assertSame('Draft', $latest->getTitle(), 'The draft stays a separate revision.');
  }

  /**
   * A room alone becomes additional information and no address is invented.
   */
  public function testRoomOnly(): void {
    $event = $this->event(['field_event_room' => 'Room 5']);
    $this->runHook();
    $event = $this->reload($event);
    $this->assertTrue($event->get('field_event_address')->isEmpty());
    $this->assertSame('<p>Room 5</p>', $event->get('field_address_additional_info')->value);
  }

  /**
   * Values an editor already entered are never overwritten.
   */
  public function testDoesNotOverwrite(): void {
    $event = $this->event([
      'field_event_place' => $this->place->id(),
      'field_event_room' => 'Room 101',
      'field_event_address' => $this->address('1 Existing Rd'),
      'field_address_additional_info' => ['value' => '<p>Mine</p>', 'format' => 'basic_html'],
    ]);
    $this->runHook();
    $event = $this->reload($event);
    $this->assertSame('1 Existing Rd', $event->get('field_event_address')->address_line1);
    $this->assertSame('<p>Mine</p>', $event->get('field_address_additional_info')->value);
  }

  /**
   * Localist events keep rendering their synced place and are left alone.
   */
  public function testSkipsLocalistEvents(): void {
    $event = $this->event([
      'field_localist_id' => '12345',
      'field_event_place' => $this->place->id(),
      'field_event_room' => 'Room 101',
    ]);
    $this->runHook();
    $event = $this->reload($event);
    $this->assertTrue($event->get('field_event_address')->isEmpty());
    $this->assertTrue($event->get('field_address_additional_info')->isEmpty());
  }

  /**
   * A second run changes nothing.
   */
  public function testIdempotent(): void {
    $this->event(['field_event_room' => 'Room 5']);
    $this->assertStringContainsString('1 event', $this->runHook());
    $this->assertSame('No hand-authored events needed a location backfill.', $this->runHook());
  }

  /**
   * A site without the native fields is skipped.
   */
  public function testSkipsWithoutNativeFields(): void {
    FieldConfig::loadByName('node', 'event', 'field_address_additional_info')->delete();
    $this->assertSame('Events have no native location fields; skipping.', $this->runHook());
  }

}
