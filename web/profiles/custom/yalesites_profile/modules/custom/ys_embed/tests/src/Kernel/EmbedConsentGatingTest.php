<?php

namespace Drupal\Tests\ys_embed\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;

/**
 * Tests that consent gating reaches the rendered markup, and only when asked.
 *
 * The unit tests cover what each plugin declares. This covers the thing that
 * actually protects a visitor: whether the third-party URL leaves the server as
 * a live 'src' the browser will fetch immediately, or as Klaro's deferred
 * 'data-src'.
 *
 * @group yalesites
 * @group ys_embed
 */
class EmbedConsentGatingTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    // ys_embed_consent_gating_applies() asks KlaroHelper the same questions
    // Klaro asks itself, and one of them is a permission check - so the user
    // module has to be here for roles to exist.
    'user',
    // Gating is skipped unless Klaro is there to undefer the markup, so it has
    // to be installed for the "on" cases to be meaningful.
    'klaro',
    'ys_embed',
  ];

  /**
   * The setting under test lives in ys_core, which is not installed here.
   *
   * Installing ys_core would drag its whole dependency tree in to read one
   * boolean. Its schema is therefore unavailable and strict checking has to be
   * off; the schema itself is exercised by ys_core's own kernel tests.
   *
   * @var bool
   */
  // phpcs:ignore DrupalPractice.Objects.StrictSchemaDisabled.StrictConfigSchema
  protected $strictConfigSchema = FALSE;

  /**
   * The embed source plugin manager.
   *
   * @var \Drupal\ys_embed\Plugin\EmbedSourceManager
   */
  protected $embedManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['user', 'klaro']);
    // Klaro attaches nothing to a visitor without this, so without it every
    // "gating on" case here would silently exercise the "off" path instead.
    Role::load(RoleInterface::ANONYMOUS_ID)->grantPermission('use klaro')->save();
    $this->embedManager = $this->container->get('plugin.manager.embed_source');
  }

  /**
   * Renders an embed source through the real theme layer.
   *
   * @param string $plugin_id
   *   The embed source plugin ID.
   * @param array $params
   *   The template params the plugin would have captured from an embed code.
   *
   * @return string
   *   The rendered markup.
   */
  protected function renderEmbed(string $plugin_id, array $params): string {
    $build = $this->embedManager->loadPluginById($plugin_id)->build($params + ['title' => 'Test embed']);
    return (string) $this->container->get('renderer')->renderRoot($build);
  }

  /**
   * Turns site-level consent gating on or off.
   *
   * @param bool $enabled
   *   Whether the consent banner and gating are switched on.
   */
  protected function setConsentGating(bool $enabled): void {
    $this->config('ys_core.consent_settings')->set('banner_enabled', $enabled)->save();
  }

  /**
   * The rendered embed is invalidated when the consent setting changes.
   *
   * The markup depends on the setting, so without this dependency an embed
   * rendered before a site enabled consent would keep being served from the
   * render cache with a live src. The deliberate absence of a cache clear in
   * setConsentGating() is what lets the two tests either side of this one catch
   * that regression.
   */
  public function testRenderedEmbedDependsOnTheConsentSetting(): void {
    $build = $this->embedManager->loadPluginById('google_maps')
      ->build(['title' => 'Test embed', 'map_params' => '?pb=test']);
    $this->container->get('renderer')->renderRoot($build);

    $this->assertContains('config:ys_core.consent_settings', $build['#cache']['tags']);
  }

  /**
   * With gating off, a gated iframe still renders a live src.
   *
   * This is the pre-Klaro behaviour, and it has to survive so that installing
   * the module does not silently break embeds on sites that have not opted in.
   */
  public function testIframeKeepsLiveSrcWhenGatingIsOff(): void {
    $this->setConsentGating(FALSE);
    $markup = $this->renderEmbed('google_maps', ['map_params' => '?pb=test']);

    $this->assertStringContainsString(' src="https://www.google.com/maps/embed?pb=test"', $markup);
    $this->assertStringNotContainsString('data-src', $markup);
    $this->assertStringNotContainsString('data-name', $markup);
  }

  /**
   * With gating on, a gated iframe ships data-src instead of src.
   */
  public function testIframeDefersSrcWhenGatingIsOn(): void {
    $this->setConsentGating(TRUE);
    $markup = $this->renderEmbed('google_maps', ['map_params' => '?pb=test']);

    // 'data-src="..."' contains 'src="..."', so match the attribute
    // boundary rather than the bare substring.
    $this->assertStringNotContainsString(' src="https://www.google.com/maps/embed', $markup);
    $this->assertStringContainsString('data-src="https://www.google.com/maps/embed?pb=test"', $markup);
    $this->assertStringContainsString('data-name="google_maps"', $markup);
  }

  /**
   * A Yale-tenant source keeps its live src even with gating on.
   */
  public function testUngatedSourceIsNeverDeferred(): void {
    $this->setConsentGating(TRUE);
    $markup = $this->renderEmbed('twenty_five_live_form', ['params' => 'x=1']);

    $this->assertStringContainsString(' src="https://25live.collegenet.com/pro/yale/embedded/preview?x=1"', $markup);
    $this->assertStringNotContainsString('data-src', $markup);
  }

  /**
   * With gating off, a script source still renders a live script src.
   */
  public function testScriptKeepsLiveSrcWhenGatingIsOff(): void {
    $this->setConsentGating(FALSE);
    $markup = $this->renderEmbed('twitter', ['blockquote' => '<blockquote class="twitter-tweet"></blockquote>']);

    $this->assertStringContainsString(' src="https://platform.twitter.com/widgets.js"', $markup);
    $this->assertStringNotContainsString('text/plain', $markup);
  }

  /**
   * With gating on, a script source ships Klaro's deferred script tag.
   */
  public function testScriptDefersWhenGatingIsOn(): void {
    $this->setConsentGating(TRUE);
    $markup = $this->renderEmbed('twitter', ['blockquote' => '<blockquote class="twitter-tweet"></blockquote>']);

    $this->assertStringNotContainsString(' src="https://platform.twitter.com/widgets.js"', $markup);
    $this->assertStringContainsString('type="text/plain"', $markup);
    $this->assertStringContainsString('data-type="application/javascript"', $markup);
    $this->assertStringContainsString('data-src="https://platform.twitter.com/widgets.js"', $markup);
    $this->assertStringContainsString('data-name="x"', $markup);
  }

}
