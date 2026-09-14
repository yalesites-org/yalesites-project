<?php

declare(strict_types=1);

namespace Drupal\Tests\ys_beacon_portkey\Unit;

use Drupal\Core\Config\ImmutableConfig;
use Drupal\ai\Base\AiProviderClientBase;
use Drupal\ai\Base\OpenAiBasedProviderClientBase;
use Drupal\ai\Exception\AiQuotaException;
use Drupal\ai\Exception\AiRateLimitException;
use Drupal\key\KeyInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_beacon_portkey\Plugin\AiProvider\PortkeyProvider;
use OpenAI\Exceptions\ErrorException;
use Psr\Http\Message\ResponseInterface;

/**
 * Tests Portkey API-error mapping by HTTP status code.
 *
 * @group ys_beacon
 * @coversDefaultClass \Drupal\ys_beacon_portkey\Plugin\AiProvider\PortkeyProvider
 */
class PortkeyProviderTest extends UnitTestCase {

  /**
   * Status codes map to the correct typed AI exception.
   *
   * @covers ::handleApiException
   * @dataProvider providerStatusMapping
   */
  public function testStatusMappedToException(int $status, ?string $errorCode, string $expected): void {
    $this->expectException($expected);
    $this->invokeHandleApiException($this->errorException($status, 'Upstream said no.', $errorCode));
  }

  /**
   * Data provider: HTTP status (+ optional error code) to expected exception.
   */
  public static function providerStatusMapping(): array {
    return [
      'rate limit' => [429, NULL, AiRateLimitException::class],
      'quota exhausted via 429 code' => [429, 'insufficient_quota', AiQuotaException::class],
      'request too large' => [413, NULL, AiRateLimitException::class],
      'payment required is quota' => [402, NULL, AiQuotaException::class],
    ];
  }

  /**
   * A status code with no special mapping is rethrown unchanged.
   *
   * @covers ::handleApiException
   */
  public function testUnmappedStatusIsRethrown(): void {
    $e = $this->errorException(500, 'Internal server error.', NULL);
    $this->expectExceptionObject($e);
    $this->invokeHandleApiException($e);
  }

  /**
   * Detection ignores message wording.
   *
   * A 429 whose body says nothing recognizable still maps to a rate limit.
   *
   * @covers ::handleApiException
   */
  public function testDetectionIgnoresMessageWording(): void {
    $this->expectException(AiRateLimitException::class);
    $this->invokeHandleApiException($this->errorException(429, 'totally novel gateway wording', NULL));
  }

  /**
   * Builds an openai-php ErrorException with a given HTTP status.
   */
  private function errorException(int $status, string $message, ?string $errorCode): ErrorException {
    $response = $this->createMock(ResponseInterface::class);
    $response->method('getStatusCode')->willReturn($status);
    $contents = ['message' => $message, 'type' => 'error'];
    if ($errorCode !== NULL) {
      $contents['code'] = $errorCode;
    }
    return new ErrorException($contents, $response);
  }

  /**
   * Invokes the protected handleApiException() without a full constructor.
   *
   * The method is pure (it reads only its argument), so a constructor-less
   * instance is sufficient and avoids wiring the provider's many services.
   */
  private function invokeHandleApiException(\Throwable $e): void {
    $provider = (new \ReflectionClass(PortkeyProvider::class))->newInstanceWithoutConstructor();
    $method = new \ReflectionMethod($provider, 'handleApiException');
    $method->setAccessible(TRUE);
    $method->invoke($provider, $e);
  }

  /**
   * The provider is unusable until an API key is configured.
   *
   * @covers ::isUsable
   */
  public function testNotUsableWithoutApiKey(): void {
    $this->assertFalse($this->provider([])->isUsable());
    $this->assertFalse($this->provider(['api_key' => ''])->isUsable());
  }

  /**
   * With an API key the provider is usable, but only for supported operations.
   *
   * @covers ::isUsable
   */
  public function testUsableForSupportedOperationsWithApiKey(): void {
    $provider = $this->provider(['api_key' => 'portkey-key']);
    $this->assertTrue($provider->isUsable());
    $this->assertTrue($provider->isUsable('chat'));
    $this->assertTrue($provider->isUsable('embeddings'));
    $this->assertFalse($provider->isUsable('moderation'));
  }

  /**
   * Embeddings authenticate with the dedicated embeddings key when set.
   *
   * @covers ::authenticateForOperation
   */
  public function testEmbeddingsUsesEmbeddingsKey(): void {
    $provider = $this->provider([
      'api_key' => 'chat-key-id',
      'embeddings_api_key' => 'embeddings-key-id',
    ]);
    $this->setKeyRepository($provider, [
      'chat-key-id' => 'CHAT_SECRET',
      'embeddings-key-id' => 'EMBED_SECRET',
    ]);

    $this->authenticate($provider, 'embeddings');

    $this->assertSame('EMBED_SECRET', $this->readApiKey($provider));
  }

  /**
   * Embeddings fall back to the chat key when no embeddings key is configured.
   *
   * @covers ::authenticateForOperation
   */
  public function testEmbeddingsFallBackToChatKey(): void {
    $provider = $this->provider(['api_key' => 'chat-key-id']);
    $this->setKeyRepository($provider, ['chat-key-id' => 'CHAT_SECRET']);

    $this->authenticate($provider, 'embeddings');

    $this->assertSame('CHAT_SECRET', $this->readApiKey($provider));
  }

  /**
   * Chat always authenticates with the chat key, never the embeddings key.
   *
   * @covers ::authenticateForOperation
   */
  public function testChatUsesChatKey(): void {
    $provider = $this->provider([
      'api_key' => 'chat-key-id',
      'embeddings_api_key' => 'embeddings-key-id',
    ]);
    $this->setKeyRepository($provider, [
      'chat-key-id' => 'CHAT_SECRET',
      'embeddings-key-id' => 'EMBED_SECRET',
    ]);

    $this->authenticate($provider, 'chat');

    $this->assertSame('CHAT_SECRET', $this->readApiKey($provider));
  }

  /**
   * Builds a provider with getConfig() stubbed to the given settings.
   *
   * The provider extends a service-heavy base class, so the constructor is
   * skipped and only getConfig() is stubbed - enough for isUsable() and the
   * key selection in authenticateForOperation().
   *
   * @param array $settings
   *   Config values keyed by name (e.g. api_key, embeddings_api_key).
   */
  private function provider(array $settings): PortkeyProvider {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(fn ($name) => $settings[$name] ?? NULL);
    $provider = $this->getMockBuilder(PortkeyProvider::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['getConfig'])
      ->getMock();
    $provider->method('getConfig')->willReturn($config);
    return $provider;
  }

  /**
   * Injects a key repository that resolves the given id => secret map.
   */
  private function setKeyRepository(PortkeyProvider $provider, array $keys): void {
    $repository = $this->createMock(KeyRepositoryInterface::class);
    $repository->method('getKey')->willReturnCallback(function ($id) use ($keys) {
      if (!isset($keys[$id])) {
        return NULL;
      }
      $key = $this->createMock(KeyInterface::class);
      $key->method('getKeyValue')->willReturn($keys[$id]);
      return $key;
    });
    $property = new \ReflectionProperty(AiProviderClientBase::class, 'keyRepository');
    $property->setAccessible(TRUE);
    $property->setValue($provider, $repository);
  }

  /**
   * Invokes the protected authenticateForOperation().
   */
  private function authenticate(PortkeyProvider $provider, string $operation): void {
    $method = new \ReflectionMethod($provider, 'authenticateForOperation');
    $method->setAccessible(TRUE);
    $method->invoke($provider, $operation);
  }

  /**
   * Reads the resolved API key set by authentication.
   */
  private function readApiKey(PortkeyProvider $provider): string {
    $property = new \ReflectionProperty(OpenAiBasedProviderClientBase::class, 'apiKey');
    $property->setAccessible(TRUE);
    return $property->getValue($provider);
  }

}
