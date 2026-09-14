<?php

namespace Drupal\Tests\ys_core\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_core\TextFormatRepairResult;

require_once __DIR__ . '/../../../ys_core.deploy.php';

/**
 * Tests the deploy log message for the text format repair.
 *
 * The reviewer's third ask was that the deploy log say what was touched where
 * rather than printing one total, so the wording is the deliverable and is
 * worth pinning down. .deploy.php is not autoloaded, hence the require_once
 * above.
 *
 * @group ys_core
 * @group yalesites
 *
 * @see yalesites-org/YaleSites-Internal#1646
 */
class TextFormatRepairMessageTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // t() resolves through the container, which a unit test does not get.
    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);
  }

  /**
   * A run that changed nothing says so rather than printing an empty report.
   */
  public function testNothingToRepairIsStated(): void {
    $this->assertSame(
      'No node values had an out-of-contract text format.',
      ys_core_text_format_repair_message([], [])
    );
  }

  /**
   * Counts are broken down per field, not reported as one total.
   */
  public function testRepairCountsAreBrokenDownPerField(): void {
    $message = ys_core_text_format_repair_message([
      'resource.field_abstract' => 6,
      'resource.field_citation' => 4,
    ], []);

    $this->assertSame([
      'Repaired the stored text format on 10 row(s):',
      '  node.resource.field_abstract: 6 row(s)',
      '  node.resource.field_citation: 4 row(s)',
    ], explode("\n", $message));
  }

  /**
   * Deferred values name the nodes and the markup that would have been lost.
   *
   * This is what a human needs to answer the "is the narrower format right
   * here?" question without going back to the database.
   */
  public function testDeferralsNameTheNodesAndTheLostMarkup(): void {
    $result = new TextFormatRepairResult(0, [
      [
        'entity_id' => 12,
        'revision_id' => 40,
        'from' => 'restricted_html',
        'to' => 'heading_html',
        'dropped' => ['a'],
      ],
      [
        'entity_id' => 12,
        'revision_id' => 41,
        'from' => 'restricted_html',
        'to' => 'heading_html',
        'dropped' => ['a', 'p@class'],
      ],
    ]);

    $lines = explode("\n", ys_core_text_format_repair_message([], [
      'resource.field_teaser_text' => $result,
    ]));

    // A run that repaired nothing but deferred something must not also claim
    // nothing was out of contract — the deferred rows are out of contract.
    $this->assertStringNotContainsString('No node values', $lines[0]);
    $this->assertStringContainsString('Left 2 row(s) alone', $lines[0]);
    $this->assertSame(
      '  node.resource.field_teaser_text: 2 row(s) across 1 node(s) (12), would lose a, p@class',
      $lines[1]
    );
  }

}
