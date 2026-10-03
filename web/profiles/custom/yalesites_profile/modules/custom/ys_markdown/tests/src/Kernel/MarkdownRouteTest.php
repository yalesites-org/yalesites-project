<?php

namespace Drupal\Tests\ys_markdown\Kernel;

use Drupal\Core\Http\Exception\CacheableNotFoundHttpException;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\Tests\ys_core\Kernel\YsKernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Route;

/**
 * Tests serving a node's Markdown through the real routing stack.
 *
 * @group ys_markdown
 */
class MarkdownRouteTest extends YsKernelTestBase {

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
   * A published page answers with Markdown starting at its title.
   */
  public function testPublishedPageServesMarkdown(): void {
    $node = Node::create([
      'type' => 'page',
      'title' => 'Hello page',
      'status' => 1,
      'body' => ['value' => '<p>Body text</p>', 'format' => 'plain_text'],
    ]);
    $node->save();

    $response = $this->get('/node/' . $node->id() . '/md');
    $this->assertSame(200, $response->getStatusCode());
    $this->assertStringStartsWith('text/markdown', $response->headers->get('Content-Type'));
    $content = $response->getContent();
    $this->assertStringStartsWith('# Hello page', $content);
    $this->assertStringContainsString('Source: ', $content);
    $this->assertStringContainsString('Body text', $content);
    // The display's own linked title heading is not repeated under ours.
    $this->assertStringNotContainsString('[Hello page]', $content);
  }

  /**
   * The .md suffix on the internal path reaches the same response.
   */
  public function testSuffixPathWorks(): void {
    $node = Node::create(['type' => 'page', 'title' => 'Suffix page', 'status' => 1]);
    $node->save();
    $response = $this->get('/node/' . $node->id() . '.md');
    $this->assertSame(200, $response->getStatusCode());
    $this->assertStringContainsString('# Suffix page', $response->getContent());
  }

  /**
   * Turning the setting off 404s, and back on serves again without a flush.
   */
  public function testSettingToggleInvalidatesCachedAnswer(): void {
    $node = Node::create(['type' => 'page', 'title' => 'Toggle page', 'status' => 1]);
    $node->save();
    $path = '/node/' . $node->id() . '/md';

    $config = $this->config('ys_core.site');
    $config->set('ai_readability.markdown_enabled', FALSE)->save();
    $notFound = $this->get404($path);
    $this->assertContains('config:ys_core.site', $notFound->getCacheTags());

    $config->set('ai_readability.markdown_enabled', TRUE)->save();
    $this->assertSame(200, $this->get($path)->getStatusCode());
  }

  /**
   * An unpublished page is not served.
   */
  public function testUnpublishedIs404(): void {
    $node = Node::create(['type' => 'page', 'title' => 'Draft page', 'status' => 0]);
    $node->save();
    $this->get404('/node/' . $node->id() . '/md');
  }

  /**
   * A newer non-default revision never leaks; the default revision is served.
   */
  public function testNonDefaultRevisionNotServed(): void {
    $node = Node::create(['type' => 'page', 'title' => 'Published title', 'status' => 1]);
    $node->save();
    $draft = Node::load($node->id());
    $draft->setTitle('Forward draft title');
    $draft->setNewRevision(TRUE);
    $draft->isDefaultRevision(FALSE);
    $draft->save();

    $response = $this->get('/node/' . $node->id() . '/md');
    $this->assertSame(200, $response->getStatusCode());
    $this->assertStringContainsString('Published title', $response->getContent());
    $this->assertStringNotContainsString('Forward draft title', $response->getContent());
  }

  /**
   * The page-attachments hook merges into cacheability set by earlier hooks.
   */
  public function testPageAttachmentsKeepEarlierCacheability(): void {
    $node = Node::create(['type' => 'page', 'title' => 'Attach page', 'status' => 1]);
    $node->save();
    $request = Request::create('/node/' . $node->id());
    $request->attributes->add([
      RouteObjectInterface::ROUTE_NAME => 'entity.node.canonical',
      RouteObjectInterface::ROUTE_OBJECT => new Route('/node/{node}'),
      'node' => $node,
    ]);
    $this->container->get('request_stack')->push($request);

    $attachments = ['#cache' => ['tags' => ['earlier_tag'], 'contexts' => ['earlier_context'], 'max-age' => 0]];
    ys_markdown_page_attachments($attachments);

    $this->assertSame('text/markdown', $attachments['#attached']['html_head_link'][0][0]['type']);
    $this->assertContains('earlier_tag', $attachments['#cache']['tags']);
    $this->assertContains('node:' . $node->id(), $attachments['#cache']['tags']);
    $this->assertContains('earlier_context', $attachments['#cache']['contexts']);
    $this->assertContains('url.path', $attachments['#cache']['contexts']);
    $this->assertSame(0, $attachments['#cache']['max-age']);
  }

}
