<?php

namespace Drupal\Tests\ys_layouts\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Routing\CurrentRouteMatch;
use Drupal\Core\Url;
use Drupal\Tests\UnitTestCase;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\TermInterface;
use Drupal\taxonomy\VocabularyInterface;
use Drupal\ys_layouts\Plugin\Block\TaxonomyDisplayBlock;
use Drupal\ys_themes\ColorTokenResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests the taxonomy display block.
 *
 * @coversDefaultClass \Drupal\ys_layouts\Plugin\Block\TaxonomyDisplayBlock
 *
 * @group yalesites
 * @group ys_layouts
 */
class TaxonomyDisplayBlockTest extends UnitTestCase {

  /**
   * The entity type manager mock.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityTypeManager;

  /**
   * The current route match mock.
   *
   * @var \Drupal\Core\Routing\CurrentRouteMatch|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $routeMatch;

  /**
   * The request stack mock.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $requestStack;

  /**
   * The color token resolver mock.
   *
   * @var \Drupal\ys_themes\ColorTokenResolver|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $colorTokenResolver;

  /**
   * The block plugin under test.
   *
   * @var \Drupal\ys_layouts\Plugin\Block\TaxonomyDisplayBlock
   */
  protected $block;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->routeMatch = $this->createMock(CurrentRouteMatch::class);
    $this->requestStack = $this->createMock(RequestStack::class);
    $this->colorTokenResolver = $this->createMock(ColorTokenResolver::class);

    $this->block = new TaxonomyDisplayBlock(
      [],
      'ys_taxonomy_display_block',
      ['provider' => 'ys_layouts'],
      $this->entityTypeManager,
      $this->routeMatch,
      $this->requestStack,
      $this->colorTokenResolver
    );
  }

  /**
   * The default configuration has no vocabularies selected.
   *
   * @covers ::defaultConfiguration
   */
  public function testDefaultConfiguration(): void {
    $this->assertSame(
      ['vocabularies' => [], 'theme_selection' => 'default'],
      $this->block->defaultConfiguration()
    );
  }

  /**
   * With no current node, build() renders no items.
   *
   * @covers ::build
   */
  public function testBuildWithNoNodeReturnsNoItems(): void {
    $request = new Request();
    $this->requestStack->method('getCurrentRequest')->willReturn($request);

    $build = $this->block->build();

    $this->assertSame('ys_taxonomy_display_block', $build['#theme']);
    $this->assertSame([], $build['#items']);
  }

  /**
   * Selected vocabulary fields render as linked terms, keyed by field name.
   *
   * @covers ::build
   */
  public function testBuildRendersSelectedVocabularyFields(): void {
    $term = $this->createMock(TermInterface::class);
    $term->method('label')->willReturn('Undergraduate');
    $term->method('toUrl')->willReturn($this->createMock(Url::class));

    $field = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $field->method('getFieldDefinition')->willReturn($this->makeFieldDefinition('Audience'));
    $field->method('referencedEntities')->willReturn([$term]);

    $node = $this->createMock(Node::class);
    $node->method('hasField')->with('field_audience')->willReturn(TRUE);
    $node->method('get')->with('field_audience')->willReturn($field);

    $block = new TaxonomyDisplayBlock(
      ['vocabulary_fields' => ['field_audience' => 'field_audience']],
      'ys_taxonomy_display_block',
      ['provider' => 'ys_layouts'],
      $this->entityTypeManager,
      $this->routeMatch,
      $this->requestStack,
      $this->colorTokenResolver
    );

    $request = new Request();
    $request->attributes->set('node', $node);
    $this->requestStack->method('getCurrentRequest')->willReturn($request);

    $build = $block->build();

    $this->assertArrayHasKey('field_audience', $build['#items']);
    $this->assertSame('Audience', $build['#items']['field_audience']['label']);
    $this->assertSame('Undergraduate', $build['#items']['field_audience']['terms'][0]['#title']);
  }

  /**
   * A node with a matching taxonomy field offers it as a checkbox option.
   *
   * @covers ::blockForm
   */
  public function testBlockFormOffersMatchingVocabularyField(): void {
    $vocabulary = $this->createMock(VocabularyInterface::class);
    $vocabularyStorage = $this->createMock(EntityStorageInterface::class);
    $vocabularyStorage->method('load')->with('audience')->willReturn($vocabulary);
    $this->entityTypeManager->method('getStorage')->with('taxonomy_vocabulary')->willReturn($vocabularyStorage);

    $field = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $field->method('getName')->willReturn('field_audience');
    $field->method('getFieldDefinition')->willReturn(
      $this->makeFieldDefinition('Audience', 'entity_reference', 'taxonomy_term', ['target_bundles' => ['audience']])
    );

    $node = $this->createMock(Node::class);
    $node->method('bundle')->willReturn('page');
    $node->method('getFields')->willReturn(['field_audience' => $field]);

    $request = new Request();
    $request->attributes->set('node', $node);
    $this->requestStack->method('getCurrentRequest')->willReturn($request);
    $this->block->setStringTranslation($this->getStringTranslationStub());

    $form = $this->block->blockForm([], new FormState());

    $this->assertArrayHasKey('vocabulary_fields', $form);
    $this->assertSame(['field_audience' => 'Audience'], $form['vocabulary_fields']['#options']);
  }

  /**
   * A node with no matching taxonomy fields shows a "none found" message.
   *
   * @covers ::blockForm
   */
  public function testBlockFormWithNoVocabularyFieldsShowsMessage(): void {
    $node = $this->createMock(Node::class);
    $node->method('bundle')->willReturn('page');
    $node->method('getFields')->willReturn([]);

    $request = new Request();
    $request->attributes->set('node', $node);
    $this->requestStack->method('getCurrentRequest')->willReturn($request);
    $this->block->setStringTranslation($this->getStringTranslationStub());

    $form = $this->block->blockForm([], new FormState());

    $this->assertArrayHasKey('no_vocabulary_fields', $form);
    $this->assertArrayNotHasKey('vocabulary_fields', $form);
  }

  /**
   * Submitting the form stores the selected vocabularies and theme.
   *
   * @covers ::blockSubmit
   */
  public function testBlockSubmitStoresSelections(): void {
    $form_state = new FormState();
    $form_state->setValue('vocabulary_fields', ['field_audience' => 'field_audience']);
    $form_state->setValue('theme_selection', 'two');

    $this->block->blockSubmit([], $form_state);

    $configuration = $this->block->getConfiguration();
    $this->assertSame(['field_audience' => 'field_audience'], $configuration['vocabulary_fields']);
    $this->assertSame('two', $configuration['theme_selection']);
  }

  /**
   * The color picker process callback maps to the callout color mapping.
   *
   * @covers ::processColorPicker
   */
  public function testProcessColorPickerDelegatesToColorTokenResolver(): void {
    $element = ['#type' => 'select'];
    $form_state = new FormState();
    $complete_form = ['some' => 'form'];

    $this->colorTokenResolver->expects($this->once())
      ->method('processColorPicker')
      ->with($element, $form_state, $complete_form, 'block_content', 'callout')
      ->willReturn($element + ['#processed' => TRUE]);

    $result = $this->block->processColorPicker($element, $form_state, $complete_form);

    $this->assertTrue($result['#processed']);
  }

  /**
   * Builds a mock field definition for taxonomy reference field lookups.
   *
   * @param string $label
   *   The field label.
   * @param string $type
   *   The field type.
   * @param string $targetType
   *   The entity reference target type.
   * @param array $handlerSettings
   *   The handler_settings value.
   *
   * @return \Drupal\Core\Field\FieldDefinitionInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mock field definition.
   */
  protected function makeFieldDefinition(
    string $label,
    string $type = 'entity_reference',
    string $targetType = 'taxonomy_term',
    array $handlerSettings = ['target_bundles' => ['audience']],
  ): FieldDefinitionInterface {
    $definition = $this->createMock(FieldDefinitionInterface::class);
    $definition->method('getLabel')->willReturn($label);
    $definition->method('getType')->willReturn($type);
    $definition->method('getSetting')->willReturnMap([
      ['target_type', $targetType],
      ['handler_settings', $handlerSettings],
    ]);
    return $definition;
  }

}
