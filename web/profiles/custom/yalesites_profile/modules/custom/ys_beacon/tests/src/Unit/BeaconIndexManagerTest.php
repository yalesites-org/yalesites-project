<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_beacon\Service\BeaconIndexManager;

/**
 * Tests Azure AI Search index name sanitization.
 *
 * @group ys_beacon
 * @coversDefaultClass \Drupal\ys_beacon\Service\BeaconIndexManager
 */
class BeaconIndexManagerTest extends UnitTestCase {

  /**
   * @covers ::sanitizeIndexName
   * @dataProvider providerSanitizeIndexName
   */
  public function testSanitizeIndexName(string $input, string $expected): void {
    $this->assertSame($expected, BeaconIndexManager::sanitizeIndexName($input));
  }

  /**
   * Data provider for index name sanitization.
   */
  public static function providerSanitizeIndexName(): array {
    return [
      'pantheon site and environment' => ['my-site-live', 'my-site-live'],
      'uppercase lowered' => ['My-Site-DEV', 'my-site-dev'],
      'underscores and spaces become dashes' => ['my_site name', 'my-site-name'],
      'consecutive separators collapse' => ['my--site__test', 'my-site-test'],
      'leading and trailing dashes trimmed' => ['-my-site-', 'my-site'],
      'invalid characters replaced' => ['site.yale.edu/path', 'site-yale-edu-path'],
      'long names truncated to 128 chars' => [
        str_repeat('a', 130),
        str_repeat('a', 128),
      ],
      'truncation never ends with a dash' => [
        str_repeat('a', 127) . '-tail',
        str_repeat('a', 127),
      ],
    ];
  }

}
