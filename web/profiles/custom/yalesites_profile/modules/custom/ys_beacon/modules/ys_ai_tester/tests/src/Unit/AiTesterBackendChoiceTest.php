<?php

declare(strict_types=1);

namespace Drupal\Tests\ys_ai_tester\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_ai_tester\AnswerBackendInterface;
use Drupal\ys_ai_tester\Form\AiTesterForm;

/**
 * Tests which assistant a submitted run targets.
 *
 * @coversDefaultClass \Drupal\ys_ai_tester\Form\AiTesterForm
 * @group ys_beacon
 */
class AiTesterBackendChoiceTest extends UnitTestCase {

  /**
   * @covers ::backendChoices
   * @dataProvider provideBackendChoices
   */
  public function testBackendChoices(array $available, array $expected_keys, string $message): void {
    $this->assertSame($expected_keys, array_keys(AiTesterForm::backendChoices($available)), $message);
  }

  /**
   * Data provider for testBackendChoices().
   */
  public static function provideBackendChoices(): array {
    return [
      'no backends at all offers nothing' => [
        [],
        [],
        'An empty registry renders no selector.',
      ],
      'one backend offers no selector' => [
        ['beacon' => 'Beacon'],
        [],
        'Beacon-only must look exactly as it does today: no selector, no warning.',
      ],
      'two backends offer each plus a compare-both option' => [
        ['beacon' => 'Beacon', 'legacy' => 'Legacy'],
        ['beacon', 'legacy', AiTesterForm::RUN_ALL],
        'Both assistants plus the run-both option are offered.',
      ],
      'three backends drop the compare-both option' => [
        ['beacon' => 'Beacon', 'legacy' => 'Legacy', 'other' => 'Other'],
        ['beacon', 'legacy', 'other'],
        'The comparison view takes exactly two runs, so run-both is not offered for three.',
      ],
    ];
  }

  /**
   * @covers ::resolveRunBackends
   * @dataProvider provideRunBackends
   */
  public function testResolveRunBackends(string $choice, array $available, array $expected, string $message): void {
    $this->assertSame($expected, AiTesterForm::resolveRunBackends($choice, $available), $message);
  }

  /**
   * Data provider for testResolveRunBackends().
   */
  public static function provideRunBackends(): array {
    return [
      'explicit beacon' => [
        'beacon',
        ['beacon', 'legacy'],
        ['beacon'],
        'A chosen backend runs on its own.',
      ],
      'explicit legacy' => [
        'legacy',
        ['beacon', 'legacy'],
        ['legacy'],
        'The legacy assistant can be targeted on its own.',
      ],
      'run all runs every available backend in order' => [
        AiTesterForm::RUN_ALL,
        ['beacon', 'legacy'],
        ['beacon', 'legacy'],
        'Beacon is created first so it becomes Run A.',
      ],
      'empty choice falls back to the default backend' => [
        '',
        ['beacon'],
        ['beacon'],
        'With no selector rendered, the submission targets Beacon.',
      ],
      'a choice that is no longer available falls back to the default' => [
        'legacy',
        ['beacon'],
        ['beacon'],
        'A stale form submitted after legacy went away must not run legacy.',
      ],
      'run all with a single backend runs just that one' => [
        AiTesterForm::RUN_ALL,
        ['beacon'],
        ['beacon'],
        'Run-all degrades to the only available backend.',
      ],
      'unknown choice falls back to the default backend' => [
        'wat',
        ['beacon', 'legacy'],
        ['beacon'],
        'An unrecognised value never selects an arbitrary backend.',
      ],
    ];
  }

  /**
   * The fallback is the interface's declared default, not a copied literal.
   */
  public function testFallbackIsTheDeclaredDefaultBackend(): void {
    $this->assertSame(
      [AnswerBackendInterface::DEFAULT_ID],
      AiTesterForm::resolveRunBackends('', [AnswerBackendInterface::DEFAULT_ID])
    );
  }

}
