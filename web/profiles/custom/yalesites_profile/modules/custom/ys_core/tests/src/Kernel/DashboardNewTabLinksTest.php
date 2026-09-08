<?php

namespace Drupal\Tests\ys_core\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Tests the new-tab behaviour of the editorial dashboard's outbound links.
 *
 * The Resources links are reference material editors check alongside active
 * editing work, so following one must not drop them out of their Drupal
 * session.
 *
 * The dashboard lives under /admin, which core's AdminRouteSubscriber marks as
 * an admin route, so it renders in ys_admin_theme rather than atomic. Atomic's
 * link-purpose library (component-library-twig's link-treatment.js, shipped in
 * atomic/global) is what normally adds the icon and the "Link opens in new
 * window" message to any [target="_blank"] link, and it is never attached to
 * an admin-themed page. Every new-tab link on the dashboard therefore has to
 * carry its own screen reader announcement.
 *
 * @group ys_core
 * @group yalesites
 */
class DashboardNewTabLinksTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'views', 'twig_tweak', 'ys_core'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The Resources section links, which all point off-platform.
   */
  protected const RESOURCE_LINKS = [
    'https://yalesites.yale.edu/explore-resources/learn-the-basics',
    'https://yalesites.yale.edu/explore-resources/user-guide',
    'https://yalesites.yale.edu/trainings#officehours',
    'https://yalesites.yale.edu/resource-library',
  ];

  /**
   * Renders the dashboard and returns an XPath query over its markup.
   */
  protected function renderDashboard(): \DOMXPath {
    $build = [
      '#theme' => 'ys_dashboard',
      '#platform_version' => '1.0.0',
      '#announcements' => [],
    ];
    $html = (string) \Drupal::service('renderer')->renderInIsolation($build);

    $dom = new \DOMDocument();
    // The dashboard is a fragment, and Views may emit markup DOMDocument warns
    // about; neither is what this test is asserting on.
    @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);

    return new \DOMXPath($dom);
  }

  /**
   * Every Resources link opens in a new tab, safely.
   */
  public function testResourceLinksOpenInNewTab(): void {
    $xpath = $this->renderDashboard();

    foreach (self::RESOURCE_LINKS as $href) {
      $links = $xpath->query(sprintf('//a[@href="%s"]', $href));
      $this->assertCount(1, $links, sprintf('Found exactly one %s link.', $href));

      $link = $links->item(0);
      $this->assertSame('_blank', $link->getAttribute('target'), sprintf('%s opens in a new tab.', $href));
      // Without rel, the opened tab can reach back through window.opener.
      $this->assertSame('noopener noreferrer', $link->getAttribute('rel'), sprintf('%s opens the new tab safely.', $href));
    }
  }

  /**
   * Any dashboard link that opens a new tab announces that to screen readers.
   *
   * Asserted across every [target="_blank"] link rather than only the Resources
   * ones, because nothing on an admin-themed page supplies this automatically.
   */
  public function testEveryNewTabLinkIsAnnounced(): void {
    $xpath = $this->renderDashboard();

    $links = $xpath->query('//a[@target="_blank"]');
    $this->assertGreaterThanOrEqual(
      count(self::RESOURCE_LINKS) + 1,
      $links->count(),
      'The Resources links and the Siteimprove link all open in a new tab.'
    );

    foreach ($links as $link) {
      $announcement = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " visually-hidden ")]', $link);
      $this->assertGreaterThan(
        0,
        $announcement->count(),
        sprintf('The new-tab link to %s carries a visually hidden announcement.', $link->getAttribute('href'))
      );
      $this->assertStringContainsStringIgnoringCase(
        'opens in new window',
        $announcement->item(0)->textContent,
        sprintf('The new-tab link to %s says it opens in a new window.', $link->getAttribute('href'))
      );
    }
  }

}
