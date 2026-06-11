<?php

namespace Drupal\Tests\ys_ai_system_instructions\Unit;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_ai_system_instructions\Service\SystemInstructionsAssistantWriter;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \Drupal\ys_ai_system_instructions\Service\SystemInstructionsAssistantWriter
 *
 * @group yalesites
 */
class SystemInstructionsAssistantWriterTest extends UnitTestCase {

  /**
   * The entity storage mock shared across a test.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $storage;

  /**
   * The logger mock shared across a test.
   *
   * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $logger;

  /**
   * Builds the writer with a configurable assistant id and loaded entity.
   *
   * @param string $assistantId
   *   The value returned for ys_contoso_chat.settings:assistant_id.
   * @param \Drupal\Core\Config\Entity\ConfigEntityInterface|null $assistant
   *   The assistant entity the storage should return for that id, or NULL.
   *
   * @return \Drupal\ys_ai_system_instructions\Service\SystemInstructionsAssistantWriter
   *   The service under test.
   */
  protected function buildWriter(string $assistantId, ?ConfigEntityInterface $assistant): SystemInstructionsAssistantWriter {
    $this->storage = $this->createMock(EntityStorageInterface::class);
    $this->storage->method('load')->willReturnMap([
      [$assistantId, $assistant],
    ]);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('ai_assistant')->willReturn($this->storage);

    $config_factory = $this->getConfigFactoryStub([
      'ys_contoso_chat.settings' => ['assistant_id' => $assistantId],
    ]);

    $this->logger = $this->createMock(LoggerInterface::class);
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn($this->logger);

    return new SystemInstructionsAssistantWriter($entity_type_manager, $config_factory, $logger_factory);
  }

  /**
   * @covers ::getAssistantId
   */
  public function testGetAssistantIdFallsBackToDefault(): void {
    $writer = $this->buildWriter('', NULL);
    $this->assertSame('beacon', $writer->getAssistantId());
  }

  /**
   * @covers ::getAssistantId
   */
  public function testGetAssistantIdUsesConfiguredValue(): void {
    $writer = $this->buildWriter('custom_assistant', NULL);
    $this->assertSame('custom_assistant', $writer->getAssistantId());
  }

  /**
   * @covers ::readInstructions
   */
  public function testReadInstructionsReturnsField(): void {
    $assistant = $this->createMock(ConfigEntityInterface::class);
    $assistant->method('get')->with('instructions')->willReturn('Be helpful.');

    $writer = $this->buildWriter('beacon', $assistant);
    $this->assertSame('Be helpful.', $writer->readInstructions());
  }

  /**
   * @covers ::readInstructions
   */
  public function testReadInstructionsReturnsNullWhenAssistantMissing(): void {
    $writer = $this->buildWriter('beacon', NULL);
    $this->assertNull($writer->readInstructions());
  }

  /**
   * @covers ::writeInstructions
   */
  public function testWriteInstructionsSetsAndSaves(): void {
    $assistant = $this->createMock(ConfigEntityInterface::class);
    $assistant->expects($this->once())->method('set')->with('instructions', 'New guidance.');
    $assistant->expects($this->once())->method('save');

    $writer = $this->buildWriter('beacon', $assistant);
    $this->assertTrue($writer->writeInstructions('New guidance.'));
  }

  /**
   * @covers ::writeInstructions
   */
  public function testWriteInstructionsReturnsFalseWhenAssistantMissing(): void {
    $writer = $this->buildWriter('beacon', NULL);
    $this->logger->expects($this->once())->method('warning');
    $this->assertFalse($writer->writeInstructions('New guidance.'));
  }

}
