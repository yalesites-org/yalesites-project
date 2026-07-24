<?php

namespace Drupal\ys_beacon\Service;

use Drupal\key\KeyRepositoryInterface;
use Drupal\ys_beacon\Config\YsBeaconConfigOverrides;
use Psr\Log\LoggerInterface;

/**
 * Resolves the Azure AI Search API key paired with a site's endpoint.
 *
 * PR #1395 (yalesites-org/YaleSites-Internal#1440) pins a site's Azure AI
 * Search endpoint URL once it owns an index, so two Azure services can coexist
 * (older sites on one, newer on another). Each service has its own admin key,
 * so the key must be resolved per endpoint, not from one shared secret. All the
 * live services' keys live in a single Pantheon secret (the key entity
 * self::KEYS_MAP_KEY) as a JSON map of endpoint URL => key; this resolves the
 * one matching the site's effective endpoint. The raw key is never written to
 * config - only the non-secret URL is pinned - so Pantheon still obfuscates it
 * (yalesites-org/YaleSites-Internal#1448).
 */
class BeaconCredentials {

  /**
   * Key entity id holding the endpoint => API key JSON map.
   */
  public const KEYS_MAP_KEY = 'azure_ai_search_api_keys';

  /**
   * Legacy single-key entity id, used as a pre-rollout fallback.
   *
   * Before the map secret is populated, a single-service fleet keeps resolving
   * its one key from here, so the change ships without a hard cutover.
   */
  public const LEGACY_KEY = 'azure_ai_search_api_key';

  /**
   * Endpoints already logged as unresolvable this request, to avoid log spam.
   *
   * The key is resolved on every Azure call, so a single misconfigured endpoint
   * would otherwise log many times per request. Keyed by normalized endpoint.
   *
   * @var array<string, true>
   */
  protected array $loggedMissing = [];

  public function __construct(
    protected KeyRepositoryInterface $keyRepository,
    protected LoggerInterface $logger,
  ) {
  }

  /**
   * Resolves the API key for a given Azure AI Search endpoint.
   *
   * @param string $endpoint
   *   The endpoint URL the site is (or would be) talking to.
   *
   * @return string|null
   *   The matching API key, or NULL when none can be resolved (in which case an
   *   actionable error has been logged, except for the expected pre-rollout
   *   case where nothing is configured at all).
   */
  public function apiKeyForEndpoint(string $endpoint): ?string {
    $normalized = $this->normalize($endpoint);

    $raw = $this->keyRepository->getKey(self::KEYS_MAP_KEY)?->getKeyValue();
    $raw = is_string($raw) ? trim($raw) : '';

    // No map configured yet: fall back to the legacy single key so a
    // single-service fleet keeps working before the map is populated. This is
    // the expected pre-rollout state, so it is not logged as an error.
    if ($raw === '') {
      return $this->legacyKey();
    }

    $map = json_decode($raw, TRUE);
    if (!is_array($map)) {
      $this->logMissing($normalized, 'the "azure_ai_search_api_keys" secret is not a valid JSON object of endpoint => key');
      return NULL;
    }

    foreach ($map as $url => $key) {
      if ($this->normalize((string) $url) === $normalized && is_string($key) && $key !== '') {
        return $key;
      }
    }

    // The map is configured but has no entry for this endpoint. Fail closed
    // rather than fall back to the legacy key: once multiple services are live,
    // the legacy key would be the wrong service's key for a pinned site.
    $this->logMissing($normalized, 'no API key is defined for this endpoint in the "azure_ai_search_api_keys" map; add it');
    return NULL;
  }

  /**
   * Resolves the legacy single-value API key, if configured.
   *
   * @return string|null
   *   The legacy key value, or NULL when the key entity is absent or empty.
   */
  protected function legacyKey(): ?string {
    $value = $this->keyRepository->getKey(self::LEGACY_KEY)?->getKeyValue();
    return (is_string($value) && $value !== '') ? $value : NULL;
  }

  /**
   * Normalizes an endpoint so a stored key matches regardless of surface form.
   *
   * Reuses the endpoint normalization the config override already applies (a
   * missing scheme defaults to https), then lower-cases and drops a trailing
   * slash. Azure AI Search endpoints are bare hosts (no path or port), so this
   * is enough for an authored map key and the site's resolved URL to compare
   * equal.
   *
   * @param string $url
   *   The raw endpoint value.
   *
   * @return string
   *   The normalized endpoint, or an empty string when none was given.
   */
  protected function normalize(string $url): string {
    $url = YsBeaconConfigOverrides::normalizeEndpoint($url);
    return $url === '' ? '' : strtolower(rtrim($url, '/'));
  }

  /**
   * Logs an actionable, de-duplicated error that a key could not be resolved.
   *
   * @param string $endpoint
   *   The normalized endpoint that could not be resolved.
   * @param string $reason
   *   The specific reason, so the log points ops straight at the fix.
   */
  protected function logMissing(string $endpoint, string $reason): void {
    if (isset($this->loggedMissing[$endpoint])) {
      return;
    }
    $this->loggedMissing[$endpoint] = TRUE;
    $this->logger->error('Beacon could not resolve an Azure AI Search API key for endpoint "@endpoint": @reason.', [
      '@endpoint' => $endpoint === '' ? '(no endpoint configured)' : $endpoint,
      '@reason' => $reason,
    ]);
  }

}
