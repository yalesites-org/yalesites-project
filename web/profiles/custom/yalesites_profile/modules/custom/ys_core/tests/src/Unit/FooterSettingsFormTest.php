<?php

namespace Drupal\Tests\ys_core\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\Tests\ys_core\Traits\ProtectedPropertyTrait;
use Drupal\path_alias\AliasManager;
use Drupal\ys_core\Form\FooterSettingsForm;
use Drupal\ys_core\SocialLinksManager;

/**
 * Tests the footer settings form's link validation and node-link translation.
 *
 * Both behaviours used to live in a SettingsFormTrait that was named and
 * docblocked as shared across the ys_core settings forms but had exactly one
 * user, this form (yalesites-org/YaleSites-Internal#1725). They are now inlined
 * here. This form had no test at all, so these pin the behaviour through the
 * public entry points that reach it — that the inline changed nothing is the
 * point, so the same assertions hold before and after the move.
 *
 * @group ys_core
 * @coversDefaultClass \Drupal\ys_core\Form\FooterSettingsForm
 */
class FooterSettingsFormTest extends UnitTestCase {

  use ProtectedPropertyTrait;

  /**
   * The error the form sets on a half-filled link.
   */
  private const INCOMPLETE_LINK_ERROR = 'Any link specified must have both a URL and a link title.';

  /**
   * A link with a URL but no title is rejected.
   *
   * @covers ::validateForm
   */
  public function testValidateFlagsLinkMissingItsTitle(): void {
    $form_state = $this->validate([
      'links_col_1' => [['link_url' => '/about', 'link_title' => '']],
    ]);

    $this->assertSame(
      self::INCOMPLETE_LINK_ERROR,
      (string) $form_state->getErrors()['links_col_1']
    );
  }

  /**
   * A link with a title but no URL is rejected.
   *
   * @covers ::validateForm
   */
  public function testValidateFlagsLinkMissingItsUrl(): void {
    $form_state = $this->validate([
      'links_col_2' => [['link_url' => '', 'link_title' => 'About']],
    ]);

    $this->assertSame(
      self::INCOMPLETE_LINK_ERROR,
      (string) $form_state->getErrors()['links_col_2']
    );
  }

  /**
   * Complete links, and empty columns, raise no error.
   *
   * @covers ::validateForm
   */
  public function testValidateAcceptsCompleteLinks(): void {
    $form_state = $this->validate([
      'links_col_1' => [['link_url' => '/about', 'link_title' => 'About']],
    ]);

    $this->assertSame([], $form_state->getErrors());
  }

  /**
   * Internal /node/ links are stored as their path alias; others are untouched.
   *
   * Covers all three places the form translates a link: the footer link
   * columns, the logo URLs, and the school logo URL.
   *
   * @covers ::submitForm
   */
  public function testSubmitTranslatesNodeLinksToTheirAlias(): void {
    $written = $this->submit([
      'links_col_1' => [['link_url' => '/node/12', 'link_title' => 'About']],
      'links_col_2' => [['link_url' => 'https://yale.edu', 'link_title' => 'Yale']],
      'logos' => [['logo_url' => '/node/12', 'logo' => '7']],
      'school_logo_url' => '/node/12',
    ]);

    $this->assertSame('/about-us', $written['links.links_col_1'][0]['link_url']);
    $this->assertSame('https://yale.edu', $written['links.links_col_2'][0]['link_url']);
    $this->assertSame('/about-us', $written['content.logos'][0]['logo_url']);
    $this->assertSame('/about-us', $written['content.school_logo_url']);
  }

  /**
   * A logo with no URL stores NULL rather than translating an empty string.
   *
   * @covers ::submitForm
   */
  public function testSubmitLeavesLogoWithoutUrlAsNull(): void {
    $written = $this->submit([
      'logos' => [['logo_url' => '', 'logo' => '7']],
    ]);

    $this->assertNull($written['content.logos'][0]['logo_url']);
  }

  /**
   * A link column entry with no URL is dropped rather than stored empty.
   *
   * @covers ::submitForm
   */
  public function testSubmitDropsLinksWithoutUrl(): void {
    $written = $this->submit([
      'links_col_1' => [['link_url' => '', 'link_title' => 'About']],
    ]);

    $this->assertSame([], $written['links.links_col_1']);
  }

  /**
   * Validates the given form values and returns the resulting form state.
   *
   * @param array $values
   *   Submitted form values keyed by element name.
   *
   * @return \Drupal\Core\Form\FormState
   *   The form state, carrying any validation errors.
   */
  private function validate(array $values): FormState {
    $form_state = new FormState();
    $form_state->setValues($values);

    $form = [];
    $this->formObject($this->configFactory())->validateForm($form, $form_state);

    return $form_state;
  }

  /**
   * Submits the form with the given values and returns the saved config map.
   *
   * @param array $values
   *   Submitted form values keyed by element name. Defaults are filled in for
   *   the keys submitForm() always reads.
   *
   * @return array
   *   The keys written to ys_core.footer_settings mapped to their values.
   */
  private function submit(array $values): array {
    $written = [];
    $factory = $this->configFactory($written);

    $form_state = new FormState();
    $form_state->setValues($values + [
      'links_col_1' => [],
      'links_col_2' => [],
      'logos' => [],
      'school_logo_url' => NULL,
    ]);

    $form_object = $this->formObject($factory);
    $this->setProtectedProperty($form_object, 'cacheRender', $this->createMock(CacheBackendInterface::class));
    $this->setProtectedProperty($form_object, 'messenger', $this->createMock(MessengerInterface::class));

    $form = [];
    $form_object->submitForm($form, $form_state);

    return $written;
  }

  /**
   * A config factory whose writes are recorded into the given array.
   *
   * @param array $written
   *   Collects every key set on a config object, by reference.
   */
  private function configFactory(array &$written = []): ConfigFactoryInterface {
    $config = $this->createMock(Config::class);
    $config->method('get')->willReturn(NULL);
    $config->method('set')->willReturnCallback(function (string $key, $value) use (&$written, $config) {
      $written[$key] = $value;
      return $config;
    });
    $config->method('save')->willReturnSelf();

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);
    $factory->method('getEditable')->willReturn($config);

    return $factory;
  }

  /**
   * A form object wired with just the collaborators these paths touch.
   */
  private function formObject(ConfigFactoryInterface $factory): FooterSettingsForm {
    // Anything other than the one known node path returns a marker rather than
    // its own input, so a lookup that should have been skipped is visible in
    // the assertion instead of round-tripping unnoticed.
    $alias_manager = $this->createMock(AliasManager::class);
    $alias_manager->method('getAliasByPath')->willReturnCallback(
      fn (string $path) => $path === '/node/12' ? '/about-us' : '/UNEXPECTED-LOOKUP' . $path
    );

    $form_object = (new \ReflectionClass(FooterSettingsForm::class))->newInstanceWithoutConstructor();
    $this->setProtectedProperty($form_object, 'configFactory', $factory);
    $this->setProtectedProperty($form_object, 'socialLinks', $this->createMock(SocialLinksManager::class));
    $this->setProtectedProperty($form_object, 'pathAliasManager', $alias_manager);
    $this->setProtectedProperty($form_object, 'stringTranslation', $this->getStringTranslationStub());

    return $form_object;
  }

}
