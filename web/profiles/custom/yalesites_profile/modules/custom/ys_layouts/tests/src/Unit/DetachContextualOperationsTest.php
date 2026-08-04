<?php

namespace Drupal\Tests\ys_layouts\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_layouts\Render\DetachContextualOperations;

/**
 * Tests that the detach action is registered in contextual link metadata.
 *
 * Covers issue #1449. Core bakes an "operations" list into every
 * layout_builder_block contextual link id (see
 * \Drupal\layout_builder\Element\LayoutBuilder), and the contextual module
 * caches each id's rendered links in the browser's sessionStorage, re-fetching
 * only when the id changes (see core/modules/contextual/js/contextual.js).
 * Adding the "Make non-reusable" link without extending that list leaves the id
 * unchanged, so any browser that already cached the menu keeps showing the old
 * three links and never asks the server for the new one. These tests pin the
 * cache-busting behavior core relies on for its own "move" link.
 *
 * @coversDefaultClass \Drupal\ys_layouts\Render\DetachContextualOperations
 *
 * @group yalesites
 * @group ys_layouts
 */
class DetachContextualOperationsTest extends UnitTestCase {

  /**
   * A layout builder element holding one block with core's metadata.
   *
   * Mirrors the shape built by
   * \Drupal\layout_builder\Element\LayoutBuilder::buildAdministrativeSection():
   * the contextual links sit on a component nested under section and region
   * keys, not at the top level.
   *
   * @param string $operations
   *   The operations metadata value to seed.
   *
   * @return array
   *   The render array.
   */
  protected function elementWithOperations(string $operations): array {
    return [
      '#section_storage' => NULL,
      'layout_builder' => [
        0 => [
          'layout-builder__section' => [
            'content' => [
              'ffffffff-ffff-ffff-ffff-ffffffffffff' => [
                '#contextual_links' => [
                  'layout_builder_block' => [
                    'route_parameters' => ['delta' => 0],
                    'metadata' => ['operations' => $operations],
                  ],
                ],
              ],
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * Reads the operations metadata back out of a processed element.
   *
   * @param array $element
   *   The processed render array.
   *
   * @return string
   *   The operations metadata value.
   */
  protected function readOperations(array $element): string {
    return $element['layout_builder'][0]['layout-builder__section']['content']['ffffffff-ffff-ffff-ffff-ffffffffffff']['#contextual_links']['layout_builder_block']['metadata']['operations'];
  }

  /**
   * The detach operation is appended to core's operations metadata.
   *
   * This is what changes the contextual link id, which is what forces every
   * browser holding a cached three-link menu to re-fetch and pick up
   * "Make non-reusable".
   *
   * @covers ::addDetachOperation
   */
  public function testAppendsDetachOperation(): void {
    $element = DetachContextualOperations::addDetachOperation(
      $this->elementWithOperations('move:update:remove')
    );

    $this->assertSame(
      'move:update:remove:detach',
      $this->readOperations($element),
      'The detach operation is appended, so the contextual link id changes and stale client-side caches are bypassed.'
    );
  }

  /**
   * Applying the callback twice does not duplicate the operation.
   *
   * The id must be stable after the first change, or every render would produce
   * a new id and contextual links would never cache at all.
   *
   * @covers ::addDetachOperation
   */
  public function testIsIdempotent(): void {
    $once = DetachContextualOperations::addDetachOperation(
      $this->elementWithOperations('move:update:remove')
    );
    $twice = DetachContextualOperations::addDetachOperation($once);

    $this->assertSame(
      'move:update:remove:detach',
      $this->readOperations($twice),
      'The operation is added at most once so the contextual link id stays stable.'
    );
  }

  /**
   * Elements without layout builder contextual metadata are left alone.
   *
   * @covers ::addDetachOperation
   */
  public function testLeavesUnrelatedElementsUntouched(): void {
    $element = [
      'content' => [
        'block' => [
          '#contextual_links' => [
            'block' => ['route_parameters' => ['block' => 'somewhere']],
          ],
        ],
      ],
    ];

    $this->assertSame(
      $element,
      DetachContextualOperations::addDetachOperation($element),
      'A contextual group that is not layout_builder_block is not modified.'
    );
  }

  /**
   * The callback is registered as trusted for use as a #pre_render.
   *
   * A #pre_render callback that is not trusted throws
   * UntrustedCallbackException at render time, which would break every Layout
   * Builder page rather than just the detach link.
   *
   * @covers ::trustedCallbacks
   */
  public function testCallbackIsTrusted(): void {
    $this->assertContains(
      'addDetachOperation',
      DetachContextualOperations::trustedCallbacks(),
      'The pre_render callback is declared trusted.'
    );
  }

}
