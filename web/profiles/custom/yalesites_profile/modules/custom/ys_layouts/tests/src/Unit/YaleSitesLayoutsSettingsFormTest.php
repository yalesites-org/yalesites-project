<?php

namespace Drupal\Tests\ys_layouts\Unit;

use Drupal\Core\Form\FormState;
use Drupal\Core\Render\Renderer;
use Drupal\Core\Url;
use Drupal\Core\Utility\LinkGeneratorInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\node\NodeInterface;
use Drupal\node\NodeTypeInterface;
use Drupal\ys_layouts\Form\YaleSitesLayoutsSettingsForm;
use Drupal\ys_layouts\Service\LayoutUpdater;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tests the YaleSites Layouts settings form.
 *
 * @coversDefaultClass \Drupal\ys_layouts\Form\YaleSitesLayoutsSettingsForm
 *
 * @group yalesites
 * @group ys_layouts
 */
class YaleSitesLayoutsSettingsFormTest extends UnitTestCase {

  /**
   * The layout updater mock.
   *
   * @var \Drupal\ys_layouts\Service\LayoutUpdater|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $layoutUpdater;

  /**
   * The renderer mock.
   *
   * @var \Drupal\Core\Render\Renderer|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $renderer;

  /**
   * The form under test.
   *
   * @var \Drupal\ys_layouts\Form\YaleSitesLayoutsSettingsForm
   */
  protected $form;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->layoutUpdater = $this->createMock(LayoutUpdater::class);
    $this->renderer = $this->createMock(Renderer::class);

    $this->form = new YaleSitesLayoutsSettingsForm($this->layoutUpdater, $this->renderer);
    $this->form->setStringTranslation($this->getStringTranslationStub());
  }

  /**
   * The form ID matches the machine name used to build the route.
   *
   * @covers ::getFormId
   */
  public function testGetFormId(): void {
    $this->assertSame('ys_layouts_settings_form', $this->form->getFormId());
  }

  /**
   * The content type table has one row per content type with counts and locks.
   *
   * @covers ::buildForm
   */
  public function testBuildFormListsContentTypesWithCountsAndLocks(): void {
    $pageType = $this->createMock(NodeTypeInterface::class);
    $pageType->method('label')->willReturn('Page');
    $pageType->method('id')->willReturn('page');

    $this->layoutUpdater->method('getContentTypes')->willReturn(['page' => $pageType]);
    $this->layoutUpdater->method('getAllNodeIds')->with('page')->willReturn([1, 2, 3]);
    $this->layoutUpdater->method('getLockConfigs')->with('page')->willReturn(['ys_layout_banner' => [5 => 5]]);
    $this->layoutUpdater->method('getBlockTypes')->willReturn(['' => 'Select']);
    $this->layoutUpdater->method('getTempStoreNodes')->willReturn([]);

    $form = $this->form->buildForm([], new FormState());

    $rows = $form['content']['content_types_table']['#rows'];
    $this->assertCount(1, $rows);
    $this->assertSame(['Page', 3, 'ys_layout_banner'], $rows[0]);
  }

  /**
   * Nodes stuck in the temp store table are listed with a view link.
   *
   * @covers ::buildForm
   */
  public function testBuildFormListsTempStoreNodes(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn(42);
    $node->method('getTitle')->willReturn('Draft page');
    $node->method('toUrl')->willReturn($this->createMock(Url::class));

    $this->layoutUpdater->method('getContentTypes')->willReturn([]);
    $this->layoutUpdater->method('getBlockTypes')->willReturn(['' => 'Select']);
    $this->layoutUpdater->method('getTempStoreNodes')->willReturn([$node]);

    $linkGenerator = $this->createMock(LinkGeneratorInterface::class);
    $linkGenerator->method('generate')->willReturn('<a href="#">View</a>');
    $container = new ContainerBuilder();
    $container->set('link_generator', $linkGenerator);
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);

    $form = $this->form->buildForm([], new FormState());

    $rows = $form['tempstore']['node_table']['#rows'];
    $this->assertCount(1, $rows);
    $this->assertSame(42, $rows[0]['id']);
    $this->assertSame('Draft page', $rows[0]['title']);
  }

  /**
   * The node count for a bundle passes through from the layout updater.
   *
   * @covers ::getNodeCount
   */
  public function testGetNodeCount(): void {
    $this->layoutUpdater->method('getAllNodeIds')->with('event')->willReturn([1, 2]);

    $this->assertSame(2, $this->form->getNodeCount('event'));
  }

  /**
   * Submitting the locks action delegates to the layout updater.
   *
   * @covers ::submitUpdateLocks
   */
  public function testSubmitUpdateLocksCallsUpdateAllLocks(): void {
    $this->layoutUpdater->expects($this->once())->method('updateAllLocks');

    $this->form->submitUpdateLocks();
  }

  /**
   * Submitting the text format action delegates to the layout updater.
   *
   * @covers ::submitUpdateTextFormats
   */
  public function testSubmitUpdateTextFormatsDelegatesWithFormValues(): void {
    $form_state = new FormState();
    $form_state->setValue('block_type', 'callout');
    $form_state->setValue('field_name', 'field_body');

    $this->layoutUpdater->expects($this->once())
      ->method('updateTextFormats')
      ->with('callout', 'field_body');

    $form = [];
    $this->form->submitUpdateTextFormats($form, $form_state);
  }

  /**
   * The ajax callback replaces the field name select with new options.
   *
   * @covers ::updateBlockFields
   */
  public function testUpdateBlockFieldsReplacesFieldNameSelect(): void {
    $form_state = new FormState();
    $form_state->setValue('block_type', 'callout');
    $this->layoutUpdater->method('getTextBlockFields')->with('callout')->willReturn(['field_body' => 'Body']);
    $this->renderer->method('render')->willReturn('<select></select>');

    $form = ['update_text_formats' => ['field_name' => ['#type' => 'select', '#options' => []]]];
    $response = $this->form->updateBlockFields($form, $form_state);

    $this->assertInstanceOf('Drupal\Core\Ajax\AjaxResponse', $response);
  }

  /**
   * With no fields for the block type, the ajax callback adds no commands.
   *
   * @covers ::updateBlockFields
   */
  public function testUpdateBlockFieldsWithNoFieldsAddsNoCommands(): void {
    $form_state = new FormState();
    $form_state->setValue('block_type', '');
    $this->layoutUpdater->method('getTextBlockFields')->willReturn([]);
    $this->renderer->expects($this->never())->method('render');

    $form = ['update_text_formats' => ['field_name' => ['#type' => 'select', '#options' => []]]];
    $response = $this->form->updateBlockFields($form, $form_state);

    $this->assertInstanceOf('Drupal\Core\Ajax\AjaxResponse', $response);
  }

}
