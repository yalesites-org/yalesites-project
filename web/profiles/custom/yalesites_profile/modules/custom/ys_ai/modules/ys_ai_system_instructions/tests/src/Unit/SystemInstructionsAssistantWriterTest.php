<?php

namespace Drupal\Tests\ys_ai_system_instructions\Unit;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
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
   * The logger mock shared across a test.
   *
   * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $logger;

  /**
   * Builds the writer wired to mock assistant and agent storages.
   *
   * @param \Drupal\Core\Config\Entity\ConfigEntityInterface|null $assistant
   *   The ai_assistant entity returned for the configured id, or NULL.
   * @param \Drupal\Core\Config\Entity\ConfigEntityInterface|null $agent
   *   The ai_agent entity returned for the assistant's ai_agent id, or NULL.
   * @param bool $ai_agents_enabled
   *   Whether the ai_agents module reports as enabled.
   * @param string $assistant_id
   *   The configured assistant id (ys_contoso_chat.settings:assistant_id).
   * @param string|null $agent_id
   *   The agent id the assistant delegates to (its ai_agent value).
   *
   * @return \Drupal\ys_ai_system_instructions\Service\SystemInstructionsAssistantWriter
   *   The service under test.
   */
  protected function buildWriter(?ConfigEntityInterface $assistant, ?ConfigEntityInterface $agent, bool $ai_agents_enabled = TRUE, string $assistant_id = 'beacon', ?string $agent_id = 'beacon'): SystemInstructionsAssistantWriter {
    $assistant_storage = $this->createMock(EntityStorageInterface::class);
    $assistant_storage->method('load')->willReturnMap([[$assistant_id, $assistant]]);

    $agent_storage = $this->createMock(EntityStorageInterface::class);
    $agent_storage->method('load')->willReturnMap([[$agent_id, $agent]]);

    // Config entity type definitions expose getConfigPrefix() for the config
    // name (provider-prefixed, e.g. "ai_agents.ai_agent").
    $agent_type = $this->createMock(ConfigEntityTypeInterface::class);
    $agent_type->method('getConfigPrefix')->willReturn('ai_agents.ai_agent');
    $assistant_type = $this->createMock(ConfigEntityTypeInterface::class);
    $assistant_type->method('getConfigPrefix')->willReturn('ai_assistant_api.ai_assistant');

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->willReturnMap([
      ['ai_assistant', $assistant_storage],
      ['ai_agent', $agent_storage],
    ]);
    $entity_type_manager->method('getDefinition')->willReturnMap([
      ['ai_agent', TRUE, $agent_type],
      ['ai_assistant', TRUE, $assistant_type],
    ]);

    $module_handler = $this->createMock(ModuleHandlerInterface::class);
    $module_handler->method('moduleExists')->willReturnMap([['ai_agents', $ai_agents_enabled]]);

    $config_factory = $this->getConfigFactoryStub([
      'ys_contoso_chat.settings' => ['assistant_id' => $assistant_id],
    ]);

    $this->logger = $this->createMock(LoggerInterface::class);
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn($this->logger);

    return new SystemInstructionsAssistantWriter($entity_type_manager, $config_factory, $module_handler, $logger_factory);
  }

  /**
   * Builds an ai_assistant mock that delegates to the given agent id.
   */
  protected function assistant(?string $agent_id): ConfigEntityInterface {
    $assistant = $this->createMock(ConfigEntityInterface::class);
    $assistant->method('get')->with('ai_agent')->willReturn($agent_id);
    return $assistant;
  }

  /**
   * @covers ::getAssistantId
   */
  public function testGetAssistantIdFallsBackToDefault(): void {
    $writer = $this->buildWriter($this->assistant('beacon'), $this->createMock(ConfigEntityInterface::class), TRUE, '');
    $this->assertSame('beacon', $writer->getAssistantId());
  }

  /**
   * The agent's system_prompt is the live prompt when the assistant delegates.
   *
   * @covers ::readInstructions
   * @covers ::getTargetConfigName
   * @covers ::getTargetField
   */
  public function testTargetsAgentSystemPromptWhenAssistantDelegates(): void {
    $agent = $this->createMock(ConfigEntityInterface::class);
    $agent->method('get')->with('system_prompt')->willReturn('Agent prompt.');

    $writer = $this->buildWriter($this->assistant('beacon'), $agent);

    $this->assertSame('Agent prompt.', $writer->readInstructions());
    $this->assertSame('system_prompt', $writer->getTargetField());
    $this->assertSame('ai_agents.ai_agent.beacon', $writer->getTargetConfigName());
  }

  /**
   * @covers ::writeInstructions
   */
  public function testWriteTargetsAgentSystemPrompt(): void {
    $agent = $this->createMock(ConfigEntityInterface::class);
    $agent->expects($this->once())->method('set')->with('system_prompt', 'New guidance.');
    $agent->expects($this->once())->method('save');

    $writer = $this->buildWriter($this->assistant('beacon'), $agent);
    $this->assertTrue($writer->writeInstructions('New guidance.'));
  }

  /**
   * Falls back to the assistant's instructions when there is no agent.
   *
   * @covers ::writeInstructions
   * @covers ::getTargetField
   */
  public function testFallsBackToAssistantInstructionsWithoutAgent(): void {
    $assistant = $this->createMock(ConfigEntityInterface::class);
    $assistant->method('get')->willReturnMap([
      ['ai_agent', NULL],
      ['instructions', 'Assistant instructions.'],
    ]);
    $assistant->expects($this->once())->method('set')->with('instructions', 'New guidance.');
    $assistant->expects($this->once())->method('save');

    $writer = $this->buildWriter($assistant, NULL, TRUE, 'beacon', NULL);

    $this->assertSame('instructions', $writer->getTargetField());
    $this->assertSame('Assistant instructions.', $writer->readInstructions());
    $this->assertTrue($writer->writeInstructions('New guidance.'));
  }

  /**
   * Falls back to the assistant when ai_agents is disabled.
   *
   * @covers ::getTargetField
   */
  public function testFallsBackToAssistantWhenAgentsModuleDisabled(): void {
    $writer = $this->buildWriter($this->assistant('beacon'), NULL, FALSE);
    $this->assertSame('instructions', $writer->getTargetField());
    $this->assertSame('ai_assistant_api.ai_assistant.beacon', $writer->getTargetConfigName());
  }

  /**
   * @covers ::readInstructions
   * @covers ::writeInstructions
   */
  public function testHandlesMissingAssistant(): void {
    $writer = $this->buildWriter(NULL, NULL);
    $this->logger->expects($this->once())->method('warning');
    $this->assertNull($writer->readInstructions());
    $this->assertFalse($writer->writeInstructions('New guidance.'));
  }

}
