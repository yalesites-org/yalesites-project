<?php

declare(strict_types=1);

namespace Drupal\Tests\ys_portkey\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Tests the ys_portkey post_update migration hook.
 *
 * @group ys_portkey
 */
class PortkeyMigrationTest extends KernelTestBase {

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['ai', 'ys_portkey']);

    require_once \Drupal::service('extension.list.module')
      ->getPath('ys_portkey') . '/ys_portkey.post_update.php';
  }

  /**
   * Sets up old-style ys_portkey.settings config.
   */
  protected function setOldConfig(string $model, string $api_key = 'test_key', string $gateway_url = 'https://api.portkey.ai/v1', string $custom_headers = ''): void {
    $config = \Drupal::configFactory()->getEditable('ys_portkey.settings');
    $config
      ->set('api_key', $api_key)
      ->set('gateway_url', $gateway_url)
      ->set('model', $model)
      ->set('custom_headers', $custom_headers)
      ->save();
  }

  /**
   * Tests migration creates chat and embeddings model entries.
   */
  public function testMigrationWithExistingConfig(): void {
    $this->setOldConfig('test-model', 'my_key', 'https://custom.gw/v1', 'x-header: val');

    $sandbox = [];
    ys_portkey_post_update_migrate_to_multi_instance($sandbox);

    $models = \Drupal::config('ai.settings')->get('models.portkey');
    $this->assertNotNull($models);
    $this->assertArrayHasKey('chat', $models);
    $this->assertArrayHasKey('embeddings', $models);
    $this->assertArrayHasKey('test-model', $models['chat']);
    $this->assertArrayHasKey('test-model', $models['embeddings']);

    $chat = $models['chat']['test-model'];
    $this->assertEquals('test-model', $chat['model_id']);
    $this->assertEquals('my_key', $chat['api_key']);
    $this->assertEquals('https://custom.gw/v1', $chat['gateway_url']);
    $this->assertEquals('x-header: val', $chat['custom_headers']);
    $this->assertEquals('portkey', $chat['provider']);
    $this->assertEquals('chat', $chat['operation_type']);

    $embed = $models['embeddings']['test-model'];
    $this->assertEquals('test-model', $embed['model_id']);
    $this->assertEquals('embeddings', $embed['operation_type']);
  }

  /**
   * Tests that model names with special characters get sanitized.
   */
  public function testMigrationSanitizesModelId(): void {
    $this->setOldConfig('@gcp/anthropic.claude-haiku@20251001');

    $sandbox = [];
    ys_portkey_post_update_migrate_to_multi_instance($sandbox);

    $models = \Drupal::config('ai.settings')->get('models.portkey');
    $sanitized = '-gcp-anthropic-claude-haiku-20251001';

    $this->assertArrayHasKey($sanitized, $models['chat']);
    $this->assertArrayHasKey($sanitized, $models['embeddings']);
    $this->assertEquals($sanitized, $models['chat'][$sanitized]['model_id']);
  }

  /**
   * Tests that original model name is preserved as label.
   */
  public function testMigrationPreservesLabel(): void {
    $original = '@gcp/anthropic.claude-haiku@20251001';
    $this->setOldConfig($original);

    $sandbox = [];
    ys_portkey_post_update_migrate_to_multi_instance($sandbox);

    $models = \Drupal::config('ai.settings')->get('models.portkey');
    $sanitized = '-gcp-anthropic-claude-haiku-20251001';

    $this->assertEquals($original, $models['chat'][$sanitized]['label']);
    $this->assertEquals($original, $models['embeddings'][$sanitized]['label']);
  }

  /**
   * Tests that old config keys are cleaned up after migration.
   */
  public function testMigrationCleansOldConfig(): void {
    $this->setOldConfig('test-model', 'my_key', 'https://custom.gw/v1', 'x-header: val');

    $sandbox = [];
    ys_portkey_post_update_migrate_to_multi_instance($sandbox);

    $old_config = \Drupal::config('ys_portkey.settings');
    $this->assertNull($old_config->get('api_key'));
    $this->assertNull($old_config->get('gateway_url'));
    $this->assertNull($old_config->get('model'));
    $this->assertNull($old_config->get('custom_headers'));
    $this->assertEquals('', $old_config->get('data'));
  }

  /**
   * Tests migration with empty model creates no entries but still cleans up.
   */
  public function testMigrationWithEmptyModel(): void {
    $config = \Drupal::configFactory()->getEditable('ys_portkey.settings');
    $config
      ->set('api_key', 'some_key')
      ->set('gateway_url', 'https://api.portkey.ai/v1')
      ->set('model', '')
      ->set('custom_headers', '')
      ->save();

    $sandbox = [];
    ys_portkey_post_update_migrate_to_multi_instance($sandbox);

    $models = \Drupal::config('ai.settings')->get('models.portkey');
    $this->assertNull($models);

    $old_config = \Drupal::config('ys_portkey.settings');
    $this->assertNull($old_config->get('api_key'));
    $this->assertEquals('', $old_config->get('data'));
  }

  /**
   * Tests migration uses default gateway URL when none configured.
   */
  public function testMigrationDefaultGatewayUrl(): void {
    $config = \Drupal::configFactory()->getEditable('ys_portkey.settings');
    $config
      ->set('api_key', 'key')
      ->set('gateway_url', '')
      ->set('model', 'test-model')
      ->set('custom_headers', '')
      ->save();

    $sandbox = [];
    ys_portkey_post_update_migrate_to_multi_instance($sandbox);

    $models = \Drupal::config('ai.settings')->get('models.portkey');
    $this->assertEquals('https://api.portkey.ai/v1', $models['chat']['test-model']['gateway_url']);
  }

}
