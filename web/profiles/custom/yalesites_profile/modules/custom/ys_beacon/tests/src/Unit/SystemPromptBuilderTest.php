<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_beacon\Service\SystemPromptBuilder;

/**
 * Tests the system prompt assembly.
 *
 * @group ys_beacon
 * @coversDefaultClass \Drupal\ys_beacon\Service\SystemPromptBuilder
 */
class SystemPromptBuilderTest extends UnitTestCase {

  /**
   * The prompt builder under test.
   *
   * @var \Drupal\ys_beacon\Service\SystemPromptBuilder
   */
  protected SystemPromptBuilder $builder;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // No instructions storage service is passed, exercising the fallback
    // prompt path.
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->with('fallback_system_prompt')
      ->willReturn('FALLBACK INSTRUCTIONS');

    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')
      ->with('ys_beacon.settings')
      ->willReturn($config);

    $this->builder = new SystemPromptBuilder($config_factory);
  }

  /**
   * @covers ::build
   */
  public function testBuildNumbersSourcesInOrder(): void {
    $citations = [
      ['title' => 'Page One', 'content' => 'Alpha content'],
      ['title' => 'Page Two', 'content' => 'Beta content'],
    ];
    $prompt = $this->builder->build($citations);

    $this->assertStringStartsWith('FALLBACK INSTRUCTIONS', $prompt);
    $this->assertStringContainsString("[doc1] Page One\nAlpha content", $prompt);
    $this->assertStringContainsString("[doc2] Page Two\nBeta content", $prompt);
    $this->assertStringContainsString('[doc1]', $prompt);
    // Citation instruction for the frontend's [docN] marker contract.
    $this->assertStringContainsString('Cite every fact with its source marker', $prompt);
  }

  /**
   * @covers ::build
   */
  public function testBuildWithoutSourcesInstructsHonesty(): void {
    $prompt = $this->builder->build([]);

    $this->assertStringStartsWith('FALLBACK INSTRUCTIONS', $prompt);
    $this->assertStringContainsString('No sources were found', $prompt);
    $this->assertStringNotContainsString('[doc1]', $prompt);
  }

}
