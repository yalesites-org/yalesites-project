<?php

namespace Drupal\Tests\ys_ai_system_instructions\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\key\KeyInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\ys_ai_system_instructions\Service\SystemInstructionsApiService;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Response;

/**
 * Unit tests for SystemInstructionsApiService.
 *
 * The external API is never hit for real here: the Guzzle client is mocked
 * throughout, and every request/response is asserted against the mock.
 *
 * @coversDefaultClass \Drupal\ys_ai_system_instructions\Service\SystemInstructionsApiService
 * @group ys_ai_system_instructions
 * @group ys_ai
 * @group yalesites
 */
class SystemInstructionsApiServiceTest extends UnitTestCase {

  /**
   * The mocked Guzzle client.
   *
   * @var \GuzzleHttp\ClientInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $httpClient;

  /**
   * The mocked config object for 'ys_ai_system_instructions.settings'.
   *
   * @var \Drupal\Core\Config\ImmutableConfig|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $config;

  /**
   * Config values keyed by name, returned by the config mock's get().
   *
   * @var array
   */
  protected $configValues;

  /**
   * The mocked key repository.
   *
   * @var \Drupal\key\KeyRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $keyRepository;

  /**
   * The mocked logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $loggerChannel;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->httpClient = $this->createMock(ClientInterface::class);

    $this->configValues = [
      'system_instructions_enabled' => TRUE,
      'system_instructions_api_endpoint' => 'https://api.example.com/instructions',
      'system_instructions_web_app_name' => 'test-app',
      'system_instructions_api_key' => 'test_key_id',
    ];
    $this->config = $this->createMock(ImmutableConfig::class);
    $this->config->method('get')->willReturnCallback(
      fn ($key) => $this->configValues[$key] ?? NULL
    );

    $key = $this->createMock(KeyInterface::class);
    $key->method('getKeyValue')->willReturn('secret-key-value');
    $this->keyRepository = $this->createMock(KeyRepositoryInterface::class);
    $this->keyRepository->method('getKey')->with('test_key_id')->willReturn($key);

    $this->loggerChannel = $this->createMock(LoggerChannelInterface::class);
  }

  /**
   * Builds the service under test with mocked constructor dependencies.
   */
  protected function createService(): SystemInstructionsApiService {
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->with('ys_ai_system_instructions.settings')->willReturn($this->config);

    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->with('ys_ai_system_instructions')->willReturn($this->loggerChannel);

    return new SystemInstructionsApiService(
      $this->httpClient,
      $config_factory,
      $this->keyRepository,
      $logger_factory
    );
  }

  /**
   * Tests getSystemInstructions() when the feature is disabled.
   *
   * @covers ::getSystemInstructions
   * @covers ::getApiConfig
   */
  public function testGetSystemInstructionsReturnsErrorWhenFeatureDisabled(): void {
    $this->configValues['system_instructions_enabled'] = FALSE;

    $this->httpClient->expects($this->never())->method('request');
    $this->loggerChannel->expects($this->once())->method('warning');

    $result = $this->createService()->getSystemInstructions();

    $this->assertSame([
      'success' => FALSE,
      'data' => '',
      'error' => 'API configuration is incomplete.',
    ], $result);
  }

  /**
   * Tests getSystemInstructions() when required config values are missing.
   *
   * @covers ::getSystemInstructions
   * @covers ::getApiConfig
   */
  public function testGetSystemInstructionsReturnsErrorWhenEndpointMissing(): void {
    $this->configValues['system_instructions_api_endpoint'] = NULL;

    $this->httpClient->expects($this->never())->method('request');

    $result = $this->createService()->getSystemInstructions();

    $this->assertFalse($result['success']);
    $this->assertSame('API configuration is incomplete.', $result['error']);
  }

  /**
   * Tests getSystemInstructions() when the configured key has no value.
   *
   * @covers ::getSystemInstructions
   * @covers ::getApiConfig
   */
  public function testGetSystemInstructionsReturnsErrorWhenKeyValueMissing(): void {
    $key = $this->createMock(KeyInterface::class);
    $key->method('getKeyValue')->willReturn(NULL);
    $this->keyRepository = $this->createMock(KeyRepositoryInterface::class);
    $this->keyRepository->method('getKey')->with('test_key_id')->willReturn($key);

    $this->httpClient->expects($this->never())->method('request');

    $result = $this->createService()->getSystemInstructions();

    $this->assertFalse($result['success']);
    $this->assertSame('API configuration is incomplete.', $result['error']);
  }

  /**
   * Tests getSystemInstructions() builds the expected request and parses it.
   *
   * @covers ::getSystemInstructions
   * @covers ::getApiConfig
   */
  public function testGetSystemInstructionsSendsExpectedRequestAndParsesSuccess(): void {
    $response = new Response(200, [], json_encode([
      'AZURE_OPENAI_SYSTEM_MESSAGE' => 'Hello world instructions',
    ]));

    $this->httpClient->expects($this->once())
      ->method('request')
      ->with(
        'POST',
        'https://api.example.com/instructions',
        $this->callback(function (array $options) {
          return $options['json'] === [
            'action' => 'get',
            'web_app_name' => 'test-app',
            'environment_variables' => ['AZURE_OPENAI_SYSTEM_MESSAGE'],
          ]
            && $options['headers']['x-functions-key'] === 'secret-key-value'
            && $options['headers']['Content-Type'] === 'application/json'
            && $options['timeout'] === SystemInstructionsApiService::API_TIMEOUT;
        })
      )
      ->willReturn($response);

    $result = $this->createService()->getSystemInstructions();

    $this->assertSame([
      'success' => TRUE,
      'data' => 'Hello world instructions',
      'error' => '',
    ], $result);
  }

  /**
   * Tests getSystemInstructions() when the response is missing the message.
   *
   * @covers ::getSystemInstructions
   */
  public function testGetSystemInstructionsReturnsErrorOnInvalidResponseFormat(): void {
    $response = new Response(200, [], json_encode(['unexpected' => 'shape']));
    $this->httpClient->method('request')->willReturn($response);

    $this->loggerChannel->expects($this->once())->method('error');

    $result = $this->createService()->getSystemInstructions();

    $this->assertSame([
      'success' => FALSE,
      'data' => '',
      'error' => 'Invalid API response format.',
    ], $result);
  }

  /**
   * Tests getSystemInstructions() when the API returns a non-200 status.
   *
   * @covers ::getSystemInstructions
   */
  public function testGetSystemInstructionsReturnsErrorOnNon200Status(): void {
    $response = new Response(500, [], json_encode([
      'AZURE_OPENAI_SYSTEM_MESSAGE' => 'Hello world instructions',
    ]));
    $this->httpClient->method('request')->willReturn($response);

    $result = $this->createService()->getSystemInstructions();

    $this->assertFalse($result['success']);
    $this->assertSame('Invalid API response format.', $result['error']);
  }

  /**
   * Tests getSystemInstructions() maps transport exceptions to messages.
   *
   * @covers ::getSystemInstructions
   *
   * @dataProvider providerExceptionMessages
   */
  public function testGetSystemInstructionsMapsExceptionsToUserFriendlyMessages(string $raw_message, string $expected_user_message): void {
    $this->httpClient->method('request')->willThrowException(new TransferException($raw_message));

    $this->loggerChannel->expects($this->once())->method('error');

    $result = $this->createService()->getSystemInstructions();

    $this->assertSame([
      'success' => FALSE,
      'data' => '',
      'error' => $expected_user_message,
    ], $result);
  }

  /**
   * Data provider of raw Guzzle exception messages and their user-facing map.
   */
  public static function providerExceptionMessages(): array {
    return [
      'unrecognized error' => [
        'Something went wrong',
        'Unable to connect to the API. Please check your API endpoint and network connection.',
      ],
      'DNS failure' => [
        'cURL error 6: Could not resolve host',
        'Cannot reach the API endpoint. Please verify the endpoint URL is correct.',
      ],
      'connection refused' => [
        'cURL error 7: Failed to connect',
        'Connection refused by the API endpoint. Please check if the service is running.',
      ],
      'timeout' => [
        'cURL error 28: Operation timed out',
        'API request timed out. The service may be slow or unreachable.',
      ],
      'unauthorized' => [
        'Client error: 401 Unauthorized',
        'API authentication failed. Please check your API key.',
      ],
      'forbidden' => [
        'Client error: 403 Forbidden',
        'Access denied by the API. Please verify your API key has the correct permissions.',
      ],
      'not found' => [
        'Client error: 404 Not Found',
        'API endpoint not found. Please check the endpoint URL.',
      ],
      'server error' => [
        'Server error: 500 Internal Server Error',
        'The API server encountered an error. Please try again later.',
      ],
    ];
  }

  /**
   * Tests setSystemInstructions() when the feature is disabled.
   *
   * @covers ::setSystemInstructions
   * @covers ::getApiConfig
   */
  public function testSetSystemInstructionsReturnsErrorWhenFeatureDisabled(): void {
    $this->configValues['system_instructions_enabled'] = FALSE;

    $this->httpClient->expects($this->never())->method('request');
    $this->loggerChannel->expects($this->once())->method('warning');

    $result = $this->createService()->setSystemInstructions('New instructions');

    $this->assertSame([
      'success' => FALSE,
      'error' => 'API configuration is incomplete.',
    ], $result);
  }

  /**
   * Tests setSystemInstructions() builds the expected request payload.
   *
   * @covers ::setSystemInstructions
   */
  public function testSetSystemInstructionsSendsExpectedRequestAndReturnsSuccess(): void {
    $response = new Response(200, [], '');

    $this->httpClient->expects($this->once())
      ->method('request')
      ->with(
        'POST',
        'https://api.example.com/instructions',
        $this->callback(function (array $options) {
          return $options['json'] === [
            'action' => 'set',
            'web_app_name' => 'test-app',
            'environment_variables' => [
              'AZURE_OPENAI_SYSTEM_MESSAGE' => 'New instructions',
            ],
          ]
            && $options['headers']['x-functions-key'] === 'secret-key-value';
        })
      )
      ->willReturn($response);

    $result = $this->createService()->setSystemInstructions('New instructions');

    $this->assertSame(['success' => TRUE, 'error' => ''], $result);
  }

  /**
   * Tests setSystemInstructions() when the API returns a non-200 status.
   *
   * @covers ::setSystemInstructions
   */
  public function testSetSystemInstructionsReturnsErrorOnNon200Status(): void {
    $response = new Response(422, [], 'Unprocessable');
    $this->httpClient->method('request')->willReturn($response);

    $this->loggerChannel->expects($this->once())->method('error');

    $result = $this->createService()->setSystemInstructions('New instructions');

    $this->assertSame([
      'success' => FALSE,
      'error' => 'API returned status code: 422',
    ], $result);
  }

  /**
   * Tests setSystemInstructions() maps a transport exception to a message.
   *
   * @covers ::setSystemInstructions
   */
  public function testSetSystemInstructionsMapsExceptionToUserFriendlyMessage(): void {
    $this->httpClient->method('request')
      ->willThrowException(new TransferException('cURL error 28: Operation timed out'));

    $this->loggerChannel->expects($this->once())->method('error');

    $result = $this->createService()->setSystemInstructions('New instructions');

    $this->assertSame([
      'success' => FALSE,
      'error' => 'API request timed out. The service may be slow or unreachable.',
    ], $result);
  }

}
