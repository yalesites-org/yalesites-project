<?php

namespace Drupal\Tests\ys_dcn_field\Kernel;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormState;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;

/**
 * Kernel tests for the DcnFieldDefaultWidget field widget.
 *
 * @coversDefaultClass \Drupal\ys_dcn_field\Plugin\Field\FieldWidget\DcnFieldDefaultWidget
 * @group ys_dcn_field
 * @group yalesites
 */
class DcnFieldDefaultWidgetTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * The module ships no config schema for its field settings, so strict schema
   * checking would fail on every field save. It is disabled here to exercise
   * real field behavior; the missing schema is logged as a GAP.
   */
  // phpcs:ignore DrupalPractice.Objects.StrictSchemaDisabled.StrictConfigSchema
  protected $strictConfigSchema = FALSE;

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
   * Instantiates the widget through the container and builds its element.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   The field items to build the widget for.
   * @param bool $required
   *   The value to pass as the incoming element's '#required' key, mirroring
   *   what WidgetBase::formSingleElement() sets before calling formElement().
   */
  protected function buildFormElement(FieldItemListInterface $items, $required = TRUE) {
    $widget = \Drupal::service('plugin.manager.field.widget')->createInstance('dcn_field_default', [
      'field_definition' => $items->getFieldDefinition(),
      'settings' => [],
      'third_party_settings' => [],
    ]);

    $form = [];
    $form_state = new FormState();
    $element = ['#required' => $required];

    return $widget->formElement($items, 0, $element, $form, $form_state);
  }

  /**
   * @covers ::formElement
   */
  public function testFormElementStructure() {
    $entity = \Drupal::entityTypeManager()->getStorage('entity_test')->create();
    $element = $this->buildFormElement($entity->get('field_dcn'));

    $this->assertSame('container', $element['#type']);
    $this->assertContains('dcn-field-widget-inline', $element['#attributes']['class']);
    $this->assertContains('ys_dcn_field/dcn_field_widget', $element['#attached']['library']);

    $this->assertSame('select', $element['dcn_type_target_id']['#type']);
    $this->assertArrayHasKey($this->term->id(), $element['dcn_type_target_id']['#options']);
    $this->assertSame('ISBN', $element['dcn_type_target_id']['#options'][$this->term->id()]);
    $this->assertTrue($element['dcn_type_target_id']['#required']);

    $this->assertSame('textfield', $element['dcn_identifier']['#type']);
    $this->assertSame(255, $element['dcn_identifier']['#maxlength']);
    $this->assertTrue($element['dcn_identifier']['#required']);
  }

  /**
   * @covers ::formElement
   */
  public function testFormElementDefaultValuesFromExistingItem() {
    $entity = \Drupal::entityTypeManager()->getStorage('entity_test')->create([
      'field_dcn' => [
        'dcn_type_target_id' => $this->term->id(),
        'dcn_identifier' => '978-0-306-40615-7',
      ],
    ]);
    $element = $this->buildFormElement($entity->get('field_dcn'));

    $this->assertSame($this->term->id(), $element['dcn_type_target_id']['#default_value']);
    $this->assertSame('978-0-306-40615-7', $element['dcn_identifier']['#default_value']);
  }

  /**
   * @covers ::formElement
   */
  public function testFormElementNotRequiredWhenElementSaysSo() {
    $entity = \Drupal::entityTypeManager()->getStorage('entity_test')->create();
    $element = $this->buildFormElement($entity->get('field_dcn'), FALSE);

    $this->assertFalse($element['dcn_type_target_id']['#required']);
    $this->assertFalse($element['dcn_identifier']['#required']);
  }

}
