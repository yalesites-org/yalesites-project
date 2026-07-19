<?php

namespace Drupal\Tests\ys_embed\Kernel;

use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\KernelTests\KernelTestBase;
use Drupal\ys_embed\Plugin\EmbedSource\Broken;
use Drupal\ys_embed\Plugin\EmbedSource\Twitter;
use Drupal\ys_embed\Plugin\EmbedSourceManager;

/**
 * Tests discovery, active-filtering, and lookup logic in EmbedSourceManager.
 *
 * @coversDefaultClass \Drupal\ys_embed\Plugin\EmbedSourceManager
 *
 * @group yalesites
 * @group ys_embed
 */
class EmbedSourceManagerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'ys_embed',
  ];

  /**
   * A real X/Twitter blockquote embed code, an active EmbedSource.
   *
   * @var string
   */
  const TWITTER_EMBED = '<blockquote class="twitter-tweet"><p lang="en" dir="ltr">Yale news.</p></blockquote> <script async src="https://platform.twitter.com/widgets.js" charset="utf-8"></script>';

  /**
   * A real Qualtrics survey URL. Qualtrics is registered but "active = FALSE".
   *
   * @var string
   */
  const QUALTRICS_URL = 'https://yalesurvey.ca1.qualtrics.com/jfe/form/SV_cDezt2JVsNok77o';

  /**
   * The EmbedSource plugin manager.
   *
   * @var \Drupal\ys_embed\Plugin\EmbedSourceManager
   */
  protected $manager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->manager = $this->container->get('plugin.manager.embed_source');
  }

  /**
   * The manager service is registered and is a default plugin manager.
   */
  public function testManagerServiceIsRegistered(): void {
    $this->assertInstanceOf(EmbedSourceManager::class, $this->manager);
    $this->assertInstanceOf(DefaultPluginManager::class, $this->manager);
  }

  /**
   * @covers ::getSources
   */
  public function testGetSourcesOnlyIncludesActivePlugins(): void {
    $sources = $this->manager->getSources();
    $this->assertArrayHasKey('twitter', $sources);
    $this->assertArrayHasKey('google_calendar', $sources);
    $this->assertArrayNotHasKey('broken', $sources);
    $this->assertArrayNotHasKey('qualtrics', $sources);
  }

  /**
   * @covers ::getSources
   */
  public function testGetDefinitionsIncludesInactivePlugins(): void {
    // Discovery finds every plugin regardless of its "active" annotation;
    // only getSources() filters on it.
    $definitions = $this->manager->getDefinitions();
    $this->assertArrayHasKey('broken', $definitions);
    $this->assertArrayHasKey('qualtrics', $definitions);
  }

  /**
   * @covers ::findEmbedSource
   */
  public function testFindEmbedSourceMatchesKnownEmbedCode(): void {
    $source = $this->manager->findEmbedSource(self::TWITTER_EMBED);
    $this->assertIsArray($source);
    $this->assertSame('twitter', $source['id']);
  }

  /**
   * @covers ::findEmbedSource
   */
  public function testFindEmbedSourceReturnsNullForUnmatchedInput(): void {
    $this->assertNull($this->manager->findEmbedSource('this matches nothing'));
  }

  /**
   * @covers ::findEmbedSource
   */
  public function testFindEmbedSourceIgnoresInactivePluginMatch(): void {
    // The Qualtrics regex would match this URL, but Qualtrics is
    // "active = FALSE" so it is excluded from getSources() and therefore
    // never considered by findEmbedSource().
    $this->assertNull($this->manager->findEmbedSource(self::QUALTRICS_URL));
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidTrueForActivePluginMatch(): void {
    $this->assertTrue($this->manager->isValid(self::TWITTER_EMBED));
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidFalseForInactivePluginMatch(): void {
    $this->assertFalse($this->manager->isValid(self::QUALTRICS_URL));
  }

  /**
   * @covers ::isValidSourceId
   */
  public function testIsValidSourceIdRecognizesRegisteredIdsRegardlessOfActiveFlag(): void {
    $this->assertTrue($this->manager->isValidSourceId('twitter'));
    $this->assertTrue($this->manager->isValidSourceId('qualtrics'));
    $this->assertTrue($this->manager->isValidSourceId('broken'));
    $this->assertFalse($this->manager->isValidSourceId('not_a_real_plugin_id'));
  }

  /**
   * @covers ::loadPluginById
   */
  public function testLoadPluginByIdReturnsCorrectPluginInstance(): void {
    $this->assertInstanceOf(Twitter::class, $this->manager->loadPluginById('twitter'));
  }

  /**
   * @covers ::loadPluginById
   */
  public function testLoadPluginByIdCachesInstancesById(): void {
    $first = $this->manager->loadPluginById('twitter');
    $second = $this->manager->loadPluginById('twitter');
    $this->assertSame($first, $second);
  }

  /**
   * @covers ::loadPluginByCode
   */
  public function testLoadPluginByCodeReturnsMatchingPlugin(): void {
    $this->assertInstanceOf(Twitter::class, $this->manager->loadPluginByCode(self::TWITTER_EMBED));
  }

  /**
   * @covers ::loadPluginByCode
   */
  public function testLoadPluginByCodeReturnsBrokenPluginForUnmatchedInput(): void {
    // loadPluginByCode() falls back to loadPluginById(BROKEN_ID) directly,
    // and 'broken' is itself a validly registered plugin id, so this path
    // does not hit the loadPluginById() gap characterized below.
    $this->assertInstanceOf(Broken::class, $this->manager->loadPluginByCode('this matches nothing'));
  }

  /**
   * LoadPluginById() returns the Broken plugin for an unregistered ID.
   *
   * LoadPluginById() lazily instantiates and returns the Broken plugin for an
   * unregistered plugin ID (per its docblock) rather than returning NULL with
   * an "Undefined array key" warning.
   *
   * @covers ::loadPluginById
   */
  public function testLoadPluginByIdReturnsBrokenPluginForUnregisteredId(): void {
    $captured = [];
    set_error_handler(function (int $errno, string $errstr) use (&$captured): bool {
      $captured[] = $errstr;
      return TRUE;
    }, E_WARNING);

    try {
      $result = $this->manager->loadPluginById('totally_unregistered_plugin_id');
    }
    finally {
      restore_error_handler();
    }

    $this->assertInstanceOf(Broken::class, $result);
    $this->assertSame([], $captured);
  }

}
