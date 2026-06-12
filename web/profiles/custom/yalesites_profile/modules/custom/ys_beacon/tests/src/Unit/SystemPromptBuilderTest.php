<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_beacon\Service\SystemInstructionsStorage;
use Drupal\ys_beacon\Service\SystemPromptBuilder;

/**
 * Tests the system prompt assembly.
 *
 * @group ys_beacon
 * @coversDefaultClass \Drupal\ys_beacon\Service\SystemPromptBuilder
 */
class SystemPromptBuilderTest extends UnitTestCase {

  /**
   * The mocked config factory returning the fallback prompt.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->with('fallback_system_prompt')
      ->willReturn('FALLBACK INSTRUCTIONS');

    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->configFactory->method('get')
      ->with('ys_beacon.settings')
      ->willReturn($config);
  }

  /**
   * Creates a prompt builder with the given active instructions row.
   *
   * @param array|null $active
   *   The row returned by the storage's getActiveInstructions(), or NULL
   *   when no version has been saved.
   *
   * @return \Drupal\ys_beacon\Service\SystemPromptBuilder
   *   The builder under test.
   */
  protected function createBuilder(?array $active): SystemPromptBuilder {
    $storage = $this->createMock(SystemInstructionsStorage::class);
    $storage->method('getActiveInstructions')->willReturn($active);
    return new SystemPromptBuilder($this->configFactory, $storage);
  }

  /**
   * @covers ::build
   */
  public function testBuildNumbersSourcesInOrder(): void {
    $builder = $this->createBuilder(NULL);
    $citations = [
      ['title' => 'Page One', 'content' => 'Alpha content'],
      ['title' => 'Page Two', 'content' => 'Beta content'],
    ];
    $prompt = $builder->build($citations);

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
    $builder = $this->createBuilder(NULL);
    $prompt = $builder->build([]);

    $this->assertStringStartsWith('FALLBACK INSTRUCTIONS', $prompt);
    $this->assertStringContainsString('No sources were found', $prompt);
    $this->assertStringNotContainsString('[doc1]', $prompt);
  }

  /**
   * @covers ::build
   */
  public function testBuildUsesActiveInstructionsOverFallback(): void {
    $builder = $this->createBuilder(['instructions' => 'SITE INSTRUCTIONS']);
    $prompt = $builder->build([
      ['title' => 'Page One', 'content' => 'Alpha content'],
    ]);

    $this->assertStringStartsWith('SITE INSTRUCTIONS', $prompt);
    $this->assertStringNotContainsString('FALLBACK INSTRUCTIONS', $prompt);
  }

}
