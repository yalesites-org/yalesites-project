<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\key\KeyInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_beacon\Service\BeaconCredentials;
use Psr\Log\LoggerInterface;

/**
 * Tests endpoint-paired Azure AI Search API key resolution.
 *
 * The key for a service is resolved by the site's effective endpoint from a
 * single JSON map secret, so a site pinned to one Azure service always uses
 * that service's key even when another service is live
 * (yalesites-org/YaleSites-Internal#1448).
 *
 * @group ys_beacon
 * @coversDefaultClass \Drupal\ys_beacon\Service\BeaconCredentials
 */
class BeaconCredentialsTest extends UnitTestCase {

  /**
   * The endpoint's own key is returned when it is present in the map.
   *
   * @covers ::apiKeyForEndpoint
   */
  public function testReturnsKeyForMatchingEndpoint(): void {
    $map = json_encode([
      'https://a.search.windows.net' => 'KEY_A',
      'https://b.search.windows.net' => 'KEY_B',
    ]);
    $credentials = $this->buildCredentials($map, 'LEGACY');

    $this->assertSame('KEY_A', $credentials->apiKeyForEndpoint('https://a.search.windows.net'));
    $this->assertSame('KEY_B', $credentials->apiKeyForEndpoint('https://b.search.windows.net'));
  }

  /**
   * Endpoint and map keys are normalized the same way before matching.
   *
   * A scheme-less, differently-cased, or trailing-slashed value still matches
   * the authored map key, so an operator does not have to author URLs in one
   * exact canonical form.
   *
   * @covers ::apiKeyForEndpoint
   */
  public function testNormalizesEndpointAndMapKeysForMatching(): void {
    // Authored with mixed case and a trailing slash.
    $map = json_encode(['https://Beacon.Search.Windows.Net/' => 'KEY_A']);
    $credentials = $this->buildCredentials($map, NULL);

    // Looked up scheme-less and lower-cased: normalization makes them match.
    $this->assertSame('KEY_A', $credentials->apiKeyForEndpoint('beacon.search.windows.net'));
  }

  /**
   * A populated map that lacks the endpoint fails closed and logs.
   *
   * It must NOT fall back to the legacy key here: doing so would hand a pinned
   * site the wrong service's key once multiple services are live. The missing
   * endpoint is named in an actionable error so ops adds it to the map.
   *
   * @covers ::apiKeyForEndpoint
   */
  public function testMissingEndpointReturnsNullAndLogsError(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('error')
      ->with(
        $this->anything(),
        $this->callback(function (array $context): bool {
          return isset($context['@endpoint'])
            && str_contains((string) $context['@endpoint'], 'c.search.windows.net');
        }),
      );

    $map = json_encode(['https://a.search.windows.net' => 'KEY_A']);
    $credentials = $this->buildCredentials($map, 'LEGACY', $logger);

    $this->assertNull($credentials->apiKeyForEndpoint('https://c.search.windows.net'));
  }

  /**
   * A non-empty but malformed map fails closed and logs an error.
   *
   * @covers ::apiKeyForEndpoint
   * @dataProvider providerMalformedMap
   */
  public function testMalformedMapReturnsNullAndLogsError(string $raw): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('error');

    $credentials = $this->buildCredentials($raw, 'LEGACY', $logger);

    $this->assertNull($credentials->apiKeyForEndpoint('https://a.search.windows.net'));
  }

  /**
   * Values that are present but are not a JSON object of keys.
   */
  public static function providerMalformedMap(): array {
    return [
      'not json' => ['this is not json'],
      'json string scalar' => ['"just-a-string"'],
      'json number scalar' => ['12345'],
    ];
  }

  /**
   * An empty/absent map falls back to the legacy single key.
   *
   * This preserves the current single-service fleet before the map secret is
   * populated (zero-downtime rollout), and is the expected pre-rollout state -
   * so it is not treated as an error.
   *
   * @covers ::apiKeyForEndpoint
   * @dataProvider providerEmptyMap
   */
  public function testEmptyMapFallsBackToLegacyKey(?string $rawMap): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->never())->method('error');

    $credentials = $this->buildCredentials($rawMap, 'LEGACY_VALUE', $logger);

    $this->assertSame('LEGACY_VALUE', $credentials->apiKeyForEndpoint('https://anything.search.windows.net'));
  }

  /**
   * The map secret is either absent (no key entity) or present but blank.
   */
  public static function providerEmptyMap(): array {
    return [
      'no map key entity' => [NULL],
      'blank map value' => [''],
      'whitespace map value' => ['   '],
    ];
  }

  /**
   * With no map and no legacy key, resolution returns NULL.
   *
   * @covers ::apiKeyForEndpoint
   */
  public function testNoMapAndNoLegacyReturnsNull(): void {
    $credentials = $this->buildCredentials(NULL, NULL);

    $this->assertNull($credentials->apiKeyForEndpoint('https://a.search.windows.net'));
  }

  /**
   * A repeated unresolved endpoint logs once per endpoint, not per call.
   *
   * @covers ::apiKeyForEndpoint
   */
  public function testDedupesMissingKeyLogPerEndpoint(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('error');

    $map = json_encode(['https://a.search.windows.net' => 'KEY_A']);
    $credentials = $this->buildCredentials($map, NULL, $logger);

    $credentials->apiKeyForEndpoint('https://z.search.windows.net');
    $credentials->apiKeyForEndpoint('https://z.search.windows.net');
  }

  /**
   * Builds the resolver with a key repository stubbed for the two key entities.
   *
   * @param string|null $mapValue
   *   The value the azure_ai_search_api_keys key resolves to, or NULL when that
   *   key entity does not exist.
   * @param string|null $legacyValue
   *   The value the legacy azure_ai_search_api_key resolves to, or NULL when it
   *   does not exist.
   * @param \Psr\Log\LoggerInterface|null $logger
   *   The logger to assert against, or NULL for a throwaway mock.
   *
   * @return \Drupal\ys_beacon\Service\BeaconCredentials
   *   The resolver under test.
   */
  private function buildCredentials(?string $mapValue, ?string $legacyValue, ?LoggerInterface $logger = NULL): BeaconCredentials {
    $repository = $this->createMock(KeyRepositoryInterface::class);
    $repository->method('getKey')->willReturnCallback(function (string $id) use ($mapValue, $legacyValue): ?KeyInterface {
      $value = match ($id) {
        BeaconCredentials::KEYS_MAP_KEY => $mapValue,
        BeaconCredentials::LEGACY_KEY => $legacyValue,
        default => NULL,
      };
      if ($value === NULL) {
        return NULL;
      }
      $key = $this->createMock(KeyInterface::class);
      $key->method('getKeyValue')->willReturn($value);
      return $key;
    });

    return new BeaconCredentials($repository, $logger ?? $this->createMock(LoggerInterface::class));
  }

}
