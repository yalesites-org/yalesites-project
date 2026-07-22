<?php

namespace Drupal\Tests\ys_dcn_field\Kernel;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;

/**
 * Kernel tests for the DcnFieldDefaultFormatter field formatter.
 *
 * @coversDefaultClass \Drupal\ys_dcn_field\Plugin\Field\FieldFormatter\DcnFieldDefaultFormatter
 * @group ys_dcn_field
 * @group yalesites
 */
class DcnFieldDefaultFormatterTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'taxonomy',
    'entity_test',
    'ys_dcn_field',
  ];

  /**
   * A DCN type taxonomy term.
   *
   * @var \Drupal\taxonomy\TermInterface
   */
  protected $term;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('taxonomy_term');
    $this->installConfig(['field', 'filter']);

    Vocabulary::create([
      'vid' => 'dcn_types',
      'name' => 'DCN Types',
    ])->save();

    $this->term = Term::create([
      'vid' => 'dcn_types',
      'name' => 'ISBN',
    ]);
    $this->term->save();

    FieldStorageConfig::create([
      'field_name' => 'field_dcn',
      'entity_type' => 'entity_test',
      'type' => 'dcn_field',
    ])->save();

    FieldConfig::create([
      'field_name' => 'field_dcn',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'label' => 'DCN',
    ])->save();
  }

  /**
   * Renders the field_dcn field through the formatter with given settings.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity carrying the field.
   * @param array $settings
   *   Formatter settings ('separator', 'show_label').
   */
  protected function renderField(FieldableEntityInterface $entity, array $settings) {
    $build = $entity->get('field_dcn')->view([
      'type' => 'dcn_field_default',
      'settings' => $settings,
    ]);
    return (string) \Drupal::service('renderer')->renderRoot($build);
  }

  /**
   * @covers ::viewElements
   */
  public function testViewElementsWithLabel() {
    $entity = \Drupal::entityTypeManager()->getStorage('entity_test')->create([
      'field_dcn' => [
        'dcn_type_target_id' => $this->term->id(),
        'dcn_identifier' => '978-0-306-40615-7',
      ],
    ]);
    $output = $this->renderField($entity, ['separator' => ' - ', 'show_label' => TRUE]);
    $this->assertStringContainsString('ISBN - 978-0-306-40615-7', $output);
  }

  /**
   * @covers ::viewElements
   */
  public function testViewElementsWithoutLabel() {
    $entity = \Drupal::entityTypeManager()->getStorage('entity_test')->create([
      'field_dcn' => [
        'dcn_type_target_id' => $this->term->id(),
        'dcn_identifier' => '978-0-306-40615-7',
      ],
    ]);
    $output = $this->renderField($entity, ['separator' => ' - ', 'show_label' => FALSE]);
    $this->assertStringNotContainsString('ISBN', $output);
    $this->assertStringContainsString('978-0-306-40615-7', $output);
  }

  /**
   * @covers ::viewElements
   */
  public function testViewElementsSkipsItemWithoutTerm() {
    $entity = \Drupal::entityTypeManager()->getStorage('entity_test')->create([
      'field_dcn' => [
        'dcn_identifier' => '978-0-306-40615-7',
      ],
    ]);
    $output = $this->renderField($entity, ['separator' => ' - ', 'show_label' => TRUE]);
    $this->assertSame('', trim($output));
  }

  /**
   * A literal "0" identifier renders (not treated as falsy/empty).
   *
   * @covers ::viewElements
   */
  public function testViewElementsShouldRenderZeroIdentifier() {
    $entity = \Drupal::entityTypeManager()->getStorage('entity_test')->create([
      'field_dcn' => [
        'dcn_type_target_id' => $this->term->id(),
        'dcn_identifier' => '0',
      ],
    ]);
    $output = $this->renderField($entity, ['separator' => ' - ', 'show_label' => TRUE]);
    $this->assertStringContainsString('ISBN - 0', $output);
  }

}
