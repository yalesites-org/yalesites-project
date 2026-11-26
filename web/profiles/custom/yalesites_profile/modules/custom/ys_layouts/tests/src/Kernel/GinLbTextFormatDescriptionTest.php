<?php

namespace Drupal\Tests\ys_layouts\Kernel;

use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\Tests\ys_core\Kernel\YsKernelTestBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

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

  /**
   * Configure block renders the gin_lb wrapper, so the help text is styled.
   *
   * Gin_lb adds its suggestions to variables-only theme hooks such as
   * text_format_wrapper only on a short list of routes, and Configure block
   * (layout_builder.update_block) is not on it. There the help text fell back
   * to core's template and kept the field's classes.
   *
   * @dataProvider layoutBuilderRouteProvider
   */
  public function testLayoutBuilderRouteUsesGinLbWrapper(string $route_name): void {
    $this->setRoute($route_name);

    $html = $this->renderWrapper();

    preg_match('/<div[^>]*id="edit-field-transcription-0--description"[^>]*>/', $html, $match);
    $this->assertNotEmpty($match, 'The help text keeps the id the editor points aria-describedby at.');
    $this->assertStringContainsString('glb-form-item__description', $match[0]);
    $this->assertSame(1, substr_count($html, 'js-text-format-wrapper'), 'The wrapper renders once.');
  }

  /**
   * Layout Builder sidebar routes that render rich-text fields.
   */
  public static function layoutBuilderRouteProvider(): array {
    return [
      'configure block' => ['layout_builder.update_block'],
      'add block' => ['layout_builder.add_block'],
    ];
  }

  /**
   * Outside Layout Builder the rich-text wrapper keeps core's template.
   */
  public function testOtherRouteKeepsCoreWrapper(): void {
    $this->setRoute('entity.node.edit_form');

    $this->assertStringNotContainsString('glb-form-item', $this->renderWrapper());
  }

  /**
   * Renders a rich-text wrapper the way the text_format element does.
   */
  protected function renderWrapper(): string {
    $help_text = 'Paste the full text of the audio here.';
    $build = [
      '#theme' => 'text_format_wrapper',
      '#children' => '<textarea></textarea>',
      '#description' => $help_text,
      '#attributes' => [
        'class' => ['js-text-full', 'text-full'],
        'aria-describedby' => 'edit-field-transcription-0--description',
      ],
    ];
    return (string) $this->container->get('renderer')->renderInIsolation($build);
  }

  /**
   * Makes the given route the current route.
   */
  protected function setRoute(string $route_name): void {
    $request = Request::create('/');
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $route_name);
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, new Route('/'));
    $this->container->get('request_stack')->push($request);
  }

}
