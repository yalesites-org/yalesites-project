<?php

declare(strict_types=1);

namespace Drupal\Tests\ys_ai_tester\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_ai_tester\AnswerBackendInterface;
use Drupal\ys_ai_tester\AnswerBackendRegistry;

/**
 * Tests the answer backend registry that collects tagged backends.
 *
 * @coversDefaultClass \Drupal\ys_ai_tester\AnswerBackendRegistry
 * @group ys_beacon
 */
class AnswerBackendRegistryTest extends UnitTestCase {

  /**
   * Builds a stub backend.
   *
   * @param string $id
   *   The backend id.
   * @param string $label
   *   The human-readable label.
   * @param bool $available
   *   Whether the backend reports itself as available.
   *
   * @return \Drupal\ys_ai_tester\AnswerBackendInterface
   *   The stub backend.
   */
  private function backend(string $id, string $label, bool $available): AnswerBackendInterface {
    $backend = $this->createMock(AnswerBackendInterface::class);
    $backend->method('id')->willReturn($id);
    $backend->method('label')->willReturn($label);
    $backend->method('isAvailable')->willReturn($available);
    return $backend;
  }

  /**
   * Builds a registry over the given backends.
   *
   * @param \Drupal\ys_ai_tester\AnswerBackendInterface ...$backends
   *   The backends to register.
   *
   * @return \Drupal\ys_ai_tester\AnswerBackendRegistry
   *   The registry.
   */
  private function registry(AnswerBackendInterface ...$backends): AnswerBackendRegistry {
    return new AnswerBackendRegistry($backends);
  }

  /**
   * @covers ::getAvailable
   */
  public function testGetAvailableReturnsBackendById(): void {
    $beacon = $this->backend('beacon', 'Beacon', TRUE);
    $registry = $this->registry($beacon);

    $this->assertSame($beacon, $registry->getAvailable('beacon'));
  }

  /**
   * @covers ::getAvailable
   */
  public function testGetAvailableReturnsNullForUnknownId(): void {
    $registry = $this->registry($this->backend('beacon', 'Beacon', TRUE));

    $this->assertNull($registry->getAvailable('nope'));
  }

  /**
   * Tests that an unavailable backend is nameable but not runnable.
   *
   * Historical runs stay viewable and correctly labelled after the backend
   * they used is switched off, but nothing can be run against it.
   *
   * @covers ::getAvailable
   * @covers ::labelFor
   */
  public function testUnavailableBackendIsNameableButNotRunnable(): void {
    $registry = $this->registry(
      $this->backend('beacon', 'Beacon', TRUE),
      $this->backend('legacy', 'Legacy ai_engine', FALSE),
    );

    $this->assertSame(
      'Legacy ai_engine',
      (string) $registry->labelFor('legacy'),
      'An unavailable backend still names itself, so its stored runs stay readable.'
    );
    $this->assertNull($registry->getAvailable('legacy'), 'An unavailable backend cannot be run.');
  }

  /**
   * @covers ::availableOptions
   */
  public function testAvailableOptionsExcludesUnavailableBackends(): void {
    $registry = $this->registry(
      $this->backend('beacon', 'Beacon', TRUE),
      $this->backend('legacy', 'Legacy ai_engine', FALSE),
    );

    $this->assertSame(['beacon' => 'Beacon'], $registry->availableOptions());
  }

  /**
   * @covers ::availableOptions
   */
  public function testAvailableOptionsKeepsRegistrationOrder(): void {
    $registry = $this->registry(
      $this->backend('beacon', 'Beacon', TRUE),
      $this->backend('legacy', 'Legacy ai_engine', TRUE),
    );

    $this->assertSame(
      ['beacon' => 'Beacon', 'legacy' => 'Legacy ai_engine'],
      $registry->availableOptions()
    );
  }

  /**
   * @covers ::availableIds
   */
  public function testAvailableIdsListsOnlyRunnableBackends(): void {
    $registry = $this->registry(
      $this->backend('beacon', 'Beacon', TRUE),
      $this->backend('legacy', 'Legacy ai_engine', FALSE),
    );

    $this->assertSame(['beacon'], $registry->availableIds());
  }

  /**
   * @covers ::labelFor
   */
  public function testLabelForUsesTheBackendLabel(): void {
    $registry = $this->registry($this->backend('beacon', 'Beacon', TRUE));

    $this->assertSame('Beacon', (string) $registry->labelFor('beacon'));
  }

  /**
   * Tests that an unknown backend id falls back to showing the raw id.
   *
   * A run stored against a backend whose module has since been uninstalled has
   * no service to ask for a label, so the stored id is shown rather than an
   * empty cell — the run stays readable.
   *
   * @covers ::labelFor
   */
  public function testLabelForFallsBackToTheStoredId(): void {
    $registry = $this->registry($this->backend('beacon', 'Beacon', TRUE));

    $this->assertSame('legacy', (string) $registry->labelFor('legacy'));
  }

}
