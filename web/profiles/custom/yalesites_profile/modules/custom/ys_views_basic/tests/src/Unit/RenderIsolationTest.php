<?php

namespace Drupal\Tests\ys_views_basic\Unit;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Entity\EntityDisplayRepository;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\views\ViewEntityInterface;
use Drupal\views\ViewExecutable;
use Drupal\views\ViewExecutableFactory;
use Drupal\ys_views_basic\ViewsBasicManager;

/**
 * Tests per-block render isolation in ViewsBasicManager (#906 / #1306).
 *
 * @coversDefaultClass \Drupal\ys_views_basic\ViewsBasicManager
 *
 * @group yalesites
 */
class RenderIsolationTest extends UnitTestCase {

  /**
   * The mocked entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The mocked view config entity storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $viewStorage;

  /**
   * The mocked view executable factory.
   *
   * @var \Drupal\views\ViewExecutableFactory
   */
  protected $viewExecutableFactory;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->viewStorage = $this->createMock(EntityStorageInterface::class);
    $term_storage = $this->createMock(EntityStorageInterface::class);
    $this->entityTypeManager->method('getStorage')->willReturnMap([
      ['taxonomy_term', $term_storage],
      ['view', $this->viewStorage],
    ]);
    $this->viewExecutableFactory = $this->createMock(ViewExecutableFactory::class);
  }

  /**
   * Builds a manager under test with mocked dependencies.
   */
  private function manager(): ViewsBasicManager {
    return new ViewsBasicManager(
      $this->entityTypeManager,
      $this->createMock(EntityDisplayRepository::class),
      $this->createMock(RouteMatchInterface::class),
      $this->createMock(CacheTagsInvalidatorInterface::class),
      $this->viewExecutableFactory,
    );
  }

  /**
   * Each call builds the executable from a distinct clone, never the original.
   *
   * This is the core #906 fix: the config entity storage hands back one shared
   * cached instance, so without cloning two blocks would mutate the same
   * display array. Cloning gives every block its own storage.
   *
   * @covers ::initView
   */
  public function testInitViewClonesScaffoldEntity() {
    $cached_entity = $this->createMock(ViewEntityInterface::class);
    // The storage returns the same cached instance on every call.
    $this->viewStorage->method('load')
      ->with('views_basic_scaffold')
      ->willReturn($cached_entity);

    $received = [];
    $this->viewExecutableFactory->method('get')
      ->willReturnCallback(function ($entity) use (&$received) {
        $received[] = $entity;
        return $this->createMock(ViewExecutable::class);
      });

    $manager = $this->manager();
    $manager->initView(['post']);
    $manager->initView(['post']);

    $this->assertCount(2, $received);
    $this->assertNotSame($cached_entity, $received[0], 'First executable is built from a clone, not the cached entity.');
    $this->assertNotSame($cached_entity, $received[1], 'Second executable is built from a clone, not the cached entity.');
    $this->assertNotSame($received[0], $received[1], 'Each block instance gets its own cloned view.');
  }

  /**
   * Events use the dedicated events scaffold view.
   *
   * @covers ::initView
   */
  public function testInitViewUsesEventsScaffoldForEvents() {
    $entity = $this->createMock(ViewEntityInterface::class);
    $this->viewStorage->expects($this->once())
      ->method('load')
      ->with('views_basic_scaffold_events')
      ->willReturn($entity);
    $this->viewExecutableFactory->method('get')
      ->willReturn($this->createMock(ViewExecutable::class));

    $this->manager()->initView(['event']);
  }

  /**
   * A missing scaffold view yields NULL instead of a fatal error.
   *
   * @covers ::initView
   */
  public function testInitViewReturnsNullWhenScaffoldMissing() {
    $this->viewStorage->method('load')->willReturn(NULL);
    $this->assertNull($this->manager()->initView(['post']));
  }

  /**
   * Pager element ids are deterministic, small, and differ per block UUID.
   *
   * @covers ::pagerElementId
   */
  public function testPagerElementIdIsDeterministicAndSmall() {
    $method = new \ReflectionMethod(ViewsBasicManager::class, 'pagerElementId');
    $method->setAccessible(TRUE);
    $manager = $this->manager();

    // crc32(uuid) % 100: 1111... => 29, 2222... => 64 (see commit message).
    $uuid_a = '11111111-1111-1111-1111-111111111111';
    $uuid_b = '22222222-2222-2222-2222-222222222222';

    $a1 = $method->invoke($manager, $uuid_a);
    $a2 = $method->invoke($manager, $uuid_a);
    $b1 = $method->invoke($manager, $uuid_b);

    $this->assertSame($a1, $a2, 'Same UUID yields the same element id across requests.');
    $this->assertGreaterThanOrEqual(0, $a1);
    $this->assertLessThan(100, $a1, 'Element id stays small to keep the ?page= query compact.');
    $this->assertNotSame($a1, $b1, 'Different block UUIDs yield different pager elements.');
  }

  /**
   * show_current_entity does not fall through to the pinned_to_top case.
   *
   * Regression for a missing switch break that made getDefaultParamValue()
   * return the pinned_to_top value when asked for show_current_entity.
   *
   * @covers ::getDefaultParamValue
   */
  public function testShowCurrentEntityDoesNotFallThroughToPinned() {
    $params = json_encode(['show_current_entity' => 1, 'pinned_to_top' => FALSE]);
    $this->assertSame(1, $this->manager()->getDefaultParamValue('show_current_entity', $params));
  }

}
