<?php

namespace Drupal\Tests\ys_core\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_core\CoreTwigExtension;
use Drupal\ys_core\YaleSitesMediaManager;
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
   * @var \Drupal\ys_core\YaleSitesMediaManager|\PHPUnit\Framework\MockObject\MockObject
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
    $this->markTestSkipped('GAP: getUrlType() never returns "mailto" because isInternal() already matches any host-less URL -- see ~/Documents/Claude/not_dave/module-tests-20260710/ys_core.md');
  }

}
