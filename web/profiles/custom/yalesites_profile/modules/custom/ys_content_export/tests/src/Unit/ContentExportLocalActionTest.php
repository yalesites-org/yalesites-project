<?php

namespace Drupal\Tests\ys_content_export\Unit;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_content_export\Plugin\Menu\LocalAction\ContentExportLocalAction;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Unit tests for the content-export local action.
 *
 * @coversDefaultClass \Drupal\ys_content_export\Plugin\Menu\LocalAction\ContentExportLocalAction
 * @group ys_content_export
 * @group yalesites
 */
class ContentExportLocalActionTest extends UnitTestCase {

  /**
   * Builds the local action plugin around a request carrying $query.
   */
  protected function action(array $query): ContentExportLocalAction {
    $stack = new RequestStack();
    $stack->push(Request::create('/admin/content/manage-pages', 'GET', $query));
    return new ContentExportLocalAction(
      [],
      'ys_content_export.export_pages',
      ['options' => []],
      $this->createMock(RouteProviderInterface::class),
      $stack
    );
  }

  /**
   * The export link forwards the on-screen filters, minus the pager page param.
   *
   * @covers ::getOptions
   */
  public function testForwardsCurrentQueryMinusPage(): void {
    $options = $this->action(['status' => '1', 'tags' => '7', 'page' => '2'])
      ->getOptions($this->createMock(RouteMatchInterface::class));
    $this->assertSame(['status' => '1', 'tags' => '7'], $options['query']);
  }

  /**
   * With no filters applied the link adds no query option.
   *
   * @covers ::getOptions
   */
  public function testNoQueryLeavesOptionsClean(): void {
    $options = $this->action([])
      ->getOptions($this->createMock(RouteMatchInterface::class));
    $this->assertArrayNotHasKey('query', $options);
  }

}
