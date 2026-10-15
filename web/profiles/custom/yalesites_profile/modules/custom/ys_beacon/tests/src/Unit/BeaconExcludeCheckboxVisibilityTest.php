<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormState;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tests which ys_beacon metatag fields the entity form shows.
 *
 * The exclude checkbox must stay on node forms while markdown output is on,
 * even with the AI metadata toggle off, so editors can keep a page out of .md
 * and /llms.txt.
 *
 * @group ys_beacon
 */
class BeaconExcludeCheckboxVisibilityTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 3) . '/ys_beacon.module';
  }

  /**
   * Runs the widget alter hook and returns the resulting form.
   *
   * @param string $entity_type
   *   The entity type id being edited.
   * @param bool $metadata_fields
   *   The ys_beacon.settings:enable_metadata_fields value.
   * @param bool|null $markdown
   *   The ys_core.site markdown toggle, or NULL for a missing key.
   */
  private function alter(string $entity_type, bool $metadata_fields, ?bool $markdown): array {
    $factory = $this->getConfigFactoryStub([
      'ys_beacon.settings' => ['enable_metadata_fields' => $metadata_fields],
      'ys_core.site' => $markdown === NULL ? [] : ['ai_readability.markdown_enabled' => $markdown],
    ]);
    $container = new ContainerBuilder();
    $container->set('config.factory', $factory);
    \Drupal::setContainer($container);

    $entity = $this->createMock(EntityInterface::class);
    $entity->method('getEntityTypeId')->willReturn($entity_type);
    $entity->method('isNew')->willReturn(FALSE);
    $form_object = $this->createMock(EntityForm::class);
    $form_object->method('getEntity')->willReturn($entity);
    $form_state = new FormState();
    $form_state->setFormObject($form_object);

    $form = [
      'ys_beacon' => [
        '#type' => 'details',
        '#title' => 'AI Metadata',
        'ai_description' => ['#type' => 'textarea'],
        'ai_tags' => ['#type' => 'textfield'],
        'ai_disable_indexing' => ['#type' => 'radios'],
      ],
    ];
    ys_beacon_field_widget_single_element_metatag_firehose_form_alter($form, $form_state, []);
    return $form;
  }

  /**
   * Metadata fields on: the whole group stays.
   */
  public function testMetadataOnShowsWholeGroup(): void {
    $form = $this->alter('node', TRUE, FALSE);
    $this->assertArrayHasKey('ai_description', $form['ys_beacon']);
    $this->assertArrayHasKey('ai_tags', $form['ys_beacon']);
    $this->assertArrayHasKey('ai_disable_indexing', $form['ys_beacon']);
  }

  /**
   * Metadata off, markdown on, node: only the exclude checkbox remains.
   */
  public function testMetadataOffMarkdownOnKeepsOnlyExcludeOnNode(): void {
    foreach ([TRUE, NULL] as $markdown) {
      $form = $this->alter('node', FALSE, $markdown);
      $this->assertSame(['#type', '#title', 'ai_disable_indexing'], array_keys($form['ys_beacon']));
    }
  }

  /**
   * Metadata off, markdown on, media: markdown is node-only, group removed.
   */
  public function testMetadataOffMarkdownOnRemovesGroupOnMedia(): void {
    $this->assertArrayNotHasKey('ys_beacon', $this->alter('media', FALSE, TRUE));
  }

  /**
   * Metadata off and markdown off: the whole group is removed.
   */
  public function testMetadataOffMarkdownOffRemovesGroup(): void {
    $this->assertArrayNotHasKey('ys_beacon', $this->alter('node', FALSE, FALSE));
  }

}
