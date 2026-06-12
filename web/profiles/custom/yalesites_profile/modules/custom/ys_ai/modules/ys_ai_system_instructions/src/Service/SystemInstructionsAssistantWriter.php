<?php

namespace Drupal\ys_ai_system_instructions\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Reads and writes the system instructions the live chatbot uses at runtime.
 *
 * The Yale Chat chatbot runs the configured `ai_assistant` entity through
 * \Drupal\ai_assistant_api\AiAssistantApiRunner. When that assistant has an
 * `ai_agent` set (and the ai_agents module is enabled), the runner delegates to
 * the agent and the assistant's own `instructions` field is never read — the
 * live prompt comes from the agent's `system_prompt` instead (see
 * AiAssistantApiRunner::process()). This service resolves whichever field the
 * runtime actually consumes, so editing system instructions changes the bot.
 */
class SystemInstructionsAssistantWriter {

  /**
   * The default assistant id used when none is configured.
   */
  const DEFAULT_ASSISTANT_ID = 'beacon';

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a SystemInstructionsAssistantWriter.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, ConfigFactoryInterface $config_factory, ModuleHandlerInterface $module_handler, LoggerChannelFactoryInterface $logger_factory) {
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->moduleHandler = $module_handler;
    $this->logger = $logger_factory->get('ys_ai_system_instructions');
  }

  /**
   * Get the id of the AI Assistant the chatbot is configured to use.
   *
   * Resolved from the Yale Chat settings so writes always target the exact
   * assistant consumed at runtime, falling back to the shipped default.
   *
   * @return string
   *   The assistant config entity id.
   */
  public function getAssistantId(): string {
    $assistant_id = $this->configFactory->get('ys_contoso_chat.settings')->get('assistant_id');

    return $assistant_id ?: self::DEFAULT_ASSISTANT_ID;
  }

  /**
   * Read the current instructions the live chatbot uses.
   *
   * @return string|null
   *   The runtime instructions, or NULL if the target entity cannot be loaded.
   */
  public function readInstructions(): ?string {
    $entity = $this->loadTargetEntity();
    if (!$entity) {
      return NULL;
    }

    return $entity->get($this->resolveTarget()['field']);
  }

  /**
   * Write instructions to whichever entity the live chatbot reads at runtime.
   *
   * Stores the raw instructions on the resolved field (the agent's
   * `system_prompt` or the assistant's `instructions`); the runner consumes the
   * value directly, so no escaping is applied.
   *
   * @param string $instructions
   *   The instructions to store.
   *
   * @return bool
   *   TRUE if the entity was updated, FALSE if it could not be loaded.
   */
  public function writeInstructions(string $instructions): bool {
    $entity = $this->loadTargetEntity();
    if (!$entity) {
      $this->logger->warning('Could not update chatbot system instructions: target config entity "@name" not found.', [
        '@name' => $this->getTargetConfigName() ?? $this->getAssistantId(),
      ]);
      return FALSE;
    }

    $entity->set($this->resolveTarget()['field'], $instructions);
    $entity->save();

    return TRUE;
  }

  /**
   * Get the config name of the entity the live chatbot reads at runtime.
   *
   * Used to read the shipped default from sync storage on first deploy.
   *
   * @return string|null
   *   The config object name (e.g. `ai_agents.ai_agent.beacon`), or NULL if it
   *   cannot be resolved.
   */
  public function getTargetConfigName(): ?string {
    $target = $this->resolveTarget();
    if (!$target) {
      return NULL;
    }

    // getConfigPrefix() includes the provider (e.g. "ai_agents.ai_agent"), so
    // the full config name is that prefix plus the entity id.
    $prefix = $this->entityTypeManager
      ->getDefinition($target['entity_type'])
      ->getConfigPrefix();

    return $prefix . '.' . $target['entity_id'];
  }

  /**
   * Get the field on the target entity that holds the runtime instructions.
   *
   * @return string|null
   *   The field name (`system_prompt` or `instructions`), or NULL if the
   *   target cannot be resolved.
   */
  public function getTargetField(): ?string {
    return $this->resolveTarget()['field'] ?? NULL;
  }

  /**
   * Resolve the entity and field the live chatbot reads at runtime.
   *
   * Mirrors AiAssistantApiRunner: when the configured assistant delegates to an
   * agent (and ai_agents is enabled), the agent's `system_prompt` is the live
   * prompt; otherwise the assistant's own `instructions` field is used.
   *
   * @return array|null
   *   An array with 'entity_type', 'entity_id', and 'field' keys, or NULL if
   *   the configured assistant cannot be loaded.
   */
  protected function resolveTarget(): ?array {
    $assistant_id = $this->getAssistantId();
    $assistant = $this->entityTypeManager
      ->getStorage('ai_assistant')
      ->load($assistant_id);
    if (!$assistant) {
      return NULL;
    }

    $agent_id = $assistant->get('ai_agent');
    if ($agent_id && $this->moduleHandler->moduleExists('ai_agents')) {
      return [
        'entity_type' => 'ai_agent',
        'entity_id' => $agent_id,
        'field' => 'system_prompt',
      ];
    }

    return [
      'entity_type' => 'ai_assistant',
      'entity_id' => $assistant_id,
      'field' => 'instructions',
    ];
  }

  /**
   * Load the resolved target config entity.
   *
   * @return \Drupal\Core\Config\Entity\ConfigEntityInterface|null
   *   The target entity, or NULL if it does not exist.
   */
  protected function loadTargetEntity() {
    $target = $this->resolveTarget();
    if (!$target) {
      return NULL;
    }

    return $this->entityTypeManager
      ->getStorage($target['entity_type'])
      ->load($target['entity_id']);
  }

}
