<?php

namespace Drupal\Tests\ys_core\Kernel;

/**
 * Tests the Paragraphs multiple-value-form preprocess against a rowless table.
 *
 * In the Layout Builder off-canvas dialog, gin_lb's preprocess for
 * field_multiple_value_form adds a 'glb-table' class to $variables['table']
 * even when the widget is single-value and core built no table. That leaves a
 * table holding only '#attributes'. Paragraphs' own preprocess then loops over
 * $variables['table']['#rows'] without checking it exists, logging
 * 'Undefined array key "#rows"' and a foreach() warning. It shows on the
 * re-render after a validation error, for example an Accordion item saved
 * with no heading, whose single-value field_content uses the legacy
 * entity_reference_paragraphs widget.
 *
 * The fix is a patch on drupal/paragraphs declared in the profile's
 * composer.json, so these tests also fail if a dependency resolve drops it.
 * Upstream: https://www.drupal.org/project/paragraphs/issues/3574118
 *
 * PHPUnit here runs locally only; CI runs no PHPUnit.
 *
 * @group ys_core
 * @group yalesites
 */
class ParagraphsMultipleValueFormRowsTest extends YsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'entity_reference_revisions',
    'field',
    'file',
    'paragraphs',
    'system',
    'user',
  ];

  /**
   * Runs the Paragraphs preprocess and returns any PHP warnings it raised.
   *
   * @param array $variables
   *   The preprocess variables, altered in place.
   *
   * @return string[]
   *   The messages of every warning or notice raised during the call.
   */
  protected function preprocess(array &$variables): array {
    $messages = [];
    set_error_handler(function (int $errno, string $message) use (&$messages): bool {
      $messages[] = $message;
      return TRUE;
    });
    try {
      paragraphs_preprocess_field_multiple_value_form($variables);
    }
    finally {
      restore_error_handler();
    }
    return $messages;
  }

  /**
   * A table with only '#attributes', as gin_lb leaves it, raises no warning.
   */
  public function testRowlessTableRaisesNoWarning(): void {
    $variables = [
      'element' => [
        '#inline_paragraphs_widget' => TRUE,
        '#cardinality' => 1,
        '#gin_lb_form' => TRUE,
      ],
      'multiple' => FALSE,
      'table' => [
        '#attributes' => ['class' => ['glb-table']],
      ],
    ];

    $this->assertSame([], $this->preprocess($variables));
    $this->assertArrayNotHasKey('#rows', $variables['table'], 'The preprocess does not invent rows.');
    $this->assertSame(['glb-table'], $variables['table']['#attributes']['class']);
  }

  /**
   * A real table still gets every row processed.
   *
   * Guards against a fix that skips the loop whenever rows exist.
   */
  public function testRowsAreStillProcessed(): void {
    $variables = [
      'element' => [
        '#paragraphs_widget' => TRUE,
        '#cardinality' => -1,
      ],
      'multiple' => TRUE,
      'table' => [
        '#header' => [['data' => 'Items'], [], 'Order'],
        '#rows' => [
          [
            'data' => [
              ['data' => '', 'class' => ['field-multiple-drag']],
              ['data' => ['#paragraph_type' => 'accordion_item']],
              ['data' => []],
              ['data' => ['#type' => 'weight'], 'class' => ['delta-order']],
            ],
            'class' => ['draggable'],
          ],
        ],
        '#tabledrag' => [['action' => 'order']],
      ],
    ];

    $this->assertSame([], $this->preprocess($variables));
    $row = $variables['table']['#rows'][0];
    $this->assertContains('paragraph-type--accordion-item', $row['class']);
    $this->assertArrayNotHasKey(2, $row['data'], 'The unused actions cell is removed.');
    $this->assertArrayNotHasKey(1, $variables['table']['#header'], 'The unused actions header is removed.');
  }

}
