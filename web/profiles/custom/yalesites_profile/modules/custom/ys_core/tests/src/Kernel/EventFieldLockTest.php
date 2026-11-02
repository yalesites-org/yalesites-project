<?php

namespace Drupal\Tests\ys_core\Kernel;

use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;

/**
 * Tests the event form locks on fields the Localist import overwrites.
 *
 * @group ys_core
 * @group yalesites
 */
class EventFieldLockTest extends YsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');

    NodeType::create(['type' => 'event', 'name' => 'Event'])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_localist_id',
      'entity_type' => 'node',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_localist_id',
      'entity_type' => 'node',
      'bundle' => 'event',
      'label' => 'Localist ID',
    ])->save();

    // The helper lives in ys_core.module; load it without enabling ys_core,
    // which would pull in a much heavier container.
    require_once __DIR__ . '/../../../ys_core.module';
  }

  /**
   * Builds a fake node form holding the four location widgets.
   */
  protected function alterForm(NodeInterface $node, string $formId = 'node_event_edit_form'): array {
    $form = [
      'field_event_place' => ['widget' => ['#type' => 'select']],
      'field_event_room' => ['widget' => [0 => ['value' => ['#type' => 'textfield']]]],
      'field_stream_url' => ['widget' => [0 => ['uri' => ['#type' => 'url']]]],
      'field_stream_embed_code' => ['widget' => [0 => ['value' => ['#type' => 'textfield']]]],
    ];
    $formObject = $this->createMock(EntityFormInterface::class);
    $formObject->method('getEntity')->willReturn($node);
    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getFormObject')->willReturn($formObject);

    _ys_core_disable_event_fields($form, $formState, $formId);
    return $form;
  }

  /**
   * Yale Location and Room are always locked; stream fields only on Localist.
   */
  public function testFormLocksLocalistFields(): void {
    $always = [
      'field_event_place' => [],
      'field_event_room' => [0, 'value'],
    ];
    $localistOnly = [
      'field_stream_url' => [0, 'uri'],
      'field_stream_embed_code' => [0, 'value'],
    ];

    $form = $this->alterForm(Node::create(['type' => 'event', 'title' => 'x']));
    $this->assertLocked($form, $always);
    foreach (array_keys($localistOnly) as $field) {
      $this->assertArrayNotHasKey('#disabled', $form[$field]);
    }

    $form = $this->alterForm(Node::create([
      'type' => 'event',
      'title' => 'x',
      'field_localist_id' => '12345',
    ]));
    $this->assertLocked($form, $always + $localistOnly);
  }

  /**
   * Asserts each field is disabled with a note on its input element.
   */
  protected function assertLocked(array $form, array $fields): void {
    foreach ($fields as $field => $input) {
      $this->assertTrue($form[$field]['#disabled'], $field);
      $widget = $form[$field]['widget'];
      foreach ($input as $key) {
        $widget = $widget[$key];
      }
      $this->assertNotEmpty($widget['#description'], $field);
    }
  }

  /**
   * Forms for other content types are left alone.
   */
  public function testFormIgnoresOtherForms(): void {
    $form = $this->alterForm(Node::create(['type' => 'event', 'title' => 'x']), 'node_page_edit_form');
    $this->assertArrayNotHasKey('#disabled', $form['field_event_place']);
  }

}
