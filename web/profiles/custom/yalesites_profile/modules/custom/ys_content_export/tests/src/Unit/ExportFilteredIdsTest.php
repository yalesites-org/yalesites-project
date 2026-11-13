<?php

namespace Drupal\Tests\ys_content_export\Unit;

use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\Messenger;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Tests\UnitTestCase;
use Drupal\views\ViewEntityInterface;
use Drupal\views\ViewExecutable;
use Drupal\views\ViewExecutableFactory;
use Drupal\ys_content_export\Controller\ExportControllerBase;
use Drupal\ys_content_export\Controller\PeopleExportController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBag;

/**
 * Unit tests for how the exports read the filtered ids from an admin view.
 *
 * @coversDefaultClass \Drupal\ys_content_export\Controller\ExportControllerBase
 * @group ys_content_export
 * @group yalesites
 */
class ExportFilteredIdsTest extends UnitTestCase {

  /**
   * The error the exposed form queues for a filter value it rejects.
   */
  const FORM_ERROR = 'The submitted value nosuchrole in the role element is not allowed.';

  /**
   * The messenger shared by the controller and the simulated exposed form.
   *
   * @var \Drupal\Core\Messenger\Messenger
   */
  protected $messenger;

  /**
   * The exposed input the controller handed the view.
   *
   * @var array|null
   */
  protected $exposedInput;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->messenger = new Messenger(new FlashBag(), $this->createMock(KillSwitch::class));
  }

  /**
   * Runs filteredIds over a mocked view.
   *
   * @param callable $build
   *   Stands in for the view's build(): given the mocked view, sets its
   *   build_info and queues messages as core would.
   * @param array $query
   *   The export request's query string.
   *
   * @return int[]
   *   What filteredIds returned.
   */
  protected function filteredIds(callable $build, array $query): array {
    $view = $this->createMock(ViewExecutable::class);
    $view->method('setExposedInput')->willReturnCallback(function (array $input): void {
      $this->exposedInput = $input;
    });
    $view->method('build')->willReturnCallback(fn() => $build($view));

    $view_storage = $this->createMock(EntityStorageInterface::class);
    $view_storage->method('load')->with('user_admin_people')->willReturn($this->createMock(ViewEntityInterface::class));
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('view')->willReturn($view_storage);
    $factory = $this->createMock(ViewExecutableFactory::class);
    $factory->method('get')->willReturn($view);
    $container = new ContainerBuilder();
    $container->set('entity_type.manager', $manager);
    $container->set('views.executable', $factory);
    \Drupal::setContainer($container);

    $controller = new PeopleExportController($manager, $this->createMock(DateFormatterInterface::class));
    $controller->setMessenger($this->messenger);
    return (new \ReflectionMethod(ExportControllerBase::class, 'filteredIds'))
      ->invoke($controller, 'user_admin_people', 'uid', Request::create('/admin/people/export', 'GET', $query));
  }

  /**
   * Builds the way core does when the exposed form rejects a filter value.
   *
   * Core queues the form errors, flags the build aborted, and returns before
   * it creates a query, leaving build_info's query an empty string.
   *
   * @param \Drupal\views\ViewExecutable $view
   *   The mocked view.
   */
  protected function rejectFilters(ViewExecutable $view): void {
    $this->messenger->addError(self::FORM_ERROR);
    $view->build_info = ['query' => '', 'count_query' => '', 'query_args' => [], 'abort' => TRUE];
  }

  /**
   * Tests a filter value the view rejects exports no rows.
   *
   * @covers ::filteredIds
   */
  public function testRejectedFilterExportsNoRows(): void {
    $ids = $this->filteredIds([$this, 'rejectFilters'], ['role' => 'nosuchrole']);
    $this->assertSame([], $ids);
  }

  /**
   * Tests a rejected filter's form errors are not left queued for later.
   *
   * A download never shows messages, so errors left in the queue would show up
   * on whatever page the admin opens next. Errors queued before the export
   * stay.
   *
   * @covers ::filteredIds
   */
  public function testRejectedFilterErrorsAreNotLeftQueued(): void {
    $this->messenger->addError('An earlier error');
    $this->filteredIds([$this, 'rejectFilters'], ['role' => 'nosuchrole']);
    $this->assertSame(['An earlier error'], array_map('strval', $this->messenger->messagesByType(MessengerInterface::TYPE_ERROR)));
  }

  /**
   * Tests valid filters return the view's ids, in order, once each.
   *
   * @covers ::filteredIds
   */
  public function testValidFiltersReturnViewIds(): void {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAll')->willReturn([
      (object) ['uid' => '3'],
      (object) ['uid' => '1'],
      (object) ['uid' => '3'],
    ]);
    $select = $this->createMock(SelectInterface::class);
    $select->method('execute')->willReturn($statement);
    $built = function (ViewExecutable $view) use ($select): void {
      $view->build_info = ['query' => $select, 'count_query' => $select, 'query_args' => []];
    };

    $ids = $this->filteredIds($built, ['role' => 'editor', 'page' => '2']);

    $this->assertSame([3, 1], $ids);
    $this->assertSame(['role' => 'editor'], $this->exposedInput, 'The pager page is not replayed.');
  }

}
