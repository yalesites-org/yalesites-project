<?php

namespace Drupal\Tests\ys_markdown\Kernel;

use Drupal\Core\Http\Exception\CacheableNotFoundHttpException;
use Drupal\Tests\ys_core\Kernel\YsKernelTestBase;
use Drupal\ys_markdown\Controller\AiDirectivesController;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\path_alias\Entity\PathAlias;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Tests the dynamic robots.txt and llms.txt routes.
 *
 * @group ys_markdown
 */
class AiDirectivesRouteTest extends YsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'node', 'field', 'filter', 'text', 'path_alias',
    'ys_markdown',
  ];

  /**
   * {@inheritdoc}
   *
   * The ys_core.site config has no schema in this minimal module set, so
   * strict schema checking is disabled here.
   */
  // phpcs:ignore DrupalPractice.Objects.StrictSchemaDisabled.StrictConfigSchema
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   *
   * Enabling ys_beacon would pull in the AI stack, so its indexability service
   * is replaced by a small stand-in that the module's own services.yml uses.
   */
  public function register(ContainerBuilder $container) {
    parent::register($container);
    $container->register('ys_beacon.indexability', PublishedAnonymousIndexability::class);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->installConfig(['node', 'filter', 'system']);
    $this->installSchema('node', ['node_access']);
    $type = NodeType::create(['type' => 'page', 'name' => 'Page', 'display_submitted' => FALSE]);
    $type->save();
    node_add_body_field($type);
    Role::create(['id' => RoleInterface::ANONYMOUS_ID, 'label' => 'Anonymous'])
      ->grantPermission('access content')
      ->save();
  }

  /**
   * Fetches a path through the kernel.
   */
  protected function get(string $path) {
    return $this->container->get('http_kernel')->handle(Request::create($path));
  }

  /**
   * Fetches a path that must answer 404, returning the exception.
   *
   * Errors are not caught so the test does not depend on a rendered 404 page.
   */
  protected function get404(string $path): CacheableNotFoundHttpException {
    try {
      $this->container->get('http_kernel')->handle(Request::create($path), HttpKernelInterface::MAIN_REQUEST, FALSE);
    }
    catch (CacheableNotFoundHttpException $e) {
      return $e;
    }
    $this->fail('Expected a cacheable 404 for ' . $path);
  }

  /**
   * Returns the expected base robots.txt: core's file plus the site rule.
   */
  protected function baseRobots(): string {
    return file_get_contents(DRUPAL_ROOT . '/core/assets/scaffold/files/robots.txt')
      . "\n# Disallow ?page= params\nDisallow: /*?page=\n";
  }

  /**
   * With the toggle off, robots.txt is exactly the base file.
   */
  public function testRobotsToggleOffIsBaseFile(): void {
    $this->config('ys_core.site')->set('ai_readability.block_ai_crawlers', FALSE)->save();
    $response = $this->get('/robots.txt');
    $this->assertSame(200, $response->getStatusCode());
    $this->assertStringStartsWith('text/plain', $response->headers->get('Content-Type'));
    $this->assertSame($this->baseRobots(), $response->getContent());
    $this->assertContains('config:ys_core.site', $response->getCacheableMetadata()->getCacheTags());
  }

  /**
   * With the toggle on, only AI training crawlers are blocked.
   */
  public function testRobotsToggleOnBlocksTrainingBots(): void {
    $this->config('ys_core.site')->set('ai_readability.block_ai_crawlers', TRUE)->save();
    $content = $this->get('/robots.txt')->getContent();
    foreach (AiDirectivesController::AI_TRAINING_CRAWLERS as $bot) {
      $this->assertStringContainsString('User-agent: ' . $bot . "\n", $content);
    }
    $this->assertStringContainsString("Disallow: /\n", $content);
    $this->assertStringStartsWith($this->baseRobots() . "\nUser-agent: GPTBot", $content);
    foreach (['OAI-SearchBot', 'Claude-SearchBot', 'PerplexityBot'] as $bot) {
      $this->assertStringNotContainsString($bot, $content);
    }
  }

  /**
   * A missing key means on; switching it off removes the block.
   */
  public function testRobotsMissingKeyBlocks(): void {
    $config = $this->config('ys_core.site');
    $config->clear('ai_readability.block_ai_crawlers')->save();
    $this->assertStringContainsString('User-agent: GPTBot', $this->get('/robots.txt')->getContent());
    $config->set('ai_readability.block_ai_crawlers', FALSE)->save();
    $this->assertStringNotContainsString('GPTBot', $this->get('/robots.txt')->getContent());
  }

  /**
   * With Markdown off, llms.txt is a cacheable 404.
   */
  public function testLlmsOffIs404(): void {
    $this->config('ys_core.site')->set('ai_readability.markdown_enabled', FALSE)->save();
    $e = $this->get404('/llms.txt');
    $this->assertLlmsTags($e->getCacheTags());
  }

  /**
   * Asserts the tags llms.txt depends on.
   */
  protected function assertLlmsTags(array $tags): void {
    $expected = [
      'node_list',
      'config:cas.settings',
      'config:ys_core.site',
      'config:system.site',
      'config:user.role.anonymous',
    ];
    foreach ($expected as $tag) {
      $this->assertContains($tag, $tags);
    }
  }

  /**
   * With Markdown on, llms.txt lists eligible published pages only.
   */
  public function testLlmsListsPublishedPages(): void {
    $this->config('ys_core.site')->set('ai_readability.markdown_enabled', TRUE)->save();
    $this->config('system.site')->set('name', 'Test Site')->set('slogan', 'A slogan')->save();
    $node = Node::create(['type' => 'page', 'title' => 'About [us]', 'status' => 1]);
    $node->save();
    PathAlias::create(['path' => '/node/' . $node->id(), 'alias' => '/about'])->save();
    Node::create(['type' => 'page', 'title' => 'Secret draft', 'status' => 0])->save();
    // A published page behind CAS forced login is not eligible.
    $private = Node::create(['type' => 'page', 'title' => 'Private page', 'status' => 1]);
    $private->save();
    PathAlias::create(['path' => '/node/' . $private->id(), 'alias' => '/private-page'])->save();
    $this->config('cas.settings')
      ->set('forced_login.enabled', TRUE)
      ->set('forced_login.paths', ['pages' => '/private*'])
      ->save();

    $response = $this->get('/llms.txt');
    $this->assertSame(200, $response->getStatusCode());
    $this->assertStringStartsWith('text/markdown', $response->headers->get('Content-Type'));
    $content = $response->getContent();
    $this->assertStringStartsWith("# Test Site\n\n> A slogan\n\n## Pages\n\n", $content);
    $this->assertMatchesRegularExpression('#^- \\[About \\\\\\[us\\\\\\]\\]\\(http://[^)]+/about\\.md\\)$#m', $content);
    $this->assertStringNotContainsString('Secret draft', $content);
    $this->assertStringNotContainsString('Private page', $content);
    $this->assertLlmsTags($response->getCacheableMetadata()->getCacheTags());
  }

}
