<?php

declare(strict_types=1);

namespace Drupal\Tests\ys_portkey\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\ai\Enum\AiProviderCapability;
use Drupal\ai\Exception\AiRequestErrorException;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Embeddings\EmbeddingsInput;
use Drupal\ai\Plugin\ProviderProxy;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests the Portkey AI provider plugin.
 *
 * @group ys_portkey
 */
class PortkeyProviderTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'ai',
    'ys_portkey',
    'key',
    'system',
  ];

  /**
   * The AI provider plugin manager.
   *
   * @var \Drupal\ai\AiProviderPluginManager
   */
  protected $providerManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['ai', 'ys_portkey']);
    $this->providerManager = \Drupal::service('ai.provider');
  }

  /**
   * Creates a Portkey provider instance.
   */
  protected function createProvider(): ProviderProxy {
    return $this->providerManager->createInstance('portkey');
  }

  /**
   * Adds a model entry to ai.settings config.
   */
  protected function addModelConfig(string $operation_type, string $model_id, array $overrides = []): void {
    $config = \Drupal::configFactory()->getEditable('ai.settings');
    $models = $config->get('models') ?? [];
    $models['portkey'][$operation_type][$model_id] = [
      'model_id' => $model_id,
      'label' => $overrides['label'] ?? $model_id,
      'api_key' => $overrides['api_key'] ?? 'test_key',
      'gateway_url' => $overrides['gateway_url'] ?? 'https://api.portkey.ai/v1',
      'custom_headers' => $overrides['custom_headers'] ?? '',
      'provider' => 'portkey',
      'operation_type' => $operation_type,
    ] + $overrides;
    $config->set('models', $models)->save();
  }

  /**
   * Tests that the provider can be discovered and instantiated.
   */
  public function testProviderDiscovery(): void {
    $provider = $this->createProvider();
    $this->assertInstanceOf(ProviderProxy::class, $provider);
  }

  /**
   * Tests that hasPredefinedModels returns FALSE.
   */
  public function testHasPredefinedModelsFalse(): void {
    $provider = $this->createProvider();
    $this->assertFalse($provider->hasPredefinedModels());
  }

  /**
   * Tests isUsable for various operation types.
   */
  public function testIsUsable(): void {
    $provider = $this->createProvider();
    $this->assertTrue($provider->isUsable());
    $this->assertTrue($provider->isUsable('chat'));
    $this->assertTrue($provider->isUsable('embeddings'));
    $this->assertFalse($provider->isUsable('text_to_image'));
    $this->assertFalse($provider->isUsable('speech_to_text'));
  }

  /**
   * Tests getSupportedOperationTypes returns exactly chat and embeddings.
   */
  public function testSupportedOperationTypes(): void {
    $provider = $this->createProvider();
    $types = $provider->getSupportedOperationTypes();
    $this->assertCount(2, $types);
    $this->assertContains('chat', $types);
    $this->assertContains('embeddings', $types);
  }

  /**
   * Tests getSupportedCapabilities includes streaming.
   */
  public function testSupportedCapabilities(): void {
    $provider = $this->createProvider();
    $capabilities = $provider->getSupportedCapabilities();
    $this->assertContains(AiProviderCapability::StreamChatOutput, $capabilities);
  }

  /**
   * Tests getConfiguredModels returns empty when no models configured.
   */
  public function testGetConfiguredModelsEmpty(): void {
    $provider = $this->createProvider();
    $models = $provider->getConfiguredModels('chat');
    $this->assertEmpty($models);
  }

  /**
   * Tests getConfiguredModels returns models after adding to config.
   */
  public function testGetConfiguredModelsFromConfig(): void {
    $this->addModelConfig('chat', 'test-chat', ['label' => 'Test Chat']);
    $this->addModelConfig('embeddings', 'test-embed', ['label' => 'Test Embed']);

    $provider = $this->createProvider();

    $chat_models = $provider->getConfiguredModels('chat');
    $this->assertArrayHasKey('test-chat', $chat_models);
    $this->assertEquals('Test Chat', $chat_models['test-chat']);

    $embed_models = $provider->getConfiguredModels('embeddings');
    $this->assertArrayHasKey('test-embed', $embed_models);
    $this->assertEquals('Test Embed', $embed_models['test-embed']);
  }

  /**
   * Tests that chat and embeddings models are independent.
   */
  public function testModelsArePerOperationType(): void {
    $this->addModelConfig('chat', 'chat-model');
    $this->addModelConfig('embeddings', 'embed-model');

    $provider = $this->createProvider();

    $chat_models = $provider->getConfiguredModels('chat');
    $embed_models = $provider->getConfiguredModels('embeddings');

    $this->assertArrayHasKey('chat-model', $chat_models);
    $this->assertArrayNotHasKey('embed-model', $chat_models);
    $this->assertArrayHasKey('embed-model', $embed_models);
    $this->assertArrayNotHasKey('chat-model', $embed_models);
  }

  /**
   * Tests getModelInfo returns full config for existing model.
   */
  public function testGetModelInfo(): void {
    $this->addModelConfig('chat', 'my-chat', [
      'api_key' => 'my_key',
      'gateway_url' => 'https://custom.gateway/v1',
      'custom_headers' => 'x-custom: value',
    ]);

    $provider = $this->createProvider();
    $info = $provider->getModelInfo('chat', 'my-chat');

    $this->assertEquals('my-chat', $info['model_id']);
    $this->assertEquals('my_key', $info['api_key']);
    $this->assertEquals('https://custom.gateway/v1', $info['gateway_url']);
    $this->assertEquals('x-custom: value', $info['custom_headers']);
    $this->assertEquals('portkey', $info['provider']);
  }

  /**
   * Tests getModelInfo returns empty array for nonexistent model.
   */
  public function testGetModelInfoMissing(): void {
    $provider = $this->createProvider();
    $info = $provider->getModelInfo('chat', 'nonexistent');
    $this->assertEmpty($info);
  }

  /**
   * Tests that chat() throws when model doesn't exist.
   */
  public function testChatThrowsOnMissingModel(): void {
    $provider = $this->createProvider();
    $input = new ChatInput([new ChatMessage('user', 'Hello')]);

    $this->expectException(AiRequestErrorException::class);
    $provider->chat($input, 'nonexistent');
  }

  /**
   * Tests that embeddings() throws when model doesn't exist.
   */
  public function testEmbeddingsThrowsOnMissingModel(): void {
    $provider = $this->createProvider();
    $input = new EmbeddingsInput('Test text');

    $this->expectException(AiRequestErrorException::class);
    $provider->embeddings($input, 'nonexistent');
  }

  /**
   * Tests embeddingsVectorSize reads dimensions from model config.
   */
  public function testEmbeddingsVectorSizeFromConfig(): void {
    $this->addModelConfig('embeddings', 'with-dims', [
      'dimensions' => 1536,
    ]);

    $provider = $this->createProvider();
    $this->assertEquals(1536, $provider->embeddingsVectorSize('with-dims'));
  }

  /**
   * Tests embeddingsVectorSize returns 0 when no dimensions configured.
   */
  public function testEmbeddingsVectorSizeDefault(): void {
    $this->addModelConfig('embeddings', 'no-dims');

    $provider = $this->createProvider();
    $this->assertEquals(0, $provider->embeddingsVectorSize('no-dims'));
  }

  /**
   * Tests embeddingsVectorSize returns 0 for nonexistent model.
   */
  public function testEmbeddingsVectorSizeNoModel(): void {
    $provider = $this->createProvider();
    $this->assertEquals(0, $provider->embeddingsVectorSize('nonexistent'));
  }

  /**
   * Tests loadModelsForm for chat includes Portkey-specific fields.
   */
  public function testLoadModelsFormChatFields(): void {
    $provider = $this->createProvider();
    $form = [];
    $form_state = new FormState();
    $result = $provider->loadModelsForm($form, $form_state, 'chat');

    $this->assertArrayHasKey('model_data', $result);
    $model_data = $result['model_data'];

    $this->assertArrayHasKey('api_key', $model_data);
    $this->assertEquals('key_select', $model_data['api_key']['#type']);
    $this->assertTrue($model_data['api_key']['#required']);

    $this->assertArrayHasKey('gateway_url', $model_data);
    $this->assertEquals('textfield', $model_data['gateway_url']['#type']);
    $this->assertEquals('https://api.portkey.ai/v1', $model_data['gateway_url']['#default_value']);

    $this->assertArrayHasKey('custom_headers', $model_data);
    $this->assertEquals('textarea', $model_data['custom_headers']['#type']);
  }

  /**
   * Tests loadModelsForm for embeddings includes Portkey-specific fields.
   */
  public function testLoadModelsFormEmbeddingsFields(): void {
    $provider = $this->createProvider();
    $form = [];
    $form_state = new FormState();
    $result = $provider->loadModelsForm($form, $form_state, 'embeddings');

    $this->assertArrayHasKey('model_data', $result);
    $model_data = $result['model_data'];

    $this->assertArrayHasKey('api_key', $model_data);
    $this->assertArrayHasKey('gateway_url', $model_data);
    $this->assertArrayHasKey('custom_headers', $model_data);
  }

  /**
   * Tests loadModelsForm populates defaults from stored config.
   */
  public function testLoadModelsFormDefaultValues(): void {
    $this->addModelConfig('chat', 'my-chat', [
      'api_key' => 'saved_key',
      'gateway_url' => 'https://custom.gw/v1',
      'custom_headers' => 'x-foo: bar',
    ]);

    $provider = $this->createProvider();
    $form = [];
    $form_state = new FormState();
    $result = $provider->loadModelsForm($form, $form_state, 'chat', 'my-chat');

    $model_data = $result['model_data'];
    $this->assertEquals('saved_key', $model_data['api_key']['#default_value']);
    $this->assertEquals('https://custom.gw/v1', $model_data['gateway_url']['#default_value']);
    $this->assertEquals('x-foo: bar', $model_data['custom_headers']['#default_value']);
  }

  /**
   * Tests maxEmbeddingsInput returns expected value.
   */
  public function testMaxEmbeddingsInput(): void {
    $provider = $this->createProvider();
    $this->assertEquals(8191, $provider->maxEmbeddingsInput());
  }

  /**
   * Tests getApiDefinition returns chat and embeddings definitions.
   */
  public function testGetApiDefinition(): void {
    $provider = $this->createProvider();
    $definition = $provider->getApiDefinition();
    $this->assertArrayHasKey('chat', $definition);
    $this->assertArrayHasKey('embeddings', $definition);
  }

}
