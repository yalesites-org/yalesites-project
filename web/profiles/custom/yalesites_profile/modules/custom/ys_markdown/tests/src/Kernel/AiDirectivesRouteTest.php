<?php

namespace Drupal\Tests\ys_markdown\Kernel;

use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\Tests\ys_core\Kernel\YsKernelTestBase;
use Drupal\ys_markdown\Controller\AiDirectivesController;
use Drupal\ys_markdown\MarkdownBuilder;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\path_alias\Entity\PathAlias;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;

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
    'robotstxt', 'ys_markdown',
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
    $this->installConfig(['node', 'filter', 'system', 'robotstxt']);
    $this->config('robotstxt.settings')->set('content', "User-agent: *\nDisallow: /admin/\n")->save();
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
   * With the toggle off, robots.txt is the configured content plus the rule.
   */
  public function testRobotsToggleOffIsBaseFile(): void {
    $this->config('ys_core.site')->set('ai_readability.block_ai_crawlers', FALSE)->save();
    $response = $this->get('/robots.txt');
    $this->assertSame(200, $response->getStatusCode());
    $this->assertStringStartsWith('text/plain', $response->headers->get('Content-Type'));
    $this->assertSame("User-agent: *\nDisallow: /admin/\n# Disallow ?page= params\nDisallow: /*?page=", $response->getContent());
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
    $this->assertStringContainsString("\nDisallow: /", $content);
    $this->assertStringContainsString('Disallow: /*?page=', $content);
    $this->assertStringContainsString('Disallow: /admin/', $content);
    foreach (['OAI-SearchBot', 'Claude-SearchBot', 'PerplexityBot'] as $bot) {
      $this->assertStringNotContainsString($bot, $content);
    }
  }

  /**
   * A missing key means on; switching it off removes the block at once.
   */
  public function testRobotsMissingKeyBlocks(): void {
    $config = $this->config('ys_core.site');
    $config->clear('ai_readability.block_ai_crawlers')->save();
    $this->assertStringContainsString('User-agent: GPTBot', $this->get('/robots.txt')->getContent());
    $config->set('ai_readability.block_ai_crawlers', FALSE)->save();
    $this->assertStringNotContainsString('GPTBot', $this->get('/robots.txt')->getContent());
  }

  /**
   * Saving the toggle invalidates the robotstxt cache tag.
   */
  public function testToggleChangeInvalidatesRobotstxtTag(): void {
    $before = $this->container->get('cache_tags.invalidator.checksum')->getCurrentChecksum(['robotstxt']);
    $this->config('ys_core.site')->set('ai_readability.block_ai_crawlers', FALSE)->save();
    $after = $this->container->get('cache_tags.invalidator.checksum')->getCurrentChecksum(['robotstxt']);
    $this->assertNotSame($before, $after);
    // An unrelated save leaves the tag alone.
    $this->config('ys_core.site')->set('custom_favicon', 'x')->save();
    $this->assertSame($after, $this->container->get('cache_tags.invalidator.checksum')->getCurrentChecksum(['robotstxt']));
  }

  /**
   * The deploy hook replaces the module's bundled default with core's file.
   */
  public function testDeployHookSeedsFromCore(): void {
    require_once __DIR__ . '/../../../ys_markdown.deploy.php';
    $bundled = $this->container->get('extension.list.module')->getPath('robotstxt') . '/robots.txt';
    $this->config('robotstxt.settings')->set('content', file_get_contents(DRUPAL_ROOT . '/' . $bundled))->save();
    ys_markdown_deploy_10001();
    $this->assertSame(
      file_get_contents(DRUPAL_ROOT . '/core/assets/scaffold/files/robots.txt'),
      $this->config('robotstxt.settings')->get('content')
    );
  }

  /**
   * The deploy hook leaves a site's own robots.txt edits alone.
   */
  public function testDeployHookKeepsCustomContent(): void {
    require_once __DIR__ . '/../../../ys_markdown.deploy.php';
    ys_markdown_deploy_10001();
    $this->assertSame("User-agent: *\nDisallow: /admin/\n", $this->config('robotstxt.settings')->get('content'));
  }

  /**
   * With Markdown off, llms.txt is a cacheable 404.
   */
  public function testLlmsOffIs404(): void {
    $this->config('ys_core.site')->set('ai_readability.markdown_enabled', FALSE)->save();
    $response = $this->get('/llms.txt');
    $this->assertSame(404, $response->getStatusCode());
    $this->assertInstanceOf(CacheableResponseInterface::class, $response);
    $this->assertLlmsTags($response->getCacheableMetadata()->getCacheTags());
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
    $this->assertStringStartsWith("# Test Site\n\n> A slogan\n\n## Page\n\n", $content);
    $this->assertMatchesRegularExpression('#^- \\[About \\\\\\[us\\\\\\]\\]\\(http://[^)]+/about\\.md\\)$#m', $content);
    $this->assertStringNotContainsString('Secret draft', $content);
    $this->assertStringNotContainsString('Private page', $content);
    $this->assertLlmsTags($response->getCacheableMetadata()->getCacheTags());
    // Only the metadata is observable: core forces kernel requests private.
    $this->assertSame(MarkdownBuilder::MAX_AGE, $response->getCacheableMetadata()->getCacheMaxAge());
  }

  /**
   * Each node type gets its own section, ordered by label.
   */
  public function testLlmsGroupsByContentType(): void {
    $this->config('ys_core.site')->set('ai_readability.markdown_enabled', TRUE)->save();
    // Label order differs from machine-name order on purpose.
    NodeType::create(['type' => 'event', 'name' => 'Zebra events'])->save();
    NodeType::create(['type' => 'post', 'name' => 'Post'])->save();
    NodeType::create(['type' => 'resource', 'name' => 'Resource'])->save();
    foreach ([['post', 'A post'], ['page', 'A page'], ['event', 'An event']] as [$type, $title]) {
      $node = Node::create(['type' => $type, 'title' => $title, 'status' => 1]);
      $node->save();
      PathAlias::create(['path' => '/node/' . $node->id(), 'alias' => '/' . $type])->save();
    }

    $content = $this->get('/llms.txt')->getContent();
    // Reduce each link to its title so the host does not matter.
    $outline = preg_replace('#\(http[^)]*\)#', '', $content);
    $this->assertStringEndsWith("\n## Page\n\n- [A page]\n\n## Post\n\n- [A post]\n\n## Zebra events\n\n- [An event]\n\n", $outline);
    $this->assertStringNotContainsString('## Resource', $content);
  }

}
