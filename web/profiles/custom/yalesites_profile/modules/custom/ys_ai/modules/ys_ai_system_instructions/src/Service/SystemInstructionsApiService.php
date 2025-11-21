<?php

namespace Drupal\ys_ai_system_instructions\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Service for managing system instructions API calls.
 */
class SystemInstructionsApiService {

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The key repository.
   *
   * @var \Drupal\key\KeyRepositoryInterface
   */
  protected $keyRepository;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * API timeout in seconds.
   */
  const API_TIMEOUT = 30;

  /**
   * Constructs a SystemInstructionsApiService.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Drupal\key\KeyRepositoryInterface $key_repository
   *   The key repository.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(ClientInterface $http_client, ConfigFactoryInterface $config_factory, KeyRepositoryInterface $key_repository, LoggerChannelFactoryInterface $logger_factory) {
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
    $this->keyRepository = $key_repository;
    $this->logger = $logger_factory->get('ys_ai_system_instructions');
  }

  /**
   * Get system instructions from the API.
   *
   * @return array
   *   Array with 'success' (bool), 'data' (string), and 'error' (string) keys.
   */
  public function getSystemInstructions(): array {
    $config = $this->getApiConfig();
    if (!$config) {
      $this->logger->warning('API configuration is incomplete for system instructions retrieval.');
      return [
        'success' => FALSE,
        'data' => '',
        'error' => 'API configuration is incomplete.',
      ];
    }

    $payload = [
      'action' => 'get',
      'web_app_name' => $config['web_app_name'],
      'environment_variables' => ['AZURE_OPENAI_SYSTEM_MESSAGE'],
    ];

    $start_time = microtime(TRUE);
    $this->logger->info('Starting API request to get system instructions', [
      'endpoint' => $config['api_endpoint'],
      'web_app_name' => $config['web_app_name'],
      'action' => 'get',
    ]);

    try {
      $response = $this->httpClient->request('POST', $config['api_endpoint'], [
        'json' => $payload,
        'headers' => [
          'x-functions-key' => $config['api_key'],
          'Content-Type' => 'application/json',
        ],
        'timeout' => self::API_TIMEOUT,
      ]);

      $response_time = round((microtime(TRUE) - $start_time) * 1000, 2);
      $status_code = $response->getStatusCode();
      $response_body = $response->getBody()->getContents();
      $data = json_decode($response_body, TRUE);

      $this->logger->info('API response received for get system instructions', [
        'status_code' => $status_code,
        'response_time_ms' => $response_time,
        'response_size_bytes' => strlen($response_body),
        'has_system_message' => isset($data['AZURE_OPENAI_SYSTEM_MESSAGE']),
      ]);

      if ($status_code === 200 && isset($data['AZURE_OPENAI_SYSTEM_MESSAGE'])) {
        $instructions_length = strlen($data['AZURE_OPENAI_SYSTEM_MESSAGE']);
        $this->logger->info('Successfully retrieved system instructions from API', [
          'instructions_length' => $instructions_length,
          'response_time_ms' => $response_time,
        ]);

        return [
          'success' => TRUE,
          'data' => $data['AZURE_OPENAI_SYSTEM_MESSAGE'],
          'error' => '',
        ];
      }

      $this->logger->error('Invalid API response format for get system instructions', [
        'status_code' => $status_code,
        'response_time_ms' => $response_time,
        'response_keys' => array_keys($data ?: []),
      ]);

      return [
        'success' => FALSE,
        'data' => '',
        'error' => 'Invalid API response format.',
      ];

    }
    catch (GuzzleException $e) {
      $response_time = round((microtime(TRUE) - $start_time) * 1000, 2);
      $error_message = $e->getMessage();

      // Log the detailed error for debugging.
      $this->logger->error('Failed to get system instructions from API: @error', [
        '@error' => $error_message,
        'response_time_ms' => $response_time,
        'endpoint' => $config['api_endpoint'],
        'timeout' => self::API_TIMEOUT,
        'exception_class' => get_class($e),
      ]);

      // Provide a user-friendly error message.
      $user_message = 'Unable to connect to the API. Please check your API endpoint and network connection.';

      // Check for specific error types to provide more helpful messages.
      if (strpos($error_message, 'cURL error 6') !== FALSE || strpos($error_message, 'Could not resolve host') !== FALSE) {
        $user_message = 'Cannot reach the API endpoint. Please verify the endpoint URL is correct.';
      }
      elseif (strpos($error_message, 'cURL error 7') !== FALSE || strpos($error_message, 'Failed to connect') !== FALSE) {
        $user_message = 'Connection refused by the API endpoint. Please check if the service is running.';
      }
      elseif (strpos($error_message, 'cURL error 28') !== FALSE || strpos($error_message, 'timed out') !== FALSE) {
        $user_message = 'API request timed out. The service may be slow or unreachable.';
      }
      elseif (strpos($error_message, '401') !== FALSE || strpos($error_message, 'Unauthorized') !== FALSE) {
        $user_message = 'API authentication failed. Please check your API key.';
      }
      elseif (strpos($error_message, '403') !== FALSE || strpos($error_message, 'Forbidden') !== FALSE) {
        $user_message = 'Access denied by the API. Please verify your API key has the correct permissions.';
      }
      elseif (strpos($error_message, '404') !== FALSE || strpos($error_message, 'Not Found') !== FALSE) {
        $user_message = 'API endpoint not found. Please check the endpoint URL.';
      }
      elseif (strpos($error_message, '500') !== FALSE || strpos($error_message, 'Internal Server Error') !== FALSE) {
        $user_message = 'The API server encountered an error. Please try again later.';
      }

      return [
        'success' => FALSE,
        'data' => '',
        'error' => $user_message,
      ];
    }
  }

  /**
   * Set system instructions via the API.
   *
   * @param string $instructions
   *   The system instructions to set.
   *
   * @return array
   *   Array with 'success' (bool) and 'error' (string) keys.
   */
  public function setSystemInstructions(string $instructions): array {
    $config = $this->getApiConfig();
    if (!$config) {
      $this->logger->warning('API configuration is incomplete for system instructions update.');
      return [
        'success' => FALSE,
        'error' => 'API configuration is incomplete.',
      ];
    }

    $instructions_length = strlen($instructions);
    $payload = [
      'action' => 'set',
      'web_app_name' => $config['web_app_name'],
      'environment_variables' => [
        'AZURE_OPENAI_SYSTEM_MESSAGE' => $instructions,
      ],
    ];

    $start_time = microtime(TRUE);
    $this->logger->info('Starting API request to set system instructions', [
      'endpoint' => $config['api_endpoint'],
      'web_app_name' => $config['web_app_name'],
      'action' => 'set',
      'instructions_length' => $instructions_length,
      'payload_size_bytes' => strlen(json_encode($payload)),
    ]);

    try {
      $response = $this->httpClient->request('POST', $config['api_endpoint'], [
        'json' => $payload,
        'headers' => [
          'x-functions-key' => $config['api_key'],
          'Content-Type' => 'application/json',
        ],
        'timeout' => self::API_TIMEOUT,
      ]);

      $response_time = round((microtime(TRUE) - $start_time) * 1000, 2);
      $status_code = $response->getStatusCode();
      $response_body = $response->getBody()->getContents();

      $this->logger->info('API response received for set system instructions', [
        'status_code' => $status_code,
        'response_time_ms' => $response_time,
        'response_size_bytes' => strlen($response_body),
        'instructions_length' => $instructions_length,
      ]);

      if ($status_code === 200) {
        $this->logger->info('Successfully set system instructions via API', [
          'instructions_length' => $instructions_length,
          'response_time_ms' => $response_time,
        ]);

        return [
          'success' => TRUE,
          'error' => '',
        ];
      }

      $this->logger->error('API returned non-200 status code for set system instructions', [
        'status_code' => $status_code,
        'response_time_ms' => $response_time,
        'response_body' => $response_body,
        'instructions_length' => $instructions_length,
      ]);

      return [
        'success' => FALSE,
        'error' => 'API returned status code: ' . $status_code,
      ];

    }
    catch (GuzzleException $e) {
      $response_time = round((microtime(TRUE) - $start_time) * 1000, 2);
      $error_message = $e->getMessage();

      // Log the detailed error for debugging.
      $this->logger->error('Failed to set system instructions via API: @error', [
        '@error' => $error_message,
        'response_time_ms' => $response_time,
        'endpoint' => $config['api_endpoint'],
        'instructions_length' => $instructions_length,
        'timeout' => self::API_TIMEOUT,
        'exception_class' => get_class($e),
      ]);

      // Provide a user-friendly error message.
      $user_message = 'Unable to connect to the API. Please check your API endpoint and network connection.';

      // Check for specific error types to provide more helpful messages.
      if (strpos($error_message, 'cURL error 6') !== FALSE || strpos($error_message, 'Could not resolve host') !== FALSE) {
        $user_message = 'Cannot reach the API endpoint. Please verify the endpoint URL is correct.';
      }
      elseif (strpos($error_message, 'cURL error 7') !== FALSE || strpos($error_message, 'Failed to connect') !== FALSE) {
        $user_message = 'Connection refused by the API endpoint. Please check if the service is running.';
      }
      elseif (strpos($error_message, 'cURL error 28') !== FALSE || strpos($error_message, 'timed out') !== FALSE) {
        $user_message = 'API request timed out. The service may be slow or unreachable.';
      }
      elseif (strpos($error_message, '401') !== FALSE || strpos($error_message, 'Unauthorized') !== FALSE) {
        $user_message = 'API authentication failed. Please check your API key.';
      }
      elseif (strpos($error_message, '403') !== FALSE || strpos($error_message, 'Forbidden') !== FALSE) {
        $user_message = 'Access denied by the API. Please verify your API key has the correct permissions.';
      }
      elseif (strpos($error_message, '404') !== FALSE || strpos($error_message, 'Not Found') !== FALSE) {
        $user_message = 'API endpoint not found. Please check the endpoint URL.';
      }
      elseif (strpos($error_message, '500') !== FALSE || strpos($error_message, 'Internal Server Error') !== FALSE) {
        $user_message = 'The API server encountered an error. Please try again later.';
      }

      return [
        'success' => FALSE,
        'error' => $user_message,
      ];
    }
  }

  /**
   * Get API configuration.
   *
   * @return array|null
   *   Configuration array or NULL if incomplete or disabled.
   */
  protected function getApiConfig(): ?array {
    $config = $this->configFactory->get('ys_ai_system_instructions.settings');

    // Check if the feature is enabled.
    if (!$config->get('system_instructions_enabled')) {
      return NULL;
    }

    $api_endpoint = $config->get('system_instructions_api_endpoint');
    $web_app_name = $config->get('system_instructions_web_app_name');
    $api_key_name = $config->get('system_instructions_api_key');

    if (!$api_endpoint || !$web_app_name || !$api_key_name) {
      return NULL;
    }

    $api_key = $this->keyRepository->getKey($api_key_name)?->getKeyValue();
    if (!$api_key) {
      return NULL;
    }

    return [
      'api_endpoint' => $api_endpoint,
      'web_app_name' => $web_app_name,
      'api_key' => $api_key,
    ];
  }

}
