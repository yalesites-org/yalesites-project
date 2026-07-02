<?php

namespace Drupal\Tests\ys_views_basic\Unit;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_views_basic\Plugin\Field\FieldWidget\PostViewWidget;
use Drupal\ys_views_basic\ViewsBasicManager;

/**
 * Tests PostViewWidget's post-specific form contributions (#1164).
 *
 * @coversDefaultClass \Drupal\ys_views_basic\Plugin\Field\FieldWidget\PostViewWidget
 *
 * @group yalesites
 */
class PostViewWidgetTest extends UnitTestCase {

  /**
   * The mocked views basic manager.
   *
   * @var \Drupal\ys_views_basic\ViewsBasicManager
   */
  protected $manager;

  /**
   * The mocked entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->manager = $this->createMock(ViewsBasicManager::class);

    // customVocabularyLabel() loads the custom_vocab vocabulary.
    $vocabulary = $this->createMock('Drupal\taxonomy\VocabularyInterface');
    $vocabulary->method('label')->willReturn('Custom Vocab');
    $vocab_storage = $this->createMock(EntityStorageInterface::class);
    $vocab_storage->method('load')->with('custom_vocab')->willReturn($vocabulary);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->entityTypeManager->method('getStorage')
      ->with('taxonomy_vocabulary')
      ->willReturn($vocab_storage);
  }

  /**
   * Builds a PostViewWidget bound to the given bundle.
   */
  private function widget(string $bundle): PostViewWidget {
    $field_definition = $this->createMock(FieldDefinitionInterface::class);
    $field_definition->method('getTargetBundle')->willReturn($bundle);
    $widget = new PostViewWidget(
      'post_view_widget',
      [],
      $field_definition,
      [],
      [],
      $this->manager,
      $this->entityTypeManager,
    );
    $widget->setStringTranslation($this->getStringTranslationStub());
    return $widget;
  }

  /**
   * Invokes a protected method on the widget.
   */
  private function invoke(object $object, string $method, array $args = []) {
    $ref = new \ReflectionMethod($object, $method);
    $ref->setAccessible(TRUE);
    return $ref->invokeArgs($object, $args);
  }

  /**
   * The widget reports the post content type.
   *
   * @covers ::getContentType
   */
  public function testGetContentType() {
    $this->assertSame('post', $this->invoke($this->widget('post_card'), 'getContentType'));
  }

  /**
   * The view mode is resolved from the bundle, not a form control.
   *
   * @covers \Drupal\ys_views_basic\Plugin\Field\FieldWidget\ViewsBasicWidgetBase::getViewMode
   */
  public function testViewModeResolvedFromBundle() {
    $this->assertSame('card', $this->invoke($this->widget('post_card'), 'getViewMode'));
    $this->assertSame('list_item', $this->invoke($this->widget('post_list_item'), 'getViewMode'));
    $this->assertSame('condensed', $this->invoke($this->widget('post_condensed'), 'getViewMode'));
  }

  /**
   * Posts add the "Show Year" exposed filter; the shared set is preserved.
   *
   * @covers ::getExposedFilterOptions
   */
  public function testExposedFilterOptionsIncludeYear() {
    $options = $this->invoke($this->widget('post_card'), 'getExposedFilterOptions');
    $this->assertArrayHasKey('show_year_filter', $options, 'Posts expose the year filter.');
    $this->assertArrayHasKey('show_search_filter', $options);
    $this->assertArrayHasKey('show_category_filter', $options);
    $this->assertArrayHasKey('show_audience_filter', $options);
  }

  /**
   * The post eyebrow option is added with no #states gating.
   *
   * @covers ::buildEntitySpecificOptions
   */
  public function testBuildEntitySpecificOptionsAddsEyebrow() {
    $item = (object) ['params' => NULL];
    $items = $this->createMock(FieldItemListInterface::class);
    $items->method('offsetGet')->willReturn($item);

    $form = [];
    $this->invoke($this->widget('post_card'), 'buildEntitySpecificOptions', [&$form, $items, 0]);

    $element = $form['group_user_selection']['entity_and_view_mode']['post_field_options'] ?? NULL;
    $this->assertIsArray($element, 'post_field_options element is added.');
    $this->assertArrayHasKey('show_eyebrow', $element['#options']);
    $this->assertArrayNotHasKey('#states', $element, 'No #states gating on a post-only widget.');
  }

  /**
   * The form is organised into titled, collapsible detail groups (#1317).
   *
   * Verifies the sectioning without moving fields: the container keys are
   * unchanged so the form-selector/stored-JSON contract is preserved.
   *
   * @covers \Drupal\ys_views_basic\Plugin\Field\FieldWidget\ViewsBasicWidgetBase::initSelectionContainers
   */
  public function testFormSectionsAreGroupedDetails() {
    $form = [];
    $this->invoke($this->widget('post_card'), 'initSelectionContainers', [&$form]);
    $groups = $form['group_user_selection'];

    $this->assertSame('details', $groups['entity_and_view_mode']['#type']);
    $this->assertSame('details', $groups['filter_and_sort']['#type']);
    $this->assertSame('details', $groups['options']['#type']);
    $this->assertFalse($groups['options']['#open'], 'Display options collapsed by default.');
    $this->assertTrue($groups['entity_and_view_mode']['#open']);
    // entity_specific stays a plain container so it is invisible when empty.
    $this->assertSame('container', $groups['entity_specific']['#type']);
    $this->assertArrayNotHasKey('#title', $groups['entity_specific']);
  }

  /**
   * The mockup preview panel is added with the content type in context (#1318).
   *
   * @covers \Drupal\ys_views_basic\Plugin\Field\FieldWidget\ViewsBasicWidgetBase::buildPreviewPanel
   */
  public function testPreviewPanel() {
    $form = [];
    $this->invoke($this->widget('post_card'), 'buildPreviewPanel', [&$form]);
    $preview = $form['group_user_selection']['entity_and_view_mode']['preview'] ?? NULL;

    $this->assertIsArray($preview);
    $this->assertSame('inline_template', $preview['#type']);
    $this->assertSame('post', $preview['#context']['content_type']);
    $this->assertSame('card', $preview['#context']['view_mode']);
    // The static template wires up the JS target classes and the no-query note.
    $this->assertStringContainsString('vb-preview', $preview['#template']);
    $this->assertStringContainsString('not a live query', $preview['#template']);
  }

  /**
   * The preview is shown only on the creation form, not when reconfiguring.
   *
   * @covers \Drupal\ys_views_basic\Plugin\Field\FieldWidget\ViewsBasicWidgetBase::isCreateForm
   */
  public function testPreviewScopedToCreateForm() {
    $widget = $this->widget('post_card');

    $newBlock = $this->createMock(EntityInterface::class);
    $newBlock->method('isNew')->willReturn(TRUE);
    $createItems = $this->createMock(FieldItemListInterface::class);
    $createItems->method('getEntity')->willReturn($newBlock);
    $this->assertTrue($this->invoke($widget, 'isCreateForm', [$createItems]), 'Preview shows on the add-block form.');

    $existingBlock = $this->createMock(EntityInterface::class);
    $existingBlock->method('isNew')->willReturn(FALSE);
    $editItems = $this->createMock(FieldItemListInterface::class);
    $editItems->method('getEntity')->willReturn($existingBlock);
    $this->assertFalse($this->invoke($widget, 'isCreateForm', [$editItems]), 'Preview is hidden when reconfiguring an existing block.');
  }

  /**
   * The save path injects post_field_options into the stored params.
   *
   * @covers ::massageEntitySpecificParams
   */
  public function testMassageEntitySpecificParams() {
    $form = [
      'group_user_selection' => [
        'entity_and_view_mode' => [
          'post_field_options' => ['#value' => ['show_eyebrow' => 'show_eyebrow']],
        ],
      ],
    ];
    $param_data = [];
    $form_state = $this->createMock(FormStateInterface::class);
    $this->invoke($this->widget('post_card'), 'massageEntitySpecificParams', [&$param_data, $form, $form_state]);

    $this->assertSame(['show_eyebrow' => 'show_eyebrow'], $param_data['post_field_options']);
  }

}
