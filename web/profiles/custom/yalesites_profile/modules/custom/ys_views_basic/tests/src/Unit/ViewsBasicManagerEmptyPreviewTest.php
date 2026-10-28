<?php

namespace Drupal\Tests\ys_views_basic\Unit;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Entity\EntityDisplayRepository;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\taxonomy\TermStorageInterface;
use Drupal\views\Plugin\views\display\DisplayPluginBase;
use Drupal\views\ViewExecutable;
use Drupal\views\ViewExecutableFactory;
use Drupal\ys_views_basic\Service\ExposedTaxonomyFilterOptions;
use Drupal\ys_views_basic\ViewsBasicManager;

/**
 * A listing whose preview renders nothing yields an empty render array.
 *
 * ViewExecutable::preview() returns NULL when ViewExecutable::render() bails
 * out on a failed or denied build, and FALSE when the display cannot be set.
 * SetupView() used to read '#rows' off that value straight away, which logged
 * "Trying to access array offset on null" while the search index rendered
 * pages (yalesites-org/YaleSites-Internal#1970).
 *
 * @coversDefaultClass \Drupal\ys_views_basic\ViewsBasicManager
 * @group ys_views_basic
 * @group yalesites
 */
class ViewsBasicManagerEmptyPreviewTest extends UnitTestCase {

  /**
   * The manager under test.
   *
   * @var \Drupal\ys_views_basic\ViewsBasicManager
   */
  protected $manager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->willReturn($this->createMock(TermStorageInterface::class));

    $this->manager = new ViewsBasicManager(
      $entityTypeManager,
      $this->createMock(EntityDisplayRepository::class),
      $this->createMock(RouteMatchInterface::class),
      $this->createMock(CacheTagsInvalidatorInterface::class),
      $this->createMock(ViewExecutableFactory::class),
      $this->createMock(ExposedTaxonomyFilterOptions::class),
    );
  }

  /**
   * Provides preview() results that carry no render array.
   *
   * @return array
   *   Test cases keyed by description.
   */
  public static function emptyPreviewProvider(): array {
    return [
      'render bailed out (NULL)' => [NULL],
      'display could not be set (FALSE)' => [FALSE],
    ];
  }

  /**
   * An empty preview becomes an empty render array, with no PHP warning.
   *
   * PHPUnit turns the warning into an error, so the old code fails here.
   *
   * @dataProvider emptyPreviewProvider
   * @covers ::setupView
   */
  public function testEmptyPreviewReturnsEmptyRenderArray($preview) {
    $display = $this->createMock(DisplayPluginBase::class);
    $display->method('getOption')->willReturn([]);

    $view = $this->createMock(ViewExecutable::class);
    $view->method('getDisplay')->willReturn($display);
    $view->expects($this->once())->method('execute');
    $view->expects($this->once())->method('preview')->willReturn($preview);

    $this->manager->setupView($view, json_encode(['filters' => ['types' => ['post']]]));

    $this->assertSame([], $view);
  }

  /**
   * A preview with rows still gets the per-row cache keys.
   *
   * @covers ::setupView
   */
  public function testPreviewWithRowsStillKeysEachRow() {
    $display = $this->createMock(DisplayPluginBase::class);
    $display->method('getOption')->willReturn([]);

    $view = $this->createMock(ViewExecutable::class);
    $view->method('getDisplay')->willReturn($display);
    $view->method('preview')->willReturn([
      '#rows' => ['#rows' => [['#markup' => 'row']]],
    ]);

    $this->manager->setupView($view, json_encode(['filters' => ['types' => ['post']]]));

    $row = $view['#rows']['#rows'][0];
    $this->assertSame('row', $row['#markup']);
    $this->assertNotEmpty($row['#cache']['keys']);
    $this->assertContains('url.query_args:page', $row['#cache']['contexts']);
  }

}
