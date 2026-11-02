<?php

namespace Drupal\Tests\ys_core\Unit;

use Drupal\Core\Entity\Display\EntityFormDisplayInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\WidgetInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Tests\UnitTestCase;
use Drupal\paragraphs\ParagraphInterface;
use Drupal\ys_core\Plugin\Field\FieldWidget\HeadingLinksWidget;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Tests that HeadingLinksWidget stays free of storage side effects.
 *
 * The widget's massageFormValues() runs during validation, AJAX rebuilds,
 * and Layout Builder block validation, before the host is saved (or when it
 * never is). A delete or save there would change what the live revision shows
 * even when the editor cancels, so these tests pin that no paragraph is
 * ever saved or deleted from the widget, and that the link widget comes
 * from the cta_link form display rather than hardcoded settings.
 *
 * @coversDefaultClass \Drupal\ys_core\Plugin\Field\FieldWidget\HeadingLinksWidget
 * @group ys_core
 */
class HeadingLinksWidgetTest extends UnitTestCase {

  /**
   * The mocked paragraph storage.
   *
   * @var \Drupal\Core\Entity\RevisionableStorageInterface&\PHPUnit\Framework\MockObject\MockObject
   */
  protected RevisionableStorageInterface&MockObject $storage;

  /**
   * The mocked form display the widget collects for cta_link.
   *
   * @var \Drupal\Core\Entity\Display\EntityFormDisplayInterface&\PHPUnit\Framework\MockObject\MockObject
   */
  protected EntityFormDisplayInterface&MockObject $display;

  /**
   * The widget under test.
   *
   * @var \Drupal\Tests\ys_core\Unit\TestHeadingLinksWidget
   */
  protected TestHeadingLinksWidget $widget;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->storage = $this->createMock(RevisionableStorageInterface::class);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('paragraph')->willReturn($this->storage);

    $field_definition = $this->createMock(FieldDefinitionInterface::class);
    $field_definition->method('getName')->willReturn('field_heading_links');
    $field_definition->method('getSetting')->willReturnMap([
      ['handler_settings', ['target_bundles' => ['cta_link' => 'cta_link']]],
    ]);

    $this->display = $this->createMock(EntityFormDisplayInterface::class);

    $this->widget = new TestHeadingLinksWidget('heading_links_inline', [], $field_definition, [], [], $entity_type_manager);
    $this->widget->display = $this->display;
  }

  /**
   * Builds a paragraph mock that fails the test if saved or deleted.
   */
  protected function paragraphThatMustNotBeWritten(): ParagraphInterface&MockObject {
    $paragraph = $this->createMock(ParagraphInterface::class);
    $paragraph->expects($this->never())->method('delete');
    $paragraph->expects($this->never())->method('save');
    return $paragraph;
  }

  /**
   * Builds a link widget mock that echoes the submitted link value back.
   */
  protected function linkWidget(int $expected_calls): WidgetInterface&MockObject {
    $link_widget = $this->createMock(WidgetInterface::class);
    $link_widget->expects($this->exactly($expected_calls))
      ->method('massageFormValues')
      ->willReturnArgument(0);
    return $link_widget;
  }

  /**
   * Clearing an existing row drops the reference but never deletes it.
   *
   * @covers ::massageFormValues
   */
  public function testClearedRowDoesNotDeleteParagraph(): void {
    $existing = $this->paragraphThatMustNotBeWritten();
    $this->storage->method('loadRevision')->with(11)->willReturn($existing);
    $this->storage->expects($this->never())->method('delete');
    $this->display->method('getRenderer')->with('field_link')->willReturn($this->linkWidget(0));

    $form = ['#parents' => []];
    $result = $this->widget->massageFormValues([
      ['uri' => '', 'title' => '', 'existing_paragraph_id' => 5, 'existing_paragraph_revision_id' => 11],
    ], $form, new FormState());

    $this->assertSame([], $result);
  }

  /**
   * Editing an existing row updates it in memory and flags it for ERR.
   *
   * @covers ::massageFormValues
   * @covers ::getLinkWidget
   */
  public function testEditedRowIsUpdatedInMemoryOnly(): void {
    $existing = $this->paragraphThatMustNotBeWritten();
    $existing->expects($this->once())->method('set')
      ->with('field_link', ['uri' => 'https://example.com', 'title' => 'Example', 'attributes' => []]);
    $existing->expects($this->once())->method('setNeedsSave')->with(TRUE);
    $this->storage->method('loadRevision')->with(11)->willReturn($existing);
    $this->storage->expects($this->never())->method('save');
    // The link widget must be the one the form display renders field_link
    // with; massageFormValues() on it is the proof it was used.
    $this->display->expects($this->atLeastOnce())->method('getRenderer')
      ->with('field_link')->willReturn($this->linkWidget(1));

    $form = ['#parents' => []];
    $result = $this->widget->massageFormValues([
      [
        'uri' => 'https://example.com',
        'title' => 'Example',
        'existing_paragraph_id' => 5,
        'existing_paragraph_revision_id' => 11,
      ],
    ], $form, new FormState());

    $this->assertCount(1, $result);
    $this->assertSame($existing, $result[0]['entity']);
  }

  /**
   * A filled new row yields an unsaved paragraph for ERR to save later.
   *
   * @covers ::massageFormValues
   */
  public function testNewRowIsReturnedUnsaved(): void {
    $throwaway = $this->paragraphThatMustNotBeWritten();
    $new = $this->paragraphThatMustNotBeWritten();
    $new->expects($this->once())->method('setNeedsSave')->with(TRUE);
    $this->storage->method('create')->with(['type' => 'cta_link'])
      ->willReturnOnConsecutiveCalls($throwaway, $new);
    $this->storage->expects($this->never())->method('save');
    $this->display->method('getRenderer')->with('field_link')->willReturn($this->linkWidget(1));

    $form = ['#parents' => []];
    $result = $this->widget->massageFormValues([
      ['uri' => 'https://example.com', 'title' => 'Example', 'existing_paragraph_id' => NULL],
    ], $form, new FormState());

    $this->assertSame($new, $result[0]['entity']);
  }

  /**
   * A hidden field_link keeps existing references untouched.
   *
   * @covers ::massageFormValues
   * @covers ::getLinkWidget
   */
  public function testHiddenLinkFieldKeepsExistingReference(): void {
    $existing = $this->paragraphThatMustNotBeWritten();
    $existing->expects($this->never())->method('set');
    $this->storage->method('loadRevision')->with(11)->willReturn($existing);
    $this->display->method('getRenderer')->with('field_link')->willReturn(NULL);

    $form = ['#parents' => []];
    $result = $this->widget->massageFormValues([
      ['existing_paragraph_id' => 5, 'existing_paragraph_revision_id' => 11],
    ], $form, new FormState());

    $this->assertSame([['entity' => $existing]], $result);
  }

  /**
   * Row validation messages are translatable markup, not global t().
   *
   * @covers ::validateRow
   */
  public function testValidateRowFlagsMissingTitle(): void {
    $element = [
      'uri' => ['#value' => 'https://example.com', '#parents' => ['uri']],
      'title' => ['#value' => '', '#parents' => ['title']],
    ];
    $form_state = new FormState();
    HeadingLinksWidget::validateRow($element, $form_state, []);

    $errors = $form_state->getErrors();
    $this->assertCount(1, $errors);
    $this->assertInstanceOf(TranslatableMarkup::class, $errors['title']);
  }

}

/**
 * Exposes a fixed form display in place of collectRenderDisplay().
 */
class TestHeadingLinksWidget extends HeadingLinksWidget {

  /**
   * The display to return.
   *
   * @var \Drupal\Core\Entity\Display\EntityFormDisplayInterface
   */
  public EntityFormDisplayInterface $display;

  /**
   * {@inheritdoc}
   */
  protected function getFormDisplay(ParagraphInterface $paragraph): EntityFormDisplayInterface {
    return $this->display;
  }

}
