<?php

namespace Drupal\Tests\ys_localist\Unit\Controller;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_localist\Controller\RunMigrations;
use Drupal\ys_localist\LocalistManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Unit tests for the RunMigrations controller.
 *
 * @coversDefaultClass \Drupal\ys_localist\Controller\RunMigrations
 *
 * @group ys_localist
 * @group yalesites
 */
class RunMigrationsTest extends UnitTestCase {

  /**
   * The mocked config object for 'ys_localist.settings'.
   *
   * @var \Drupal\Core\Config\ImmutableConfig|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $config;

  /**
   * The mocked Localist manager.
   *
   * @var \Drupal\ys_localist\LocalistManager|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $localistManager;

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
    $this->localistManager = $this->createMock(LocalistManager::class);
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
      ->with('ys_localist.settings')
      ->willReturn($this->config);

    $request = Request::create('/admin/yalesites/localist/sync');
    if ($referer !== NULL) {
      $request->server->set('HTTP_REFERER', $referer);
    }
    $request_stack = new RequestStack();
    $request_stack->push($request);

    return new RunMigrations($config_factory, $this->localistManager, $this->messenger, $request_stack);
  }

  /**
   * @covers ::runAllMigrations
   */
  public function testRunAllMigrationsShowsImportedCountAndRedirectsToReferer(): void {
    $this->config->method('get')->with('enable_localist_sync')->willReturn(TRUE);
    $this->localistManager->expects($this->once())
      ->method('runAllMigrations')
      ->willReturn(['localist_events' => ['imported' => 5]]);

    $this->messenger->expects($this->once())
      ->method('addStatus')
      ->with('Synchronized 5 events.');

    $response = $this->createController('/admin/yalesites/localist')->runAllMigrations();

    $this->assertSame('/admin/yalesites/localist', $response->getTargetUrl());
  }

  /**
   * @covers ::runAllMigrations
   */
  public function testRunAllMigrationsShowsErrorWhenSyncDisabled(): void {
    $this->config->method('get')->with('enable_localist_sync')->willReturn(FALSE);
    $this->localistManager->expects($this->never())->method('runAllMigrations');

    $this->messenger->expects($this->once())
      ->method('addError')
      ->with('Localist sync is not enabled. No sync was performed.');

    $this->createController('/admin/yalesites/localist')->runAllMigrations();
  }

  /**
   * @covers ::getRedirectUrl
   */
  public function testGetRedirectUrlFallsBackToFrontPageWhenNoReferer(): void {
    $this->config->method('get')->with('enable_localist_sync')->willReturn(FALSE);

    $url_generator = $this->createMock(UrlGeneratorInterface::class);
    $url_generator->method('generateFromRoute')->with('<front>')->willReturn('/');
    $container = new ContainerBuilder();
    $container->set('url_generator', $url_generator);
    \Drupal::setContainer($container);

    $response = $this->createController(NULL)->runAllMigrations();

    $this->assertSame('/', $response->getTargetUrl());
  }

  /**
   * @covers ::syncGroups
   */
  public function testSyncGroupsRunsGroupMigrationAndRemovesOldExperiencesWhenEndpointValid(): void {
    $this->config->method('get')->with('enable_localist_sync')->willReturn(TRUE);
    $this->localistManager->method('checkGroupsEndpoint')->willReturn(TRUE);
    $this->localistManager->expects($this->once())->method('runMigration')->with('localist_groups');
    $this->localistManager->expects($this->once())->method('removeOldExperiences');

    $this->messenger->expects($this->once())
      ->method('addStatus')
      ->with('Successfully imported Localist groups.');

    $response = $this->createController('/admin/yalesites/localist')->syncGroups();

    $this->assertSame('/admin/yalesites/localist', $response->getTargetUrl());
  }

  /**
   * @covers ::syncGroups
   */
  public function testSyncGroupsShowsErrorWhenEndpointInvalid(): void {
    $this->config->method('get')->with('enable_localist_sync')->willReturn(TRUE);
    $this->localistManager->method('checkGroupsEndpoint')->willReturn(FALSE);
    $this->localistManager->expects($this->never())->method('runMigration');

    $this->messenger->expects($this->once())
      ->method('addError')
      ->with('Error getting groups. Check that the endpoint is correct.');

    $this->createController('/admin/yalesites/localist')->syncGroups();
  }

  /**
   * @covers ::syncGroups
   */
  public function testSyncGroupsShowsErrorWhenSyncDisabled(): void {
    $this->config->method('get')->with('enable_localist_sync')->willReturn(FALSE);
    $this->localistManager->expects($this->never())->method('checkGroupsEndpoint');

    $this->messenger->expects($this->once())
      ->method('addError')
      ->with('Localist sync is not enabled. No sync was performed.');

    $this->createController('/admin/yalesites/localist')->syncGroups();
  }

  /**
   * @covers ::create
   */
  public function testCreateReturnsRunMigrationsInstance(): void {
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->willReturn($this->config);

    $services = [
      'config.factory' => $config_factory,
      'ys_localist.manager' => $this->localistManager,
      'messenger' => $this->messenger,
      'request_stack' => new RequestStack(),
    ];
    $container = $this->createMock(ContainerInterface::class);
    $container->method('get')->willReturnCallback(fn (string $id) => $services[$id]);

    $this->assertInstanceOf(RunMigrations::class, RunMigrations::create($container));
  }

}
