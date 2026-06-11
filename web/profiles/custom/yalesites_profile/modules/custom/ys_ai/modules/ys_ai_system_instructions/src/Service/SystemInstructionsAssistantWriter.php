<?php

namespace Drupal\ys_ai_system_instructions\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Reads and writes the system instructions on the chatbot's AI Assistant.
 *
 * The live Yale Chat chatbot loads its instructions from an
 * `ai_assistant_api.ai_assistant` config entity at runtime (see
 * \Drupal\ys_contoso_chat\Controller\YsContosoChatController). This service is
 * the bridge that lets the system-instructions versioning UI update that
 * entity, so making a version active actually changes what the chatbot uses.
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
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, ConfigFactoryInterface $config_factory, LoggerChannelFactoryInterface $logger_factory) {
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
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
   * Read the current instructions from the chatbot's AI Assistant.
   *
   * @return string|null
   *   The assistant instructions, or NULL if the assistant cannot be loaded.
   */
  public function readInstructions(): ?string {
    $assistant = $this->loadAssistant();
    if (!$assistant) {
      return NULL;
    }

    return $assistant->get('instructions');
  }

  /**
   * Write instructions to the chatbot's AI Assistant.
   *
   * Stores the raw instructions on the assistant's `instructions` field; the
   * AI runner consumes this value directly, so no escaping is applied.
   *
   * @param string $instructions
   *   The instructions to store on the assistant.
   *
   * @return bool
   *   TRUE if the assistant was updated, FALSE if it could not be loaded.
   */
  public function writeInstructions(string $instructions): bool {
    $assistant = $this->loadAssistant();
    if (!$assistant) {
      $this->logger->warning('Could not update chatbot system instructions: AI Assistant "@id" not found.', [
        '@id' => $this->getAssistantId(),
      ]);
      return FALSE;
    }

    $assistant->set('instructions', $instructions);
    $assistant->save();

    return TRUE;
  }

  /**
   * Load the configured AI Assistant config entity.
   *
   * @return \Drupal\Core\Config\Entity\ConfigEntityInterface|null
   *   The assistant entity, or NULL if it does not exist.
   */
  protected function loadAssistant() {
    return $this->entityTypeManager
      ->getStorage('ai_assistant')
      ->load($this->getAssistantId());
  }

}
