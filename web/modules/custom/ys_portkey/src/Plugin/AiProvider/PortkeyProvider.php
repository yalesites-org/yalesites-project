<?php

namespace Drupal\ys_portkey\Plugin\AiProvider;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\AiProvider;
use Drupal\ai\Base\AiProviderClientBase;
use Drupal\ai\Enum\AiProviderCapability;
use Drupal\ai\Exception\AiQuotaException;
use Drupal\ai\Exception\AiRateLimitException;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatInterface;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionOutput;
use Drupal\ai\OperationType\Embeddings\EmbeddingsInput;
use Drupal\ai\OperationType\Embeddings\EmbeddingsInterface;
use Drupal\ai\OperationType\Embeddings\EmbeddingsOutput;
use Drupal\ai\Traits\OperationType\ChatTrait;
use Drupal\ys_portkey\PortkeyChatMessageIterator;
use OpenAI\Client;
use Symfony\Component\Yaml\Yaml;

/**
 * Plugin implementation of the 'portkey' provider.
 */
#[AiProvider(
  id: 'portkey',
  label: new TranslatableMarkup('Portkey'),
)]
class PortkeyProvider extends AiProviderClientBase implements
  ContainerFactoryPluginInterface,
  ChatInterface,
  EmbeddingsInterface {

  use ChatTrait;

  /**
   * The OpenAI Client.
   *
   * @var \OpenAI\Client|null
   */
  protected $client;

  /**
   * API Key.
   *
   * @var string
   */
  protected string $apiKey = '';

  /**
   * {@inheritdoc}
   */
  public function getConfiguredModels(?string $operation_type = NULL, array $capabilities = []): array {
    return $this->getModels($operation_type ?? 'chat', $capabilities);
  }

  /**
   * {@inheritdoc}
   */
  public function isUsable(?string $operation_type = NULL, array $capabilities = []): bool {
    if (!$this->getConfig()->get('api_key')) {
      return FALSE;
    }
    if ($operation_type) {
      return in_array($operation_type, $this->getSupportedOperationTypes());
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedOperationTypes(): array {
    return [
      'chat',
      'embeddings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedCapabilities(): array {
    return [
      AiProviderCapability::StreamChatOutput,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getConfig(): ImmutableConfig {
    return $this->configFactory->get('ys_portkey.settings');
  }

  /**
   * {@inheritdoc}
   */
  public function getApiDefinition(): array {
    $definition = Yaml::parseFile($this->moduleHandler->getModule('ys_portkey')->getPath() . '/definitions/api_defaults.yml');
    return $definition;
  }

  /**
   * {@inheritdoc}
   */
  public function getModelSettings(string $model_id, array $generalConfig = []): array {
    return $generalConfig;
  }

  /**
   * {@inheritdoc}
   */
  public function setAuthentication(mixed $authentication): void {
    $this->apiKey = $authentication;
    $this->client = NULL;
  }

  /**
   * Loads the OpenAI-compatible client pointed at the Portkey gateway.
   */
  protected function loadClient(): void {
    if (!$this->client) {
      $config = $this->getConfig();
      $gateway_url = $config->get('gateway_url') ?: 'https://api.portkey.ai/v1';

      // The SDK requires an API key, but Portkey auth is via the
      // x-portkey-api-key header. Pass a dummy value to satisfy the SDK.
      $client = \OpenAI::factory()
        ->withApiKey('portkey')
        ->withBaseUri($gateway_url)
        ->withHttpClient($this->httpClient);

      // Add the Portkey API key header from Key module.
      $api_key_id = $config->get('api_key');
      if ($api_key_id) {
        $key = $this->keyRepository->getKey($api_key_id);
        if ($key) {
          $client->withHttpHeader('x-portkey-api-key', $key->getKeyValue());
        }
      }

      // Apply any custom headers.
      $custom_headers = $config->get('custom_headers');
      if (!empty($custom_headers)) {
        foreach (explode("\n", $custom_headers) as $line) {
          $line = trim($line);
          if ($line !== '' && str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $value = $this->resolveKeyPlaceholders(trim($value));
            $client->withHttpHeader(trim($name), $value);
          }
        }
      }

      $this->client = $client->make();
    }
  }

  /**
   * Resolves [key:key_name] placeholders in a string using the Key module.
   *
   * @param string $value
   *   The string that may contain [key:key_name] tokens.
   *
   * @return string
   *   The string with placeholders replaced by their key values.
   */
  protected function resolveKeyPlaceholders(string $value): string {
    return preg_replace_callback('/\[key:([^\]]+)\]/', function ($matches) {
      $key = $this->keyRepository->getKey($matches[1]);
      return $key ? ($key->getKeyValue() ?? $matches[0]) : $matches[0];
    }, $value);
  }

  /**
   * {@inheritdoc}
   */
  public function chat(array|string|ChatInput $input, string $model_id, array $tags = []): ChatOutput {
    $this->loadClient();
    $chat_input = $input;
    if ($input instanceof ChatInput) {
      $chat_input = [];
      if ($this->chatSystemRole) {
        $chat_input[] = [
          'role' => 'system',
          'content' => $this->chatSystemRole,
        ];
      }
      /** @var \Drupal\ai\OperationType\Chat\ChatMessage $message */
      foreach ($input->getMessages() as $message) {
        $content = [
          [
            'type' => 'text',
            'text' => $message->getText(),
          ],
        ];
        if (count($message->getImages())) {
          foreach ($message->getImages() as $image) {
            $content[] = [
              'type' => 'image_url',
              'image_url' => [
                'url' => $image->getAsBase64EncodedString(),
              ],
            ];
          }
        }
        $new_message = [
          'role' => $message->getRole(),
          'content' => $content,
        ];

        if ($message->getToolsId()) {
          $new_message['tool_call_id'] = $message->getToolsId();
        }

        if ($message->getTools()) {
          $new_message['tool_calls'] = $message->getRenderedTools();
        }

        $chat_input[] = $new_message;
      }
    }

    $payload = [
      'model' => $model_id,
      'messages' => $chat_input,
    ] + $this->configuration;

    // Some backends reject both temperature and top_p simultaneously.
    if (isset($payload['temperature']) && isset($payload['top_p'])) {
      unset($payload['top_p']);
    }

    if (is_object($input) && method_exists($input, 'getChatTools') && $input->getChatTools()) {
      $payload['tools'] = $input->getChatTools()->renderToolsArray();
      foreach ($payload['tools'] as $key => $tool) {
        $payload['tools'][$key]['function']['strict'] = FALSE;
      }
    }

    if (is_object($input) && method_exists($input, 'getChatStructuredJsonSchema') && $input->getChatStructuredJsonSchema()) {
      $payload['response_format'] = [
        'type' => 'json_schema',
        'json_schema' => $input->getChatStructuredJsonSchema(),
      ];
    }

    try {
      if ($this->streamed) {
        $response = $this->client->chat()->createStreamed($payload);
        $message = new PortkeyChatMessageIterator($response);
      }
      else {
        $response = $this->client->chat()->create($payload)->toArray();
        $tools = [];
        if (!empty($response['choices'][0]['message']['tool_calls'])) {
          foreach ($response['choices'][0]['message']['tool_calls'] as $tool) {
            $arguments = Json::decode($tool['function']['arguments']);
            $tools[] = new ToolsFunctionOutput($input->getChatTools()->getFunctionByName($tool['function']['name']), $tool['id'], $arguments);
          }
        }
        $message = new ChatMessage($response['choices'][0]['message']['role'], $response['choices'][0]['message']['content'] ?? "", []);
        if (!empty($tools)) {
          $message->setTools($tools);
        }
      }
    }
    catch (\Exception $e) {
      if (strpos($e->getMessage(), 'Request too large') !== FALSE) {
        throw new AiRateLimitException($e->getMessage());
      }
      if (strpos($e->getMessage(), 'Too Many Requests') !== FALSE) {
        throw new AiRateLimitException($e->getMessage());
      }
      if (strpos($e->getMessage(), 'You exceeded your current quota') !== FALSE) {
        throw new AiQuotaException($e->getMessage());
      }
      throw $e;
    }

    return new ChatOutput($message, $response, []);
  }

  /**
   * {@inheritdoc}
   */
  public function embeddings(string|EmbeddingsInput $input, string $model_id, array $tags = []): EmbeddingsOutput {
    $this->loadClient();
    if ($input instanceof EmbeddingsInput) {
      $input = $input->getPrompt();
    }
    $payload = [
      'model' => $model_id,
      'input' => $input,
    ] + $this->configuration;
    try {
      $response = $this->client->embeddings()->create($payload)->toArray();
    }
    catch (\Exception $e) {
      if (strpos($e->getMessage(), 'Request too large') !== FALSE) {
        throw new AiRateLimitException($e->getMessage());
      }
      if (strpos($e->getMessage(), 'Too Many Requests') !== FALSE) {
        throw new AiRateLimitException($e->getMessage());
      }
      if (strpos($e->getMessage(), 'You exceeded your current quota') !== FALSE) {
        throw new AiQuotaException($e->getMessage());
      }
      throw $e;
    }

    return new EmbeddingsOutput($response['data'][0]['embedding'], $response, []);
  }

  /**
   * {@inheritdoc}
   */
  public function getSetupData(): array {
    $model = $this->getConfig()->get('model');
    if (empty($model)) {
      return [];
    }
    return [
      'key_config_name' => 'api_key',
      'default_models' => [
        'chat' => $model,
        'embeddings' => $model,
      ],
    ];
  }

  /**
   * Returns available models. Always the single configured model.
   */
  public function getModels(string $operation_type, $capabilities): array {
    $model = $this->getConfig()->get('model');
    if (empty($model)) {
      return [];
    }
    return [$model => $model];
  }

  /**
   * {@inheritdoc}
   */
  public function embeddingsVectorSize(string $model_id): int {
    return 0;
  }

}
