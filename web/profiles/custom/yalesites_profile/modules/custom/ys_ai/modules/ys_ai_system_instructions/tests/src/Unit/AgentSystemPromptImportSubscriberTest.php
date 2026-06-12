<?php

namespace Drupal\Tests\ys_ai_system_instructions\Unit;

use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Config\StorageTransformEvent;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_ai_system_instructions\EventSubscriber\AgentSystemPromptImportSubscriber;

/**
 * @coversDefaultClass \Drupal\ys_ai_system_instructions\EventSubscriber\AgentSystemPromptImportSubscriber
 *
 * @group yalesites
 */
class AgentSystemPromptImportSubscriberTest extends UnitTestCase {

  /**
   * Builds the transform event around a storage mock.
   *
   * @param \Drupal\Core\Config\StorageInterface $storage
   *   The transform storage.
   *
   * @return \Drupal\Core\Config\StorageTransformEvent
   *   The event.
   */
  protected function event(StorageInterface $storage): StorageTransformEvent {
    return new StorageTransformEvent($storage);
  }

  /**
   * Runs after config_ignore's import transform.
   *
   * @covers ::getSubscribedEvents
   */
  public function testRunsAfterConfigIgnore(): void {
    $events = AgentSystemPromptImportSubscriber::getSubscribedEvents();
    $priority = $events[ConfigEvents::STORAGE_TRANSFORM_IMPORT][1];
    // config_ignore default priority is -100; we must run after it.
    $this->assertLessThan(-100, $priority);
  }

  /**
   * A stripped system_prompt is restored to an empty string so import succeeds.
   *
   * @covers ::onImportTransform
   */
  public function testRestoresStrippedSystemPrompt(): void {
    $storage = $this->createMock(StorageInterface::class);
    $storage->method('exists')->with(AgentSystemPromptImportSubscriber::AGENT_CONFIG_NAME)->willReturn(TRUE);
    $storage->method('read')->willReturn(['id' => 'beacon', 'label' => 'Beacon']);

    $storage->expects($this->once())
      ->method('write')
      ->with(
        AgentSystemPromptImportSubscriber::AGENT_CONFIG_NAME,
        $this->callback(fn($data) => ($data['system_prompt'] ?? NULL) === '')
      );

    (new AgentSystemPromptImportSubscriber())->onImportTransform($this->event($storage));
  }

  /**
   * An existing system_prompt is left untouched (no overwrite on update).
   *
   * @covers ::onImportTransform
   */
  public function testLeavesExistingSystemPrompt(): void {
    $storage = $this->createMock(StorageInterface::class);
    $storage->method('exists')->willReturn(TRUE);
    $storage->method('read')->willReturn(['id' => 'beacon', 'system_prompt' => 'Admin value.']);

    $storage->expects($this->never())->method('write');

    (new AgentSystemPromptImportSubscriber())->onImportTransform($this->event($storage));
  }

  /**
   * Does nothing when the agent config is not part of the import.
   *
   * @covers ::onImportTransform
   */
  public function testIgnoresMissingAgentConfig(): void {
    $storage = $this->createMock(StorageInterface::class);
    $storage->method('exists')->willReturn(FALSE);
    $storage->expects($this->never())->method('read');
    $storage->expects($this->never())->method('write');

    (new AgentSystemPromptImportSubscriber())->onImportTransform($this->event($storage));
  }

}
