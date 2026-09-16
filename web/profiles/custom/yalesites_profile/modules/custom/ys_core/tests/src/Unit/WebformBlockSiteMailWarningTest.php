<?php

namespace Drupal\Tests\ys_core\Unit;

use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tests the site email warning on Pre-Built Form blocks in Layout Builder.
 *
 * Tightening the Site email validation only affects the next save, so a site
 * already holding an address the platform cannot send from keeps it silently.
 * This extends the warning that already fires for the default no-reply address
 * so it also fires for an unauthorized domain, naming the offending address.
 * See yalesites-org/YaleSites-Internal#1669.
 *
 * A Unit test rather than a kernel test for the same reason as
 * LayoutBuilderBlockFormMessagesTest: the branch under test is array
 * manipulation over three stubbed services.
 *
 * @group ys_core
 * @group yalesites
 */
class WebformBlockSiteMailWarningTest extends UnitTestCase {

  /**
   * The path the stubbed URL generator returns for Site Settings.
   */
  const SETTINGS_PATH = '/admin/yalesites/settings';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The function under test is procedural, so the file has to be loaded.
    require_once dirname(__DIR__, 3) . '/ys_core.module';
  }

  /**
   * Site emails that cannot reach an inbox, and the text that must appear.
   */
  public static function warnedProvider(): array {
    return [
      'default no-reply address' => ['noreply@noreply.yale.edu'],
      'unauthorized yale subdomain' => ['yalegsa@elilists.yale.edu'],
      'not a yale address at all' => ['forms@example.com'],
    ];
  }

  /**
   * An unusable site email warns the editor where they are working.
   *
   * @dataProvider warnedProvider
   */
  public function testUnusableSiteMailWarns(string $site_mail): void {
    $warning = $this->warningFor($site_mail);

    $this->assertNotNull($warning, "No warning for $site_mail.");
    $this->assertSame(['messages', 'messages--warning'], $warning['#attributes']['class']);
    $this->assertStringContainsString(self::SETTINGS_PATH, (string) $warning['message']['#markup']);
  }

  /**
   * The unauthorized-domain warning names the address that is wrong.
   *
   * A site owner cannot act on "your site email is wrong" - the warning has to
   * quote the value so they can recognise and correct it.
   */
  public function testUnauthorizedDomainWarningNamesTheAddress(): void {
    $warning = $this->warningFor('yalegsa@elilists.yale.edu');

    $this->assertStringContainsString(
      'yalegsa@elilists.yale.edu',
      (string) $warning['message']['#markup']
    );
  }

  /**
   * An address the platform can actually send from is left alone.
   */
  public function testAuthorizedSiteMailIsNotWarnedAbout(): void {
    $this->assertNull($this->warningFor('forms@yale.edu'));
    $this->assertNull($this->warningFor('Forms@YALE.EDU'));
  }

  /**
   * Only Pre-Built Form blocks carry the warning.
   */
  public function testOtherBlockTypesAreNotWarnedAbout(): void {
    $this->assertNull($this->warningFor('yalegsa@elilists.yale.edu', 'quick_links'));
  }

  /**
   * Runs the alter for a Layout Builder block form and returns any warning.
   *
   * @param string $site_mail
   *   The site's saved `system.site` mail value.
   * @param string $block_type
   *   The inline block type being configured.
   *
   * @return array|null
   *   The warning render array, or NULL if the alter added none.
   */
  private function warningFor(string $site_mail, string $block_type = 'webform'): ?array {
    $this->setContainerWithSiteMail($site_mail);

    $form = [
      'settings' => [
        'label' => ['#default_value' => 'Contact us'],
        'block_form' => [],
      ],
    ];
    $form_state = new FormState();
    $form_state->setBuildInfo(['args' => [NULL, NULL, NULL, "inline_block:$block_type"]]);

    ys_core_form_alter($form, $form_state, 'layout_builder_update_block');

    return $form['settings']['block_form']['email_warning'] ?? NULL;
  }

  /**
   * Puts the three services the warning branch reaches for in the container.
   */
  private function setContainerWithSiteMail(string $site_mail): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(
      static fn (string $key) => $key === 'mail' ? $site_mail : NULL
    );
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);

    $url_generator = $this->createMock(UrlGeneratorInterface::class);
    // Url::toString() passes its own $collect_bubbleable_metadata straight
    // through and returns whatever comes back, so FALSE means a plain string.
    $url_generator->method('generateFromRoute')->willReturn(self::SETTINGS_PATH);

    $container = new ContainerBuilder();
    $container->set('config.factory', $factory);
    $container->set('url_generator', $url_generator);
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);
  }

}
