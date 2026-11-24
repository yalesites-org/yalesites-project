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
  private function widget(string $bundle, array $site = ['font_pairing' => 'yalenew']): PostViewWidget {
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
      $this->getConfigFactoryStub(['ys_core.site' => $site]),
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
   * Posts scope their tag selects to post_category/tags/audience/custom_vocab.
   *
   * @covers \Drupal\ys_views_basic\Plugin\Field\FieldWidget\ViewsBasicWidgetBase::getTagVocabularies
   */
  public function testTagVocabulariesScopedToPost() {
    $this->assertSame(
      ['post_category', 'tags', 'audience', 'custom_vocab'],
      $this->invoke($this->widget('post_card'), 'getTagVocabularies')
    );
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
   * The mockup preview panel is themed with the content type variable (#1318).
   *
   * @covers \Drupal\ys_views_basic\Plugin\Field\FieldWidget\ViewsBasicWidgetBase::buildPreviewPanel
   */
  public function testPreviewPanel() {
    $form = [];
    $this->invoke($this->widget('post_card'), 'buildPreviewPanel', [&$form]);
    $preview = $form['group_user_selection']['entity_and_view_mode']['preview'] ?? NULL;

    $this->assertIsArray($preview);
    // The panel renders via the views_basic_mockup_preview theme hook; the
    // markup lives in the template, so assert the hook and its variables.
    $this->assertSame('views_basic_mockup_preview', $preview['#theme']);
    $this->assertSame('post', $preview['#content_type']);
    $this->assertSame('card', $preview['#view_mode']);
    $this->assertSame(100, $preview['#weight']);
  }

  /**
   * The preview gets the heading font string from the font_pairing mapping.
   *
   * After ys_core_update_10021 font_pairing is a mapping, which Twig cannot
   * print as an attribute value; the panel must pass the heading font only.
   *
   * @covers \Drupal\ys_views_basic\Plugin\Field\FieldWidget\ViewsBasicWidgetBase::buildPreviewPanel
   */
  public function testPreviewPanelReadsHeadingFont() {
    $site = [
      'font_pairing' => [
        'heading_font' => 'mallory',
        'heading_numerals' => 'lining',
        'body_numerals' => 'oldstyle',
      ],
    ];
    $form = [];
    $this->invoke($this->widget('post_card', $site), 'buildPreviewPanel', [&$form]);
    $this->assertSame('mallory', $form['group_user_selection']['entity_and_view_mode']['preview']['#font_pairing']);

    // A site not yet migrated (legacy string) falls back to the default.
    $form = [];
    $this->invoke($this->widget('post_card'), 'buildPreviewPanel', [&$form]);
    $this->assertSame('yalenew', $form['group_user_selection']['entity_and_view_mode']['preview']['#font_pairing']);
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
   * The stylesheet hides the preview in a narrow Layout Builder side panel.
   *
   * A block not yet saved with the page is still new when configured in the
   * side panel, so isNew() alone cannot keep the preview out of it. The hide
   * rule is limited to containers narrower than the 40rem two-column
   * breakpoint, so a widened panel shows it beside the options (#2256).
   */
  public function testPreviewHiddenOnlyInNarrowSidePanel() {
    $css = file_get_contents(dirname(__DIR__, 3) . '/assets/css/views-basic-preview.css');

    $this->assertMatchesRegularExpression(
      '/@container\s*\(width\s*<\s*40rem\)\s*\{\s*#drupal-off-canvas-wrapper\s+\.vb-preview-wrapper\s*\{[^}]*display:\s*none/',
      $css,
      'The preview is hidden in the off-canvas side panel only below the 40rem breakpoint.'
    );

    // Unconditional off-canvas rules may only cap the card's max-width (and
    // restore width: 100% that core's reset drops), never
    // hide the preview or collapse the grid in a wide panel.
    preg_match_all('/^#drupal-off-canvas-wrapper[^{]*\{([^}]*)\}/m', $css, $rules);
    foreach ($rules[1] as $body) {
      $this->assertMatchesRegularExpression('/^\s*max-width:\s*[\d.]+rem\s*!important;(\s*width:\s*100%\s*!important;)?\s*$/', $body, 'Unconditional off-canvas rules only cap the card width and restore width: 100%.');
    }
  }

  /**
   * The side panel keeps the card at its pop-up size (#2256).
   *
   * Core's off-canvas reset forces max-width: inherit !important, so each cap
   * is repeated with !important under a side-panel selector.
   */
  public function testPreviewCardCappedInSidePanel() {
    $css = file_get_contents(dirname(__DIR__, 3) . '/assets/css/views-basic-preview.css');
    $caps = [
      "div.vb-preview {" => '22rem',
      "[data-view-mode='list_item']," => '26rem',
      "[data-view-mode='condensed'] {" => '26rem',
      "[data-view-mode='portrait_grid'] {" => '12rem',
    ];
    foreach ($caps as $needle => $width) {
      $this->assertMatchesRegularExpression(
        '/#drupal-off-canvas-wrapper[^{]*' . preg_quote($needle, '/') . '[^}]*max-width:\s*' . $width . '\s*!important/s',
        $css,
        "Side panel caps $needle at $width."
      );
    }
  }

  /**
   * The save path injects post_field_options into the stored params.
   *
   * @covers ::massageEntitySpecificParams
   */
  public function testMassageEntitySpecificParams() {
    // The flattened entity_and_view_mode elements massageFormValues() built.
    $built = [
      'post_field_options' => ['#value' => ['show_eyebrow' => 'show_eyebrow']],
    ];
    $param_data = [];
    $form_state = $this->createMock(FormStateInterface::class);
    $this->invoke($this->widget('post_card'), 'massageEntitySpecificParams', [&$param_data, $built, [], $form_state]);

    $this->assertSame(['show_eyebrow' => 'show_eyebrow'], $param_data['post_field_options']);
  }

}
