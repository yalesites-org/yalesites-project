<?php

declare(strict_types=1);

namespace Drupal\Tests\ys_beacon_portkey\Unit;

use Drupal\ai\Exception\AiQuotaException;
use Drupal\ai\Exception\AiRateLimitException;
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

}
