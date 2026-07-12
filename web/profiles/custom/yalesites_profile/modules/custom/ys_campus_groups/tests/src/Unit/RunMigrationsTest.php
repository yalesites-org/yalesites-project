<?php

namespace Drupal\Tests\ys_campus_groups\Unit;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_campus_groups\CampusGroupsManager;
use Drupal\ys_campus_groups\Controller\RunMigrations;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Unit tests for the RunMigrations controller.
 *
 * @coversDefaultClass \Drupal\ys_campus_groups\Controller\RunMigrations
 *
 * @group ys_campus_groups
 * @group yalesites
 */
class RunMigrationsTest extends UnitTestCase {

  /**
   * The mocked config object for 'ys_campus_groups.settings'.
   *
   * @var \Drupal\Core\Config\ImmutableConfig|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $config;

  /**
   * The mocked Campus Groups manager.
   *
   * @var \Drupal\ys_campus_groups\CampusGroupsManager|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $campusGroupsManager;

  /**
   * The mocked messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $messenger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->config = $this->createMock(ImmutableConfig::class);
    $this->campusGroupsManager = $this->createMock(CampusGroupsManager::class);
    $this->messenger = $this->createMock(MessengerInterface::class);
  }

  /**
   * Builds the controller with a request carrying the given referer.
   *
   * @param string|null $referer
   *   The HTTP_REFERER value, or NULL to omit it.
   */
  protected function createController(?string $referer): RunMigrations {
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')
      ->with('ys_campus_groups.settings')
      ->willReturn($this->config);

    $request = Request::create('/admin/yalesites/campus_groups/sync');
    if ($referer !== NULL) {
      $request->server->set('HTTP_REFERER', $referer);
    }
    $request_stack = new RequestStack();
    $request_stack->push($request);

    return new RunMigrations($config_factory, $this->campusGroupsManager, $this->messenger, $request_stack);
  }

  /**
   * @covers ::runAllMigrations
   */
  public function testRunAllMigrationsShowsImportedCountAndRedirectsToReferer(): void {
    $this->config->method('get')->with('enable_campus_groups_sync')->willReturn(TRUE);
    $this->campusGroupsManager->expects($this->once())
      ->method('runAllMigrations')
      ->willReturn(['campus_groups_events' => ['imported' => 5]]);

    $this->messenger->expects($this->once())
      ->method('addStatus')
      ->with('Synchronized 5 events.');

    $response = $this->createController('/admin/yalesites/campus_groups')->runAllMigrations();

    $this->assertSame('/admin/yalesites/campus_groups', $response->getTargetUrl());
  }

  /**
   * @covers ::runAllMigrations
   */
  public function testRunAllMigrationsShowsErrorWhenSyncDisabled(): void {
    $this->config->method('get')->with('enable_campus_groups_sync')->willReturn(FALSE);
    $this->campusGroupsManager->expects($this->never())->method('runAllMigrations');

    $this->messenger->expects($this->once())
      ->method('addError')
      ->with('Campus Groups syncing is not enabled.');

    $this->createController('/admin/yalesites/campus_groups')->runAllMigrations();
  }

  /**
   * @covers ::getRedirectUrl
   */
  public function testGetRedirectUrlFallsBackToFrontPageWhenNoReferer(): void {
    $this->config->method('get')->with('enable_campus_groups_sync')->willReturn(FALSE);

    $url_generator = $this->createMock(UrlGeneratorInterface::class);
    $url_generator->method('generateFromRoute')->with('<front>')->willReturn('/');
    $container = new ContainerBuilder();
    $container->set('url_generator', $url_generator);
    \Drupal::setContainer($container);

    $response = $this->createController(NULL)->runAllMigrations();

    $this->assertSame('/', $response->getTargetUrl());
  }

  /**
   * @covers ::create
   */
  public function testCreateReturnsRunMigrationsInstance(): void {
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->willReturn($this->config);

    $services = [
      'config.factory' => $config_factory,
      'ys_campus_groups.manager' => $this->campusGroupsManager,
      'messenger' => $this->messenger,
      'request_stack' => new RequestStack(),
    ];
    $container = $this->createMock(ContainerInterface::class);
    $container->method('get')->willReturnCallback(fn (string $id) => $services[$id]);

    $this->assertInstanceOf(RunMigrations::class, RunMigrations::create($container));
  }

}
