<?php

namespace Drupal\Tests\ys_dcn_field\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\ys_dcn_field\Plugin\Field\FieldType\DcnFieldItem;

/**
 * Kernel tests for the DcnFieldItem field type.
 *
 * @coversDefaultClass \Drupal\ys_dcn_field\Plugin\Field\FieldType\DcnFieldItem
 * @group ys_dcn_field
 * @group yalesites
 */
class DcnFieldItemTest extends KernelTestBase {

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
   * Creates an entity_test entity carrying the DCN field.
   */
  protected function createTestEntity(array $values = []) {
    return \Drupal::entityTypeManager()->getStorage('entity_test')->create($values);
  }

  /**
   * @covers ::isEmpty
   */
  public function testIsEmptyWithNoValues() {
    $entity = $this->createTestEntity();
    $this->assertTrue($entity->field_dcn->isEmpty());
  }

  /**
   * @covers ::isEmpty
   */
  public function testIsEmptyWithBothValuesSet() {
    $entity = $this->createTestEntity([
      'field_dcn' => [
        'dcn_type_target_id' => $this->term->id(),
        'dcn_identifier' => '978-0-306-40615-7',
      ],
    ]);
    $this->assertFalse($entity->field_dcn->isEmpty());
  }

  /**
   * @covers ::isEmpty
   */
  public function testIsEmptyWithOnlyTypeSet() {
    $entity = $this->createTestEntity([
      'field_dcn' => [
        'dcn_type_target_id' => $this->term->id(),
      ],
    ]);
    $this->assertFalse($entity->field_dcn->isEmpty());
  }

  /**
   * A lone "0" identifier is not empty, so it survives empty-item filtering.
   *
   * IsEmpty() returning FALSE keeps the item from being dropped by
   * FieldItemList::filterEmptyItems() when the entity is saved.
   *
   * @covers ::isEmpty
   */
  public function testIsEmptyShouldTreatZeroIdentifierAsNotEmpty() {
    $entity = $this->createTestEntity([
      'field_dcn' => [
        'dcn_identifier' => '0',
      ],
    ]);
    $this->assertFalse($entity->field_dcn->isEmpty());
  }

  /**
   * @covers ::getDcnType
   */
  public function testGetDcnTypeReturnsTerm() {
    $entity = $this->createTestEntity([
      'field_dcn' => [
        'dcn_type_target_id' => $this->term->id(),
        'dcn_identifier' => '978-0-306-40615-7',
      ],
    ]);
    $dcn_type = $entity->field_dcn->first()->getDcnType();
    $this->assertInstanceOf('Drupal\taxonomy\TermInterface', $dcn_type);
    $this->assertSame($this->term->id(), $dcn_type->id());
  }

  /**
   * @covers ::getDcnType
   */
  public function testGetDcnTypeReturnsNullWhenUnset() {
    $entity = $this->createTestEntity([
      'field_dcn' => [
        'dcn_identifier' => '978-0-306-40615-7',
      ],
    ]);
    $this->assertNull($entity->field_dcn->first()->getDcnType());
  }

  /**
   * @covers ::propertyDefinitions
   */
  public function testPropertyDefinitions() {
    $field_storage = FieldStorageConfig::loadByName('entity_test', 'field_dcn');
    $properties = DcnFieldItem::propertyDefinitions($field_storage);

    $this->assertSame(['dcn_type_target_id', 'dcn_identifier'], array_keys($properties));
    $this->assertTrue($properties['dcn_type_target_id']->isRequired());
    $this->assertTrue($properties['dcn_identifier']->isRequired());
    $this->assertSame('DCN Type', (string) $properties['dcn_type_target_id']->getLabel());
    $this->assertSame('DCN Identifier', (string) $properties['dcn_identifier']->getLabel());
  }

  /**
   * @covers ::getConstraints
   */
  public function testValidatePassesWhenBothPropertiesSet() {
    $entity = $this->createTestEntity([
      'field_dcn' => [
        'dcn_type_target_id' => $this->term->id(),
        'dcn_identifier' => '978-0-306-40615-7',
      ],
    ]);
    $violations = $entity->field_dcn->validate();
    $this->assertCount(0, $violations);
  }

  /**
   * @covers ::getConstraints
   */
  public function testValidateFailsWhenOnlyIdentifierSet() {
    $entity = $this->createTestEntity([
      'field_dcn' => [
        'dcn_identifier' => '978-0-306-40615-7',
      ],
    ]);
    $violations = $entity->field_dcn->validate();
    $this->assertGreaterThan(0, $violations->count());
  }

  /**
   * @covers ::getConstraints
   */
  public function testValidateFailsWhenOnlyTypeSet() {
    $entity = $this->createTestEntity([
      'field_dcn' => [
        'dcn_type_target_id' => $this->term->id(),
      ],
    ]);
    $violations = $entity->field_dcn->validate();
    $this->assertGreaterThan(0, $violations->count());
  }

  /**
   * @covers ::fieldSettingsForm
   */
  public function testFieldSettingsFormListsVocabularies() {
    $entity = $this->createTestEntity();
    $item = $entity->get('field_dcn')->appendItem();

    $form_state = new FormState();
    $form = $item->fieldSettingsForm([], $form_state);

    $this->assertSame('select', $form['dcn_type_vocabulary']['#type']);
    $this->assertArrayHasKey('dcn_types', $form['dcn_type_vocabulary']['#options']);
    $this->assertSame('DCN Types', $form['dcn_type_vocabulary']['#options']['dcn_types']);
    $this->assertSame('dcn_types', $form['dcn_type_vocabulary']['#default_value']);
  }

}
