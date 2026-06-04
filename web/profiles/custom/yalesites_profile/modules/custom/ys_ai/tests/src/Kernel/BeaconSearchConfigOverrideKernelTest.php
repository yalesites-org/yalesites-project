<?php

namespace Drupal\Tests\ys_ai\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\key\Entity\Key;
use Drupal\ys_ai\Config\BeaconSearchConfigOverride;

/**
 * Integration tests for BeaconSearchConfigOverride.
 *
 * Exercises loadOverrides() against the real key.repository and config.storage
 * services (rather than mocks) to confirm that blank Beacon server fields are
 * filled from the Key and environment, while explicitly stored values win.
 *
 * @coversDefaultClass \Drupal\ys_ai\Config\BeaconSearchConfigOverride
 *
 * @group yalesites
 */
class BeaconSearchConfigOverrideKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'key'];

  /**
   * Captured original env values restored after each test.
   *
   * @var array
   */
  protected $envBackup = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    foreach (['PANTHEON_SITE_NAME', 'PANTHEON_ENVIRONMENT', 'LANDO_APP_NAME', 'DDEV_SITENAME'] as $name) {
      $this->envBackup[$name] = $_ENV[$name] ?? NULL;
      unset($_ENV[$name]);
      putenv($name);
    }

    // The Azure URL is exposed to Drupal as a Key entity. Use the config
    // provider so the value is self-contained in the test.
    Key::create([
      'id' => BeaconSearchConfigOverride::URL_KEY_ID,
      'label' => 'Azure AI Search URL',
      'key_type' => 'authentication',
      'key_provider' => 'config',
      'key_provider_settings' => [
        'key_value' => 'https://key.search.windows.net',
        'base64_encoded' => FALSE,
      ],
      'key_input' => 'text_field',
    ])->save();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach ($this->envBackup as $name => $value) {
      if ($value === NULL) {
        unset($_ENV[$name]);
        putenv($name);
      }
      else {
        $_ENV[$name] = $value;
        putenv($name . '=' . $value);
      }
    }
    parent::tearDown();
  }

  /**
   * Stores the Beacon server config with the given URL and index name.
   *
   * @param string $url
   *   The stored url value.
   * @param string $databaseName
   *   The stored database_name value.
   */
  protected function storeServerConfig(string $url, string $databaseName): void {
    $this->container->get('config.storage')->write(BeaconSearchConfigOverride::SERVER_CONFIG_NAME, [
      'backend_config' => [
        'database_settings' => [
          'url' => $url,
          'database_name' => $databaseName,
        ],
      ],
    ]);
  }

  /**
   * Builds the override using the real container services.
   *
   * @return \Drupal\ys_ai\Config\BeaconSearchConfigOverride
   *   The override under test.
   */
  protected function override(): BeaconSearchConfigOverride {
    return new BeaconSearchConfigOverride(
      $this->container->get('key.repository'),
      $this->container->get('config.storage')
    );
  }

  /**
   * Both fields blank: the override supplies the Key URL and derived index.
   *
   * @covers ::loadOverrides
   */
  public function testBlankFieldsAreFilledFromKeyAndEnvironment(): void {
    $_ENV['PANTHEON_SITE_NAME'] = 'mysite';
    $_ENV['PANTHEON_ENVIRONMENT'] = 'dev';
    $this->storeServerConfig('', '');

    $result = $this->override()->loadOverrides([
      BeaconSearchConfigOverride::URL_CONFIG_NAME,
      BeaconSearchConfigOverride::SERVER_CONFIG_NAME,
    ]);

    $this->assertSame(
      'https://key.search.windows.net',
      $result[BeaconSearchConfigOverride::URL_CONFIG_NAME]['url']
    );
    $this->assertSame(
      'mysite-dev',
      $result[BeaconSearchConfigOverride::SERVER_CONFIG_NAME]['backend_config']['database_settings']['database_name']
    );
  }

  /**
   * Explicit values: the entered URL and index name take precedence.
   *
   * The entered URL is propagated to the global VDB config the client reads
   * (winning over the Key), and the index-name override yields to the value
   * stored on the server.
   *
   * @covers ::loadOverrides
   */
  public function testExplicitValuesTakePrecedence(): void {
    $_ENV['PANTHEON_SITE_NAME'] = 'mysite';
    $_ENV['PANTHEON_ENVIRONMENT'] = 'dev';
    $this->storeServerConfig('https://explicit.search.windows.net', 'my-custom-index');

    $result = $this->override()->loadOverrides([
      BeaconSearchConfigOverride::URL_CONFIG_NAME,
      BeaconSearchConfigOverride::SERVER_CONFIG_NAME,
    ]);

    $this->assertSame(
      'https://explicit.search.windows.net',
      $result[BeaconSearchConfigOverride::URL_CONFIG_NAME]['url']
    );
    $this->assertArrayNotHasKey(BeaconSearchConfigOverride::SERVER_CONFIG_NAME, $result);
  }

}
