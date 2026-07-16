<?php

namespace Drupal\Tests\ys_starterkit\Unit\Plugin\Action;

use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\single_content_sync\ContentFileGeneratorInterface;
use Drupal\single_content_sync\ContentSyncHelperInterface;
use Drupal\single_content_sync\Plugin\Action\ContentBulkExport;
use Drupal\ys_starterkit\Plugin\Action\MediaBulkExport;
use Drupal\ys_starterkit\Plugin\Action\TaxonomyBulkExport;

/**
 * Unit tests for the ys_starterkit bulk export actions.
 *
 * MediaBulkExport and TaxonomyBulkExport add no logic of their own -- each is
 * an empty subclass of single_content_sync's ContentBulkExport, whose
 * configuration and access-checking behavior is exercised here via the
 * ys_starterkit class that is actually instantiated in production.
 *
 * ContentBulkExport::executeMultiple() is not covered: it builds a status
 * message via Link::createFromRoute(), which requires the routing/URL
 * generation services (a Kernel-level dependency) rather than the container
 * these unit tests otherwise avoid.
 *
 * @group yalesites
 * @group ys_starterkit
 */
class BulkExportActionsTest extends UnitTestCase {

  /**
   * The mocked content file generator.
   *
   * @var \Drupal\single_content_sync\ContentFileGeneratorInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $fileGenerator;

  /**
   * The mocked content sync helper.
   *
   * @var \Drupal\single_content_sync\ContentSyncHelperInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $contentSyncHelper;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->fileGenerator = $this->createMock(ContentFileGeneratorInterface::class);
    $this->contentSyncHelper = $this->createMock(ContentSyncHelperInterface::class);

    // access() calls AccessResult::allowedIfHasPermission(), which validates
    // cache contexts via the cache_contexts_manager service, and
    // buildConfigurationForm() uses $this->t(). Provide a minimal container so
    // these container-backed calls resolve in this unit test.
    $cache_contexts_manager = $this->createMock(CacheContextsManager::class);
    $cache_contexts_manager->method('assertValidTokens')->willReturn(TRUE);
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $cache_contexts_manager);
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);
  }

  /**
   * Data provider of the ys_starterkit bulk export action classes.
   *
   * @return array
   *   Sets of arguments for the test methods.
   */
  public static function bulkExportActionProvider(): array {
    return [
      'media' => [MediaBulkExport::class],
      'taxonomy' => [TaxonomyBulkExport::class],
    ];
  }

  /**
   * Builds an action instance with the mocked dependencies.
   *
   * @param string $class
   *   The action class to instantiate.
   * @param array $configuration
   *   The plugin configuration.
   *
   * @return \Drupal\single_content_sync\Plugin\Action\ContentBulkExport
   *   The action instance.
   */
  protected function createAction(string $class, array $configuration = []): ContentBulkExport {
    return new $class(
      $configuration,
      'test_id',
      [],
      $this->fileGenerator,
      $this->contentSyncHelper
    );
  }

  /**
   * Tests defaultConfiguration() returns the expected defaults.
   *
   * @dataProvider bulkExportActionProvider
   * @covers \Drupal\single_content_sync\Plugin\Action\ContentBulkExport::defaultConfiguration
   */
  public function testDefaultConfiguration(string $class): void {
    $action = $this->createAction($class);
    $this->assertSame([
      'assets' => TRUE,
      'translation' => TRUE,
    ], $action->defaultConfiguration());
  }

  /**
   * Tests the constructor applies defaults to empty configuration.
   *
   * @dataProvider bulkExportActionProvider
   * @covers \Drupal\single_content_sync\Plugin\Action\ContentBulkExport::__construct
   */
  public function testConstructorAppliesDefaultsToEmptyConfiguration(string $class): void {
    $action = $this->createAction($class, []);
    $this->assertSame([
      'assets' => TRUE,
      'translation' => TRUE,
    ], $action->getConfiguration());
  }

  /**
   * Tests access() is allowed with permission and helper approval.
   *
   * @dataProvider bulkExportActionProvider
   * @covers \Drupal\single_content_sync\Plugin\Action\ContentBulkExport::access
   */
  public function testAccessAllowedWithPermissionAndHelperAllows(string $class): void {
    $action = $this->createAction($class);
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->with('export single content')->willReturn(TRUE);
    $this->contentSyncHelper->method('access')->willReturn(TRUE);

    $this->assertTrue($action->access($this->createMock(EntityInterface::class), $account));
  }

  /**
   * Tests access() is denied without the export permission.
   *
   * @dataProvider bulkExportActionProvider
   * @covers \Drupal\single_content_sync\Plugin\Action\ContentBulkExport::access
   */
  public function testAccessDeniedWithoutPermission(string $class): void {
    $action = $this->createAction($class);
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturn(FALSE);
    $this->contentSyncHelper->method('access')->willReturn(TRUE);

    $this->assertFalse($action->access($this->createMock(EntityInterface::class), $account));
  }

  /**
   * Tests access() is forbidden with a cache tag when the helper denies.
   *
   * @dataProvider bulkExportActionProvider
   * @covers \Drupal\single_content_sync\Plugin\Action\ContentBulkExport::access
   */
  public function testAccessForbiddenWhenHelperDenies(string $class): void {
    $action = $this->createAction($class);
    $account = $this->createMock(AccountInterface::class);
    // Permission granted, but the helper still overrides to forbidden.
    $account->method('hasPermission')->willReturn(TRUE);
    $this->contentSyncHelper->method('access')->willReturn(FALSE);

    $result = $action->access($this->createMock(EntityInterface::class), $account, TRUE);
    $this->assertFalse($result->isAllowed());
    $this->assertTrue($result->isForbidden());
    $this->assertSame(['config:single_content_sync.settings'], $result->getCacheTags());
  }

  /**
   * Tests buildConfigurationForm() reflects the current configuration.
   *
   * @dataProvider bulkExportActionProvider
   * @covers \Drupal\single_content_sync\Plugin\Action\ContentBulkExport::buildConfigurationForm
   */
  public function testBuildConfigurationFormReflectsCurrentConfiguration(string $class): void {
    $action = $this->createAction($class, ['assets' => FALSE, 'translation' => TRUE]);
    $form = $action->buildConfigurationForm([], new FormState());

    $this->assertFalse($form['assets']['#default_value']);
    $this->assertTrue($form['translation']['#default_value']);
  }

  /**
   * Tests submitConfigurationForm() updates the stored configuration.
   *
   * @dataProvider bulkExportActionProvider
   * @covers \Drupal\single_content_sync\Plugin\Action\ContentBulkExport::submitConfigurationForm
   */
  public function testSubmitConfigurationFormUpdatesConfiguration(string $class): void {
    $action = $this->createAction($class);
    $form = [];
    $form_state = new FormState();
    $form_state->setValues(['assets' => FALSE, 'translation' => FALSE]);

    $action->submitConfigurationForm($form, $form_state);

    $this->assertSame([
      'assets' => FALSE,
      'translation' => FALSE,
    ], $action->getConfiguration());
  }

  /**
   * Tests execute() is a no-op that returns NULL.
   *
   * @dataProvider bulkExportActionProvider
   * @covers \Drupal\single_content_sync\Plugin\Action\ContentBulkExport::execute
   */
  public function testExecuteReturnsNull(string $class): void {
    $action = $this->createAction($class);
    $this->assertNull($action->execute(new \stdClass()));
  }

}
