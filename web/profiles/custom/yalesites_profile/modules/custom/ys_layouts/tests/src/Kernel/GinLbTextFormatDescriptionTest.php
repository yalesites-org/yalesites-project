<?php

namespace Drupal\Tests\ys_layouts\Kernel;

use Drupal\Tests\ys_core\Kernel\YsKernelTestBase;

/**
 * Tests that a rich-text field's help text is styled as help text in gin_lb.
 *
 * Gin_lb's text-format-wrapper template prints the element's attributes on the
 * description div, but those attributes carry the text field's own classes
 * (js-text-full, text-full) and no description class. So in the Layout Builder
 * sidebar the help text under every rich-text field rendered as 16px body
 * text instead of the small gray help text every other field gets
 * (yalesites-org/yalesites-project#1665, the Audio block's Transcription
 * field). ys_layouts_preprocess_text_format_wrapper__gin_lb() adds the classes
 * gin_lb's form-element template gives every other description.
 *
 * @group yalesites
 * @group ys_layouts
 */
class GinLbTextFormatDescriptionTest extends YsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'block_content',
    'layout_discovery',
    'layout_builder',
    'quick_node_clone',
    'formdazzle',
    'ys_themes',
    'ys_layouts',
    'gin_lb',
  ];

  /**
   * The gin_lb description carries the help-text classes, not the field's.
   */
  public function testDescriptionGetsHelpTextClass(): void {
    $help_text = 'Paste the full text of the audio here.';
    $build = [
      '#theme' => 'text_format_wrapper__gin_lb',
      '#children' => '<textarea></textarea>',
      '#description' => $help_text,
      '#attributes' => [
        'class' => ['js-text-full', 'text-full'],
        'aria-describedby' => 'edit-field-transcription-0--description',
      ],
    ];

    $html = (string) $this->container->get('renderer')->renderInIsolation($build);

    $this->assertContains('ys_layouts/gin_lb_text_format', $build['#attached']['library'] ?? []);

    $this->assertMatchesRegularExpression(
      '/<div[^>]*id="edit-field-transcription-0--description"[^>]*>/',
      $html,
      'The description keeps the id the editor field points aria-describedby at.',
    );
    preg_match('/<div[^>]*id="edit-field-transcription-0--description"[^>]*>/', $html, $match);
    $this->assertStringContainsString('glb-form-item__description', $match[0]);
    $this->assertMatchesRegularExpression('/class="[^"]*\bdescription\b/', $match[0]);
  }

}
