<?php

namespace Drupal\ys_portkey\Plugin\AiProvider;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\AiProvider;
use Drupal\ai\Base\AiProviderClientBase;
use Drupal\ai\Enum\AiProviderCapability;
use Drupal\ai\Exception\AiBadRequestException;
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
   * Models are user-created, not predefined.
   *
   * @var bool
   */
  protected bool $hasPredefinedModels = FALSE;

  /**
   * Maximum number of retry attempts for rate-limited requests.
   */
  const MAX_RETRIES = 3;

  /**
   * Base delay in seconds for exponential backoff (doubles each attempt).
   */
  const RETRY_BASE_DELAY = 2;

  /**
   * {@inheritdoc}
   */
  public function isUsable(?string $operation_type = NULL, array $capabilities = []): bool {
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
   * {@inheritdoc}
   */
  public function loadModelsForm(array $form, $form_state, string $operation_type, string|NULL $model_id = NULL): array {
    $form = parent::loadModelsForm($form, $form_state, $operation_type, $model_id);
    $config = $this->loadModelConfig($operation_type, $model_id);

    $form['model_data']['api_key'] = [
      '#type' => 'key_select',
      '#title' => new TranslatableMarkup('Portkey API Key'),
      '#description' => new TranslatableMarkup('Select a Key entity containing your Portkey API key. This is sent as the x-portkey-api-key header.'),
      '#default_value' => $config['api_key'] ?? '',
      '#required' => TRUE,
      '#weight' => 2,
    ];

    $form['model_data']['gateway_url'] = [
      '#type' => 'textfield',
      '#title' => new TranslatableMarkup('Gateway URL'),
      '#description' => new TranslatableMarkup('The Portkey gateway endpoint. Change only for self-hosted Portkey deployments.'),
      '#default_value' => $config['gateway_url'] ?? 'https://api.portkey.ai/v1',
      '#weight' => 3,
    ];

    $form['model_data']['custom_headers'] = [
      '#type' => 'textarea',
      '#title' => new TranslatableMarkup('Custom Headers'),
      '#description' => new TranslatableMarkup('Additional HTTP headers sent with every API request. One per line in "Header-Name: value" format. Use [key:key_name] to reference a Key module key.'),
      '#default_value' => $config['custom_headers'] ?? '',
      '#weight' => 50,
    ];

    return $form;
  }

  /**
   * Builds the OpenAI-compatible client for a specific model instance.
   *
   * @param array $model_config
   *   The model configuration from ai.settings.
   */
  protected function loadClient(array $model_config): void {
    $this->client = NULL;
    $gateway_url = $model_config['gateway_url'] ?? 'https://api.portkey.ai/v1';
    if (empty($gateway_url)) {
      $gateway_url = 'https://api.portkey.ai/v1';
    }

    $client = \OpenAI::factory()
      ->withApiKey('portkey')
      ->withBaseUri($gateway_url)
      ->withHttpClient($this->httpClient);

    $api_key_id = $model_config['api_key'] ?? '';
    if ($api_key_id) {
      $key = $this->keyRepository->getKey($api_key_id);
      if ($key) {
        $client->withHttpHeader('x-portkey-api-key', $key->getKeyValue());
      }
    }

    $custom_headers = $model_config['custom_headers'] ?? '';
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

  /**
   * Executes an API call with retry and exponential backoff on rate limits.
   *
   * @param callable $operation
   *   The API call to execute.
   *
   * @return mixed
   *   The result of the operation.
   *
   * @throws \Exception
   *   The last exception if all retries are exhausted.
   */
  protected function executeWithRetry(callable $operation): mixed {
    $lastException = NULL;
    for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
      try {
        return $operation();
      }
      catch (\Exception $e) {
        $isRateLimit = str_contains($e->getMessage(), 'Too Many Requests')
          || str_contains($e->getMessage(), '429');
        if ($isRateLimit && $attempt < self::MAX_RETRIES) {
          $lastException = $e;
          sleep(self::RETRY_BASE_DELAY * (int) pow(2, $attempt));
          continue;
        }
        throw $e;
      }
    }
    throw $lastException;
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
    $info = $this->getModelInfo('chat', $model_id);
    if (empty($info['api_key'])) {
      throw new AiBadRequestException('The model does not exist.');
    }
    $this->loadClient($info);

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
        $content = [];
        if ($message->getText() !== '') {
          $content[] = [
            'type' => 'text',
            'text' => $message->getText(),
          ];
        }
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
          'content' => !empty($content) ? $content : NULL,
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
        $response = $this->executeWithRetry(
          fn() => $this->client->chat()->createStreamed($payload)
        );
        $message = new PortkeyChatMessageIterator($response);
      }
      else {
        $response = $this->executeWithRetry(
          fn() => $this->client->chat()->create($payload)->toArray()
        );
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
      if (str_contains($e->getMessage(), 'Request too large')) {
        throw new AiRateLimitException($e->getMessage());
      }
      if (str_contains($e->getMessage(), 'Too Many Requests')) {
        throw new AiRateLimitException($e->getMessage());
      }
      if (str_contains($e->getMessage(), 'You exceeded your current quota')) {
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
    $info = $this->getModelInfo('embeddings', $model_id);
    if (empty($info['api_key'])) {
      throw new AiBadRequestException('The model does not exist.');
    }
    $this->loadClient($info);

    if ($input instanceof EmbeddingsInput) {
      $input = $input->getPrompt();
    }
    $payload = [
      'model' => $model_id,
      'input' => $input,
    ];
    if (!empty($info['dimensions'])) {
      $payload['dimensions'] = (int) $info['dimensions'];
    }
    $payload += $this->configuration;
    try {
      $response = $this->executeWithRetry(
        fn() => $this->client->embeddings()->create($payload)->toArray()
      );
    }
    catch (\Exception $e) {
      if (str_contains($e->getMessage(), 'Request too large')) {
        throw new AiRateLimitException($e->getMessage());
      }
      if (str_contains($e->getMessage(), 'Too Many Requests')) {
        throw new AiRateLimitException($e->getMessage());
      }
      if (str_contains($e->getMessage(), 'You exceeded your current quota')) {
        throw new AiQuotaException($e->getMessage());
      }
      throw $e;
    }

    return new EmbeddingsOutput($response['data'][0]['embedding'], $response, []);
  }

  /**
   * {@inheritdoc}
   */
  public function maxEmbeddingsInput(string $model_id = ''): int {
    return 8191;
  }

  /**
   * {@inheritdoc}
   */
  public function embeddingsVectorSize(string $model_id): int {
    $info = $this->getModelInfo('embeddings', $model_id);
    if (!empty($info['dimensions'])) {
      return (int) $info['dimensions'];
    }
    return 0;
  }

}
