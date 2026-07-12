<?php

namespace Drupal\Tests\ys_servicenow\Unit\Plugin\migrate_plus\authentication;

use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for the ServiceNowAuth migrate authentication plugin.
 *
 * ServiceNowAuth::getAuthenticationOptions() cannot currently be exercised at
 * all: its method signature is incompatible with the parent interface it
 * implements, which makes PHP raise an uncatchable fatal error the instant
 * the class is loaded (verified while authoring this suite -- instantiating
 * the class here crashed the entire PHPUnit process, not just one
 * assertion). This class therefore avoids referencing ServiceNowAuth as a
 * PHP symbol at all -- even a `use` import would autoload it the moment the
 * name is used in executable code -- and instead inspects its source text
 * directly.
 * See ::testGetAuthenticationOptionsSignatureIsIncompatibleWithInterface.
 *
 * @group yalesites
 * @group ys_servicenow
 */
class ServiceNowAuthTest extends UnitTestCase {

  /**
   * Absolute path to the plugin source file under test.
   */
  protected function pluginFilePath(): string {
    return dirname(__FILE__, 7) . '/src/Plugin/migrate_plus/authentication/ServiceNowAuth.php';
  }

  /**
   * Current behavior: the class cannot be loaded without a fatal error.
   *
   * AuthenticationPluginInterface::getAuthenticationOptions($url): array
   * declares a required $url parameter, but
   * ServiceNowAuth::getAuthenticationOptions(): array overrides it with zero
   * parameters. PHP's signature-compatibility check for interface
   * implementations raises a fatal "Declaration ... must be compatible
   * with ..." error the moment the class is loaded -- not just when the
   * method is called -- so every code path that would autoload this plugin
   * (migrate_plus's authentication plugin manager during plugin discovery,
   * running the ServiceNow migration, or a test instantiating the class)
   * currently fatals the whole PHP process. This means the ServiceNow sync
   * feature cannot run at all in its current state. Paired with
   * testGetAuthenticationOptionsShouldAcceptUrlParameter() -- delete once
   * the GAP is fixed.
   */
  public function testGetAuthenticationOptionsSignatureIsIncompatibleWithInterface() {
    $source = file_get_contents($this->pluginFilePath());

    $this->assertMatchesRegularExpression(
      '/public function getAuthenticationOptions\(\s*\)\s*:\s*array/',
      $source,
      'ServiceNowAuth::getAuthenticationOptions() currently declares zero parameters. AuthenticationPluginInterface requires a $url parameter, and the mismatch fatals PHP class loading -- see the GAP note referenced above.'
    );
  }

  /**
   * GAP test: getAuthenticationOptions() should accept the $url parameter.
   */
  public function testGetAuthenticationOptionsShouldAcceptUrlParameter() {
    $this->markTestSkipped('GAP: ServiceNowAuth::getAuthenticationOptions() declares zero parameters, incompatible with AuthenticationPluginInterface::getAuthenticationOptions($url): array -- this currently fatals PHP the instant the class is loaded (verified: it crashed this test suite), meaning the ServiceNow migration cannot run at all until fixed -- see ~/Documents/Claude/not_dave/module-tests-20260710/ys_servicenow.md');
  }

}
