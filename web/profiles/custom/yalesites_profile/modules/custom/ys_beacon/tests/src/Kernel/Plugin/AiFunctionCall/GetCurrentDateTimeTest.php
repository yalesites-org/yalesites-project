<?php

namespace Drupal\Tests\ys_beacon\Kernel\Plugin\AiFunctionCall;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ys_beacon\Plugin\AiFunctionCall\GetCurrentDateTime;

/**
 * Tests the current-date-time AI function-call tool.
 *
 * Builds the plugin directly from its own #[FunctionCall] attribute (via
 * reflection) instead of through the full plugin manager, so the test does
 * not need to enable ys_beacon's heavy dependency graph (ai_search, Azure
 * search, Portkey keys) just to exercise one small tool - the same reasoning
 * GuardrailTelemetryTest uses to avoid installing the whole module.
 *
 * @group ys_beacon
 * @coversDefaultClass \Drupal\ys_beacon\Plugin\AiFunctionCall\GetCurrentDateTime
 */
class GetCurrentDateTimeTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['ai', 'system'];

  /**
   * Builds the plugin with a fixed "now".
   *
   * Uses a real Drupal Context/TypedData stack, so context defaulting and
   * value conversion are exercised exactly as the framework would drive
   * them - not simulated.
   *
   * @param int $timestamp
   *   The Unix timestamp ::execute() should treat as "now".
   *
   * @return \Drupal\ys_beacon\Plugin\AiFunctionCall\GetCurrentDateTime
   *   The plugin instance.
   */
  protected function plugin(int $timestamp): GetCurrentDateTime {
    $reflection = new \ReflectionClass(GetCurrentDateTime::class);
    $attribute = $reflection->getAttributes(FunctionCall::class)[0]->newInstance();
    $definition = [
      'id' => $attribute->id,
      'function_name' => $attribute->function_name,
      'name' => $attribute->name,
      'description' => $attribute->description,
      'group' => $attribute->group,
      'context_definitions' => $attribute->context_definitions,
      'class' => GetCurrentDateTime::class,
      'provider' => 'ys_beacon',
    ];

    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn($timestamp);

    return new GetCurrentDateTime(
      [],
      $attribute->id,
      $definition,
      $this->container->get('ai.context_definition_normalizer'),
      $this->container->get('plugin.manager.ai_data_type_converter'),
      $time,
      $this->container->get('date.formatter'),
    );
  }

  /**
   * The plugin declares the metadata the issue's acceptance criteria ask for.
   *
   * Reads the attribute rather than calling anything, so it covers no method.
   */
  public function testDeclaresExpectedMetadata(): void {
    $reflection = new \ReflectionClass(GetCurrentDateTime::class);
    $attribute = $reflection->getAttributes(FunctionCall::class)[0]->newInstance();

    $this->assertSame('ys_beacon:current_date_time', $attribute->id);
    $this->assertSame('information_tools', $attribute->group);
    $this->assertArrayHasKey('timezone', $attribute->context_definitions);
    // Required so the model always sends a non-empty arguments object -
    // an all-optional tool can have a real model send an empty arguments
    // string, which contrib's own Json::decode('') -> NULL TypeErrors on
    // (confirmed live, yalesites-org/YaleSites-Internal#1146).
    $this->assertTrue($attribute->context_definitions['timezone']->isRequired());
  }

  /**
   * @covers ::execute
   */
  public function testDefaultsToYaleTimezoneWhenOmitted(): void {
    $timestamp = (new \DateTimeImmutable('2026-01-15T12:00:00+00:00'))->getTimestamp();
    $expected = (new \DateTimeImmutable('2026-01-15T12:00:00+00:00'))
      ->setTimezone(new \DateTimeZone('America/New_York'));

    $plugin = $this->plugin($timestamp);
    $plugin->execute();
    $output = $plugin->getReadableOutput();

    $this->assertStringContainsString('America/New_York', $output);
    $this->assertStringContainsString($expected->format('Y-m-d'), $output);
    $this->assertStringContainsString('07:00', $output);
  }

  /**
   * @covers ::execute
   */
  public function testHonorsAnExplicitValidTimezone(): void {
    $timestamp = (new \DateTimeImmutable('2026-01-15T12:00:00+00:00'))->getTimestamp();

    $plugin = $this->plugin($timestamp);
    $plugin->setContextValue('timezone', 'Asia/Tokyo');
    $plugin->execute();
    $output = $plugin->getReadableOutput();

    $this->assertStringContainsString('Asia/Tokyo', $output);
    // Tokyo is UTC+9 with no DST: 12:00 UTC -> 21:00.
    $this->assertStringContainsString('21:00', $output);
  }

  /**
   * @covers ::execute
   */
  public function testFallsBackToYaleTimezoneOnAnInvalidTimezone(): void {
    $timestamp = (new \DateTimeImmutable('2026-01-15T12:00:00+00:00'))->getTimestamp();

    $plugin = $this->plugin($timestamp);
    $plugin->setContextValue('timezone', 'Not/AZone');
    $plugin->execute();
    $output = $plugin->getReadableOutput();

    $this->assertStringContainsString('America/New_York', $output);
    $this->assertStringNotContainsString('Not/AZone', $output);
  }

  /**
   * A DST-affected date is localized with the correct offset.
   *
   * @covers ::execute
   */
  public function testHandlesDstCorrectly(): void {
    // America/New_York is on daylight time (EDT, UTC-4) in July.
    $timestamp = (new \DateTimeImmutable('2026-07-15T12:00:00+00:00'))->getTimestamp();

    $plugin = $this->plugin($timestamp);
    $plugin->execute();
    $output = $plugin->getReadableOutput();

    $this->assertStringContainsString('08:00', $output);
  }

}
