<?php

namespace Drupal\Tests\ys_core\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_core\CoreTwigExtension;
use Drupal\ys_media\YaleSitesMediaManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests CoreTwigExtension's config lookups and URL classification.
 *
 * @coversDefaultClass \Drupal\ys_core\CoreTwigExtension
 *
 * @group ys_core
 * @group yalesites
 */
class CoreTwigExtensionTest extends UnitTestCase {

  /**
   * Mock of the ys_core.site config, keyed by setting name.
   *
   * @var \Drupal\Core\Config\Config|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $coreConfig;

  /**
   * Mock of the ys_core.header_settings config, keyed by setting name.
   *
   * @var \Drupal\Core\Config\Config|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $headerConfig;

  /**
   * Mock of the media manager, used only for the site_name_image setting.
   *
   * @var \Drupal\ys_media\YaleSitesMediaManager|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $mediaManager;

  /**
   * The extension under test, bound to the current domain www.yale.edu.
   *
   * @var \Drupal\ys_core\CoreTwigExtension
   */
  protected $extension;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->coreConfig = $this->createMock(Config::class);
    $this->headerConfig = $this->createMock(Config::class);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('getEditable')->willReturnMap([
      ['ys_core.site', $this->coreConfig],
      ['ys_core.header_settings', $this->headerConfig],
    ]);

    $this->mediaManager = $this->createMock(YaleSitesMediaManager::class);

    $request = Request::create('https://www.yale.edu/page?foo=bar');
    $requestStack = new RequestStack();
    $requestStack->push($request);

    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);

    $this->extension = new CoreTwigExtension($configFactory, $this->mediaManager, $requestStack, $loggerFactory);
  }

  /**
   * @covers ::getCoreSetting
   */
  public function testGetCoreSetting(): void {
    $this->coreConfig->method('get')->with('site_name')->willReturn('Yale University');
    $this->assertSame('Yale University', $this->extension->getCoreSetting('site_name'));
  }

  /**
   * @covers ::getHeaderSetting
   */
  public function testGetHeaderSettingReturnsRawConfigForNonImageSetting(): void {
    $this->headerConfig->method('get')->with('header_type')->willReturn('mega-menu');
    $this->assertSame('mega-menu', $this->extension->getHeaderSetting('header_type'));
  }

  /**
   * @covers ::getHeaderSetting
   */
  public function testGetHeaderSettingReturnsFalseWhenNoSiteNameImageConfigured(): void {
    $this->headerConfig->method('get')->with('site_name_image')->willReturn(NULL);
    $this->assertFalse($this->extension->getHeaderSetting('site_name_image'));
  }

  /**
   * @covers ::getHeaderSetting
   */
  public function testGetHeaderSettingReturnsSvgWhenSiteNameImageConfigured(): void {
    $this->headerConfig->method('get')->with('site_name_image')->willReturn([42]);
    $this->mediaManager->expects($this->once())
      ->method('getSiteNameImage')
      ->with(42)
      ->willReturn('<svg><title>Yale</title></svg>');

    $this->assertSame('<svg><title>Yale</title></svg>', $this->extension->getHeaderSetting('site_name_image'));
  }

  /**
   * Degrades instead of fataling when the media manager is absent.
   *
   * The media manager is injected with an optional container reference, so it
   * is NULL on any bootstrap where ys_media is not enabled -- which `drush
   * deploy` guarantees for exactly one bootstrap on an existing site, because
   * updatedb runs before the config import that enables the module. Pages are
   * served during that window, so this path has to return the same value the
   * "no image configured" path returns rather than raising an error.
   *
   * @covers ::__construct
   * @covers ::getHeaderSetting
   */
  public function testGetHeaderSettingDegradesWhenMediaManagerIsUnavailable(): void {
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('getEditable')->willReturnMap([
      ['ys_core.site', $this->coreConfig],
      ['ys_core.header_settings', $this->headerConfig],
    ]);
    $requestStack = new RequestStack();
    $requestStack->push(Request::create('https://www.yale.edu/page'));

    $extension = new CoreTwigExtension(
      $configFactory,
      NULL,
      $requestStack,
      $this->createMock(LoggerChannelFactoryInterface::class)
    );

    // A site name image IS configured; only the service is missing.
    $this->headerConfig->method('get')->willReturnMap([
      ['site_name_image', [42]],
      ['header_type', 'mega-menu'],
    ]);

    $this->assertFalse(
      $extension->getHeaderSetting('site_name_image'),
      'Without ys_media the SVG is simply unavailable, exactly as when none is configured.'
    );
    $this->assertSame(
      'mega-menu',
      $extension->getHeaderSetting('header_type'),
      'Every other header setting must keep working without ys_media.'
    );
  }

  /**
   * @covers ::getQueryParam
   */
  public function testGetQueryParamReturnsValue(): void {
    $this->assertSame('bar', $this->extension->getQueryParam('foo'));
  }

  /**
   * @covers ::getQueryParam
   */
  public function testGetQueryParamReturnsNullWhenMissing(): void {
    $this->assertNull($this->extension->getQueryParam('missing'));
  }

  /**
   * @covers ::getUrlType
   *
   * @dataProvider urlTypeProvider
   */
  public function testGetUrlType(string $url, string $expected): void {
    $this->assertSame($expected, $this->extension->getUrlType($url));
  }

  /**
   * Provides URLs and their expected getUrlType() classification.
   *
   * @return array
   *   Each case: [url, expected type].
   */
  public static function urlTypeProvider(): array {
    return [
      'pdf download' => ['/files/report.pdf', 'download'],
      'docx download' => ['/files/report.docx', 'download'],
      'same-domain absolute url' => ['https://www.yale.edu/about', 'internal'],
      'relative path' => ['/about', 'internal'],
      'query string only' => ['?foo=bar', 'internal'],
      'anchor only' => ['#section', 'internal'],
      'data url' => ['data:image/png;base64,abc', 'internal'],
      'other-domain absolute url' => ['https://external.com/page', 'external'],
    ];
  }

  /**
   * Mailto links are classified as internal, not mailto.
   *
   * This is a characterization of a dead branch: isInternal() treats any URL
   * with no host component as internal (via urlHasCurrentDomain()'s
   * empty-host shortcut), and mailto: URLs have no host per PHP's
   * parse_url(). That means the isMailTo() elseif branch in getUrlType() is
   * unreachable -- mailto links are always typed 'internal'. Paired with
   * testGetUrlTypeShouldClassifyMailtoLinksAsMailto() -- delete once the GAP
   * is fixed.
   *
   * @covers ::getUrlType
   */
  public function testGetUrlTypeClassifiesMailtoAsInternal(): void {
    $this->assertSame('internal', $this->extension->getUrlType('mailto:test@yale.edu'));
  }

  /**
   * @covers ::getUrlType
   */
  public function testGetUrlTypeShouldClassifyMailtoLinksAsMailto(): void {
    $this->markTestSkipped(
      'GAP: getUrlType() can never return "mailto". It tests isInternal() before '
      . 'isMailTo(), and isInternal() treats any URL whose parse_url() host is '
      . 'empty as internal -- which every mailto: URL is. Fixing it means moving '
      . 'the isMailTo() check above isInternal(); that is a behavior change, so it '
      . 'is out of scope for the behavior-preserving ys_core cleanup '
      . '(yalesites-org/YaleSites-Internal#579). Delete this test and its '
      . 'characterization pair once the ordering is fixed.'
    );
  }

  /**
   * The Twig extension registers exactly the five documented function names.
   *
   * These names are a public contract: atomic's templates call them
   * directly, so renaming or dropping one breaks rendering with no PHP
   * error. Constraint 2 of the ys_core cleanup issue
   * (yalesites-org/YaleSites-Internal#579) states the names must not change,
   * and nothing pinned them until now -- a class move or namespace change
   * during the refactor could have silently altered this list.
   *
   * @covers ::getFunctions
   */
  public function testGetFunctionsRegistersTheDocumentedTwigFunctionNames(): void {
    $names = array_map(
      static fn($function) => $function->getName(),
      $this->extension->getFunctions()
    );

    $this->assertSame(
      [
        'getCoreSetting',
        'getHeaderSetting',
        'getUrlType',
        'getQueryParam',
        'getAssetPath',
      ],
      $names
    );
  }

  /**
   * An absent manifest falls back to the unversioned asset name.
   *
   * A bogus directory makes both file_exists() checks miss, so this reaches the
   * early return. It cannot tell the two misses apart, though: deleting the
   * _yale-packages fallback would not fail this test. That branch is in fact
   * uncovered -- a normal checkout has a manifest under node_modules, so the
   * first path always wins and the fallback only runs where the theme's npm
   * assets are absent but _yale-packages is populated.
   *
   * @covers ::getAssetPath
   */
  public function testGetAssetPathFallsBackToInputWhenManifestMissing(): void {
    $this->assertSame(
      'icons.svg',
      $this->extension->getAssetPath('icons.svg', 'themes/contrib/does-not-exist')
    );
  }

  /**
   * A known asset resolves to the versioned filename from the manifest.
   *
   * Asserted against the manifest's actual contents rather than a literal hash:
   * the hash changes on every component-library build, so hardcoding one would
   * make this test fail on an unrelated rebuild.
   *
   * @covers ::getAssetPath
   */
  public function testGetAssetPathReturnsVersionedNameFromManifest(): void {
    $manifest = $this->readAssetManifest();
    $asset = array_key_first($manifest);

    // Without this guard the assertion below would pass even if getAssetPath()
    // never read the manifest and just returned its input, whenever the
    // manifest happens to map an asset to its own unversioned name.
    $this->assertNotSame(
      $asset,
      $manifest[$asset],
      'Guard: the manifest actually versions this asset.'
    );

    $this->assertSame($manifest[$asset], $this->extension->getAssetPath($asset));
  }

  /**
   * An asset missing from a present manifest falls back to the input name.
   *
   * @covers ::getAssetPath
   */
  public function testGetAssetPathFallsBackToInputWhenAssetNotInManifest(): void {
    $manifest = $this->readAssetManifest();
    $this->assertArrayNotHasKey('not-a-real-asset.svg', $manifest, 'Guard: the key is genuinely absent.');

    $this->assertSame('not-a-real-asset.svg', $this->extension->getAssetPath('not-a-real-asset.svg'));
  }

  /**
   * Reads the component library asset manifest getAssetPath() resolves against.
   *
   * GetAssetPath() reads the real filesystem relative to DRUPAL_ROOT and offers
   * no seam to inject a fixture, so the manifest-present branches are exercised
   * against the installed manifest and skipped when the theme's npm assets have
   * not been built.
   *
   * @return array
   *   The decoded manifest, asset name => versioned asset name.
   */
  protected function readAssetManifest(): array {
    $base = DRUPAL_ROOT . '/themes/contrib/atomic/';
    $candidates = [
      $base . 'node_modules/@yalesites-org/component-library-twig/dist/manifest.json',
      $base . '_yale-packages/component-library-twig/dist/manifest.json',
    ];

    foreach ($candidates as $path) {
      if (file_exists($path)) {
        $manifest = json_decode(file_get_contents($path), TRUE);
        if (is_array($manifest) && $manifest !== []) {
          return $manifest;
        }
      }
    }

    $this->markTestSkipped(
      'No component library asset manifest is installed. Since the move to the '
      . 'Vite build this is the normal case -- that build emits no manifest, so '
      . 'these manifest-present branches are skipped everywhere rather than only '
      . 'in an unbuilt checkout, and getAssetPath() is effectively a passthrough. '
      . 'testGetAssetPathFallsBackToInputWhenManifestMissing() covers that path.'
    );
  }

}
