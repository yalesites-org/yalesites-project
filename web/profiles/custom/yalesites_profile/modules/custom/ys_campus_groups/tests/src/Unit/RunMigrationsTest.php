<?php

namespace Drupal\Tests\ys_campus_groups\Unit;

use Drupal\Core\Messenger\Messenger;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_campus_groups\CampusGroupsManager;
use Drupal\ys_campus_groups\Controller\RunMigrations;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBag;

/**
 * Unit tests for the Campus Groups "Sync now" controller.
 *
 * @coversDefaultClass \Drupal\ys_campus_groups\Controller\RunMigrations
 *
 * @group ys_campus_groups
 * @group yalesites
 */
class RunMigrationsTest extends UnitTestCase {

  /**
   * The exact core warning that decorates a successful sync (see #1394).
   *
   * Core's migrate_drupal module probes the unrelated system_site migration
   * (source plugin "variable") during migration-definition discovery; with no
   * legacy source database it queues this messenger error even though the
   * Campus Groups sync succeeded.
   */
  const NOISE_WARNING = 'Failed to connect to your database server. The server reports the following message: No database connection configured for source plugin variable.';

  /**
   * Builds the controller with a real in-memory messenger under test.
   *
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger the controller and the (mocked) manager share.
   * @param bool $enabled
   *   Whether Campus Groups syncing is enabled in config.
   *
   * @return \Drupal\ys_campus_groups\Controller\RunMigrations
   *   The controller instance.
   */
  private function buildController(MessengerInterface $messenger, bool $enabled): RunMigrations {
    $config_factory = $this->getConfigFactoryStub([
      'ys_campus_groups.settings' => ['enable_campus_groups_sync' => $enabled],
    ]);

    // The manager "runs" the sync: it reports 8 imported events and, like the
    // real core probe, queues the spurious database warning while doing so.
    $manager = $this->createMock(CampusGroupsManager::class);
    $manager->method('runAllMigrations')->willReturnCallback(
      function () use ($messenger) {
        $messenger->addError(self::NOISE_WARNING);
        return ['campus_groups_events' => ['imported' => 8]];
      }
    );

    // A referer keeps getRedirectUrl() off the (container-backed) route path.
    $request = Request::create('/admin/yalesites/campus_groups/sync');
    $request->server->set('HTTP_REFERER', 'https://example.com/admin/yalesites/campus_groups');
    $request_stack = new RequestStack();
    $request_stack->push($request);

    return new RunMigrations($config_factory, $manager, $messenger, $request_stack);
  }

  /**
   * Casts the messenger's messages of a type to plain strings.
   *
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger to read.
   * @param string $type
   *   A MessengerInterface::TYPE_* constant.
   *
   * @return string[]
   *   The messages as strings.
   */
  private function messages(MessengerInterface $messenger, string $type): array {
    return array_map('strval', $messenger->messagesByType($type));
  }

  /**
   * The spurious database warning is stripped, real messages are preserved.
   *
   * @covers ::runAllMigrations
   */
  public function testSpuriousDatabaseWarningIsRemovedFromSyncScreen(): void {
    $messenger = new Messenger(new FlashBag(), new KillSwitch());

    // A pre-existing unrelated error and a *different* source-plugin error must
    // both survive -- the filter is deliberately narrow to that one message.
    $messenger->addError('Something else went wrong.');
    $messenger->addError('No database connection configured for source plugin foo_bar');

    $controller = $this->buildController($messenger, TRUE);
    $controller->runAllMigrations();

    $errors = $this->messages($messenger, MessengerInterface::TYPE_ERROR);
    foreach ($errors as $error) {
      $this->assertStringNotContainsString('source plugin variable', $error);
    }
    $this->assertContains('Something else went wrong.', $errors);
    $this->assertContains('No database connection configured for source plugin foo_bar', $errors);

    $status = $this->messages($messenger, MessengerInterface::TYPE_STATUS);
    $this->assertContains('Synchronized 8 events.', $status);
  }

}
