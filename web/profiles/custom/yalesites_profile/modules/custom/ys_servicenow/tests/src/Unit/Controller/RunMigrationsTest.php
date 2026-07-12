<?php

namespace Drupal\Tests\ys_servicenow\Unit\Controller;

use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_servicenow\Controller\RunMigrations;
use Drupal\ys_servicenow\ServiceNowManager;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Unit tests for the RunMigrations controller.
 *
 * @coversDefaultClass \Drupal\ys_servicenow\Controller\RunMigrations
 *
 * @group yalesites
 * @group ys_servicenow
 */
class RunMigrationsTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // runAllMigrations() calls Url::fromRoute(...)->toString(), which
    // resolves the url_generator service via the container.
    $url_generator = $this->createMock(UrlGeneratorInterface::class);
    $url_generator->method('generateFromRoute')
      ->with('ys_servicenow.settings')
      ->willReturn('/admin/yalesites/servicenow');

    $container = new ContainerBuilder();
    $container->set('url_generator', $url_generator);
    \Drupal::setContainer($container);
  }

  /**
   * Builds a RunMigrations controller with the given sync-enabled setting.
   */
  protected function buildController(bool $sync_enabled, ServiceNowManager $servicenow_manager, MessengerInterface $messenger): RunMigrations {
    $config_factory = $this->getConfigFactoryStub([
      'ys_servicenow.settings' => ['enable_servicenow_sync' => $sync_enabled],
    ]);

    return new RunMigrations($config_factory, $servicenow_manager, $messenger);
  }

  /**
   * @covers ::runAllMigrations
   */
  public function testRunAllMigrationsRunsSyncAndRedirectsWhenEnabled() {
    $servicenow_manager = $this->createMock(ServiceNowManager::class);
    $servicenow_manager->expects($this->once())->method('runAllMigrations');

    $messages = [];
    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->method('addMessage')->willReturnCallback(function ($message) use (&$messages) {
      $messages[] = $message;
    });

    $controller = $this->buildController(TRUE, $servicenow_manager, $messenger);
    $response = $controller->runAllMigrations();

    $this->assertSame([
      'Running ServiceNow migrations...',
      'ServiceNow migrations complete.',
    ], $messages);
    $this->assertInstanceOf(RedirectResponse::class, $response);
    $this->assertSame('/admin/yalesites/servicenow', $response->getTargetUrl());
  }

  /**
   * @covers ::runAllMigrations
   */
  public function testRunAllMigrationsSkipsSyncAndRedirectsWhenDisabled() {
    $servicenow_manager = $this->createMock(ServiceNowManager::class);
    $servicenow_manager->expects($this->never())->method('runAllMigrations');

    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->once())
      ->method('addMessage')
      ->with('ServiceNow sync is disabled.  No sync was performed.');

    $controller = $this->buildController(FALSE, $servicenow_manager, $messenger);
    $response = $controller->runAllMigrations();

    $this->assertInstanceOf(RedirectResponse::class, $response);
    $this->assertSame('/admin/yalesites/servicenow', $response->getTargetUrl());
  }

}
