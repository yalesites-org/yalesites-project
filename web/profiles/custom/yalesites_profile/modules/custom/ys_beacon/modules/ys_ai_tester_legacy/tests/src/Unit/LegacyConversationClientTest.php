<?php

declare(strict_types=1);

namespace Drupal\Tests\ys_ai_tester_legacy\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_ai_tester_legacy\LegacyConversationClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;

/**
 * Tests the HTTP adapter for the legacy Azure conversation endpoint.
 *
 * @coversDefaultClass \Drupal\ys_ai_tester_legacy\LegacyConversationClient
 * @group ys_beacon
 */
class LegacyConversationClientTest extends UnitTestCase {

  /**
   * Builds the client under test.
   *
   * @param bool $module_exists
   *   Whether ai_engine_chat reports as installed.
   * @param string|null $azure_base_url
   *   The configured base URL.
   * @param \GuzzleHttp\ClientInterface|null $http_client
   *   The HTTP client, or NULL for one that must never be called.
   * @param bool $strict_config
   *   When TRUE, assert azure_base_url is the only config key ever read.
   *
   * @return \Drupal\ys_ai_tester_legacy\LegacyConversationClient
   *   The client.
   */
  private function client(
    bool $module_exists,
    ?string $azure_base_url,
    ?ClientInterface $http_client = NULL,
    bool $strict_config = FALSE,
  ): LegacyConversationClient {
    $config = $this->createMock(ImmutableConfig::class);
    $get = $config->method('get')->willReturn($azure_base_url);
    if ($strict_config) {
      // Proves availability never depends on ai_engine_chat's "enable" flag,
      // which cutover sets to FALSE while leaving azure_base_url in place.
      $get->with('azure_base_url');
    }

    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')
      ->with('ai_engine_chat.settings')
      ->willReturn($config);

    $module_handler = $this->createMock(ModuleHandlerInterface::class);
    $module_handler->method('moduleExists')
      ->with('ai_engine_chat')
      ->willReturn($module_exists);

    $uuid = $this->createMock(UuidInterface::class);
    $uuid->method('generate')->willReturn('fixed-uuid');

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1700000000);

    return new LegacyConversationClient(
      $http_client ?? $this->neverCalledHttpClient(),
      $config_factory,
      $module_handler,
      $uuid,
      $time,
    );
  }

  /**
   * Builds an HTTP client that fails the test if it is used.
   */
  private function neverCalledHttpClient(): ClientInterface {
    $http_client = $this->createMock(ClientInterface::class);
    $http_client->expects($this->never())->method('request');
    return $http_client;
  }

  /**
   * Builds a response whose body is the given NDJSON.
   */
  private function response(string $body): ResponseInterface {
    $response = $this->createMock(ResponseInterface::class);
    $response->method('getBody')->willReturn(Utils::streamFor($body));
    return $response;
  }

  /**
   * @covers ::isConfigured
   */
  public function testNotConfiguredWhenModuleIsUninstalled(): void {
    $this->assertFalse($this->client(FALSE, 'https://example.com')->isConfigured());
  }

  /**
   * @covers ::isConfigured
   */
  public function testNotConfiguredWhenBaseUrlIsEmpty(): void {
    $this->assertFalse($this->client(TRUE, '')->isConfigured());
  }

  /**
   * @covers ::isConfigured
   */
  public function testNotConfiguredWhenBaseUrlIsMissing(): void {
    $this->assertFalse($this->client(TRUE, NULL)->isConfigured());
  }

  /**
   * @covers ::isConfigured
   */
  public function testNotConfiguredWhenBaseUrlIsOnlyWhitespace(): void {
    $this->assertFalse($this->client(TRUE, "  \n ")->isConfigured());
  }

  /**
   * Tests the primary cutover case: chat switched off, base URL still set.
   *
   * A cut-over site has ai_engine_chat.settings:enable = FALSE. The legacy
   * option must stay available anyway, so availability reads only the base URL.
   *
   * @covers ::isConfigured
   */
  public function testConfiguredOnCutOverSiteWithOnlyTheBaseUrl(): void {
    $client = $this->client(TRUE, 'https://askyale.example.com', NULL, TRUE);

    $this->assertTrue($client->isConfigured());
  }

  /**
   * @covers ::ask
   */
  public function testAskRefusesWhenNotConfigured(): void {
    $client = $this->client(TRUE, '');

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('legacy ai_engine chat endpoint is not configured');

    $client->ask('Any question?');
  }

  /**
   * Tests the request contract the Azure app expects, plus the timeouts.
   *
   * @covers ::ask
   */
  public function testAskPostsTheWidgetRequestShapeWithExplicitTimeouts(): void {
    $captured = [];
    $http_client = $this->createMock(ClientInterface::class);
    $http_client->expects($this->once())
      ->method('request')
      ->willReturnCallback(function (string $method, string $uri, array $options) use (&$captured): ResponseInterface {
        $captured = ['method' => $method, 'uri' => $uri, 'options' => $options];
        return $this->response(json_encode([
          'choices' => [['messages' => [['role' => 'assistant', 'content' => 'Answer.']]]],
        ]));
      });

    // A trailing slash on the configured base URL must not double up.
    $client = $this->client(TRUE, 'https://askyale.example.com/', $http_client);
    $result = $client->ask('What are the office hours?');

    $this->assertSame('POST', $captured['method']);
    $this->assertSame('https://askyale.example.com/conversation', $captured['uri']);
    $this->assertSame(
      [
        [
          'id' => 'fixed-uuid',
          'role' => 'user',
          'content' => 'What are the office hours?',
          'date' => '2023-11-14T22:13:20Z',
        ],
      ],
      $captured['options']['json']['messages']
    );
    $this->assertSame(
      LegacyConversationClient::REQUEST_TIMEOUT,
      $captured['options']['timeout'],
      'One hung request must not be able to stall the whole batch.'
    );
    $this->assertSame(
      LegacyConversationClient::CONNECT_TIMEOUT,
      $captured['options']['connect_timeout']
    );
    $this->assertSame('Answer.', $result['answer']);
  }

  /**
   * @covers ::ask
   */
  public function testAskReturnsParsedAnswerAndCitations(): void {
    $body = json_encode([
      'choices' => [
        [
          'messages' => [
            [
              'role' => 'tool',
              'content' => json_encode([
                'citations' => [['title' => 'Handbook', 'url' => 'https://example.com/h']],
                'intent' => 'hours',
              ]),
            ],
            ['role' => 'assistant', 'content' => 'See the handbook. [doc1]'],
          ],
        ],
      ],
    ]);

    $http_client = $this->createMock(ClientInterface::class);
    $http_client->method('request')->willReturn($this->response($body));

    $result = $this->client(TRUE, 'https://askyale.example.com', $http_client)->ask('Hours?');

    $this->assertSame('See the handbook. [doc1]', $result['answer']);
    $this->assertSame('Handbook', $result['citations'][0]['title']);
  }

  /**
   * A transport failure surfaces as a readable error, not a raw Guzzle trace.
   *
   * @covers ::ask
   */
  public function testAskWrapsTransportFailures(): void {
    $http_client = $this->createMock(ClientInterface::class);
    $http_client->method('request')
      ->willThrowException(new TransferException('cURL error 28: Operation timed out'));

    $client = $this->client(TRUE, 'https://askyale.example.com', $http_client);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('The legacy ai_engine request failed: cURL error 28');

    $client->ask('Hours?');
  }

}
