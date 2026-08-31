<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_beacon\BeaconAuthorization;
use Drupal\ys_beacon\Service\PdfTextIndexer;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tests that the PDF backfill deploy hook only runs where Beacon is on.
 *
 * Beacon is authorized per site by a platform administrator, so most sites
 * receiving this deploy do not have it switched off-and-on but simply off. The
 * per-item queueing helper already refuses to queue there, but the enumeration
 * feeding it - an entity query over the media library plus chunked entity
 * loads - would still run on every site in the platform to achieve nothing.
 * These tests prove the hook decides once, before touching any content.
 *
 * The container deliberately omits entity_type.manager: if the hook ever gets
 * past the guard it fails loudly rather than silently doing the work.
 *
 * @group ys_beacon
 */
class BeaconPdfBackfillDeployTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 3) . '/ys_beacon.deploy.php';
  }

  /**
   * An unauthorized site queues nothing and never reads its media library.
   */
  public function testSkipsWhenBeaconIsNotAuthorized(): void {
    $this->containerWith(authorized: FALSE, indexName: 'some-index');

    $sandbox = [];
    $result = ys_beacon_deploy_10002($sandbox);

    $this->assertSame(
      'Beacon is not enabled on this site; no PDF text extraction was queued.',
      (string) $result,
    );
    $this->assertSame(1, $sandbox['#finished'], 'The hook reports itself complete.');
  }

  /**
   * A site with no Azure index configured is skipped for the same reason.
   */
  public function testSkipsWhenNoIndexNameIsConfigured(): void {
    $this->containerWith(authorized: TRUE, indexName: '');

    $sandbox = [];
    $result = ys_beacon_deploy_10002($sandbox);

    $this->assertSame(
      'Beacon is not enabled on this site; no PDF text extraction was queued.',
      (string) $result,
    );
    $this->assertSame(1, $sandbox['#finished']);
  }

  /**
   * An enabled site gets past the guard and consults the pending documents.
   */
  public function testEnabledSiteEnumeratesPendingDocuments(): void {
    $indexer = $this->createMock(PdfTextIndexer::class);
    $indexer->expects($this->once())->method('pendingMediaIds')->willReturn([]);
    $this->containerWith(authorized: TRUE, indexName: 'some-index', indexer: $indexer);

    $sandbox = [];
    $result = ys_beacon_deploy_10002($sandbox);

    $this->assertSame(
      'No PDF documents were waiting for text extraction.',
      (string) $result,
      'With nothing pending the hook completes without loading any media.',
    );
    $this->assertSame(1, $sandbox['#finished']);
  }

  /**
   * Builds the minimal container the guard needs.
   *
   * @param bool $authorized
   *   Whether a platform administrator has authorized Beacon for the site.
   * @param string $indexName
   *   The configured Azure index name.
   * @param \Drupal\ys_beacon\Service\PdfTextIndexer|null $indexer
   *   The PDF text indexer, when the test expects the guard to be passed.
   */
  private function containerWith(bool $authorized, string $indexName, ?PdfTextIndexer $indexer = NULL): void {
    $authorization = $this->createMock(BeaconAuthorization::class);
    $authorization->method('isAuthorized')->willReturn($authorized);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('azure_index_name')->willReturn($indexName);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('ys_beacon.settings')->willReturn($config);

    $container = new ContainerBuilder();
    // setUp() has already required the deploy file, so the module handler only
    // has to accept the loadInclude() call.
    $container->set('module_handler', $this->createMock(ModuleHandlerInterface::class));
    $container->set('ys_beacon.authorization', $authorization);
    $container->set('config.factory', $configFactory);
    $container->set('string_translation', $this->getStringTranslationStub());
    if ($indexer) {
      $container->set('ys_beacon.pdf_text_indexer', $indexer);
    }
    \Drupal::setContainer($container);
  }

}
