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
   *   actionable error has been logged).
   */
  public function apiKeyForEndpoint(string $endpoint): ?string {
    $normalized = $this->normalize($endpoint);

    $raw = $this->rawKeyMap();

    // The map is the only source of keys, so an absent or blank map means no
    // endpoint can authenticate.
    if ($raw === '') {
      $this->logMissing($normalized, 'the "azure_ai_search_api_keys" secret is missing or empty');
      return NULL;
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

    // The map is configured but has no entry for this endpoint. Fail closed:
    // another service's key would be the wrong one for a pinned site.
    $this->logMissing($normalized, 'no API key is defined for this endpoint in the "azure_ai_search_api_keys" map; add it');
    return NULL;
  }

  /**
   * Whether the endpoint => key map secret holds a non-blank value.
   *
   * Says only that Beacon's key source is configured, not that any particular
   * endpoint resolves, and never logs.
   *
   * @return bool
   *   TRUE when the azure_ai_search_api_keys secret is non-blank.
   */
  public function hasKeyMap(): bool {
    return $this->rawKeyMap() !== '';
  }

  /**
   * Reads the trimmed map secret value.
   *
   * @return string
   *   The raw JSON map, or an empty string when absent or blank.
   */
  protected function rawKeyMap(): string {
    $raw = $this->keyRepository->getKey(self::KEYS_MAP_KEY)?->getKeyValue();
    return is_string($raw) ? trim($raw) : '';
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
