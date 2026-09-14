<?php

declare(strict_types=1);

namespace Drupal\Tests\ys_layouts\Unit;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_layouts\WebformResultsLinkBuilder;

/**
 * Tests the "View form submissions" link added to a form block's pencil.
 *
 * Regression coverage for the gap left by yalesites-org/YaleSites-Internal#929:
 * hiding the Webform module's own contextual links removed the only in-context
 * route an editor had to their submissions, so the Layout Builder pencil on a
 * Pre-Built Form block now carries a link to that form's results.
 *
 * @coversDefaultClass \Drupal\ys_layouts\WebformResultsLinkBuilder
 *
 * @group yalesites
 * @group ys_layouts
 */
class WebformResultsLinkBuilderTest extends UnitTestCase {

  /**
   * Route parameters core puts on a component's contextual links.
   */
  protected const ROUTE_PARAMETERS = [
    'section_storage_type' => 'overrides',
    'section_storage' => 'node.3',
    'delta' => 2,
    'region' => 'content',
    'uuid' => 'c32a5017-2f68-457d-90f1-c4753a19bed3',
  ];

  /**
   * Tests that a form block's pencil gets a link to that form's results.
   *
   * The webform is read off the block content entity already in the component's
   * render array, so the link follows whatever Layout Builder rendered —
   * including a block placed or repointed in an unsaved editing session, which
   * has no saved revision to load.
   *
   * @covers ::preRender
   */
  public function testAttachesResultsLinkToFormBlock(): void {
    $element = $this->buildElement($this->blockContent('webform', 'contact'));

    $result = WebformResultsLinkBuilder::preRender($element);
    $links = $result['layout_builder']['0']['content']['#contextual_links'];

    $this->assertSame(
      ['route_parameters' => ['webform' => 'contact']],
      $links['ys_layouts_webform_results']
    );
    // Core's own group is left exactly as it was.
    $this->assertSame(
      self::ROUTE_PARAMETERS,
      $links['layout_builder_block']['route_parameters']
    );
  }

  /**
   * Tests that a block with no webform field is left alone.
   *
   * @covers ::preRender
   */
  public function testIgnoresBlockWithoutWebformField(): void {
    $element = $this->buildElement($this->blockContent('text', 'contact'));

    $result = WebformResultsLinkBuilder::preRender($element);

    $this->assertArrayNotHasKey(
      'ys_layouts_webform_results',
      $result['layout_builder']['0']['content']['#contextual_links']
    );
  }

  /**
   * Tests that a webform field referencing nothing attaches no link.
   *
   * @covers ::preRender
   */
  public function testIgnoresEmptyWebformField(): void {
    $element = $this->buildElement($this->blockContent('webform', NULL));

    $result = WebformResultsLinkBuilder::preRender($element);

    $this->assertArrayNotHasKey(
      'ys_layouts_webform_results',
      $result['layout_builder']['0']['content']['#contextual_links']
    );
  }

  /**
   * Tests that a component rendering no block content is skipped.
   *
   * Most placed blocks are not content blocks at all (views, the page meta
   * block, and so on), and every one of them reaches this code.
   *
   * @covers ::preRender
   */
  public function testIgnoresComponentWithoutBlockContent(): void {
    $element = [
      'layout_builder' => [
        '0' => [
          'content' => [
            '#contextual_links' => [
              'layout_builder_block' => ['route_parameters' => self::ROUTE_PARAMETERS],
            ],
            'content' => ['#markup' => 'a view, not a content block'],
          ],
        ],
      ],
    ];

    $result = WebformResultsLinkBuilder::preRender($element);

    $this->assertArrayNotHasKey(
      'ys_layouts_webform_results',
      $result['layout_builder']['0']['content']['#contextual_links']
    );
  }

  /**
   * Tests that components are found however deeply the layout nests them.
   *
   * @covers ::preRender
   */
  public function testAttachesAtAnyNestingDepth(): void {
    $element = [
      'layout_builder' => [
        '0' => [
          'layout' => [
            'first' => [
              'c32a5017' => [
                '#contextual_links' => [
                  'layout_builder_block' => ['route_parameters' => self::ROUTE_PARAMETERS],
                ],
                'content' => ['#block_content' => $this->blockContent('webform', 'apply')],
              ],
            ],
          ],
        ],
      ],
    ];

    $result = WebformResultsLinkBuilder::preRender($element);

    $this->assertSame(
      ['route_parameters' => ['webform' => 'apply']],
      $result['layout_builder']['0']['layout']['first']['c32a5017']['#contextual_links']['ys_layouts_webform_results']
    );
  }

  /**
   * Tests that an element with no built layout is returned untouched.
   *
   * @covers ::preRender
   */
  public function testNoOpWithoutBuiltLayout(): void {
    $element = ['#section_storage' => 'overrides', '#markup' => 'nothing built'];

    $this->assertSame($element, WebformResultsLinkBuilder::preRender($element));
  }

  /**
   * The pre-render callback must be trusted or the renderer throws.
   *
   * @covers ::trustedCallbacks
   */
  public function testPreRenderIsTrusted(): void {
    $this->assertContains('preRender', WebformResultsLinkBuilder::trustedCallbacks());
  }

  /**
   * Builds a layout_builder element holding one placed block component.
   *
   * Mirrors the real build: core's contextual group on the component, and the
   * rendered entity under the entity-type key of the component's content.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $block_content
   *   The block content entity the component renders.
   *
   * @return array
   *   The layout_builder render element.
   */
  protected function buildElement(FieldableEntityInterface $block_content): array {
    return [
      'layout_builder' => [
        '0' => [
          'content' => [
            '#contextual_links' => [
              'layout_builder_block' => ['route_parameters' => self::ROUTE_PARAMETERS],
            ],
            'content' => ['#block_content' => $block_content],
          ],
        ],
      ],
    ];
  }

  /**
   * Mocks a block content entity carrying one field.
   *
   * @param string $field_type
   *   The field's type — 'webform' for a Pre-Built Form block.
   * @param string|null $target_id
   *   The referenced webform id, or NULL for an empty field.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface
   *   The mocked block content entity.
   */
  protected function blockContent(string $field_type, ?string $target_id): FieldableEntityInterface {
    $definition = $this->createMock(FieldDefinitionInterface::class);
    $definition->method('getType')->willReturn($field_type);

    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('getFieldDefinition')->willReturn($definition);
    $field->method('getValue')->willReturn($target_id === NULL ? [] : [['target_id' => $target_id]]);

    $block_content = $this->createMock(FieldableEntityInterface::class);
    $block_content->method('getFields')->willReturn(['field_form' => $field]);

    return $block_content;
  }

}
