<?php

namespace Drupal\Tests\ys_ai\Unit\Form;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_ai\Form\YsAiSettings;

/**
 * Unit tests for the YsAiSettings config form.
 *
 * YsAiSettings extends the contrib AiEngineChatSettings form, which in turn
 * extends core's ConfigFormBase. Both base classes are constructible with
 * plain injected dependencies (no service container needed), so this is
 * covered as a Unit test rather than a Kernel test: a real Kernel test would
 * need to enable the full ai_engine + ys_core dependency chain (ai_engine,
 * ai_engine_chat, ai_engine_embedding, ai_engine_feed, ai_engine_metadata,
 * metatag, key, plus ys_core's own contrib dependencies), which is
 * disproportionate to what this thin subclass actually adds. The
 * systemInstructionsAccess collaborator (normally wired in ::create()) is
 * injected here via reflection since the class exposes no setter.
 *
 * @coversDefaultClass \Drupal\ys_ai\Form\YsAiSettings
 *
 * @group ys_ai
 * @group yalesites
 */
class YsAiSettingsTest extends UnitTestCase {

  /**
   * The config factory mock.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $configFactory;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);

    $current_user = $this->createMock(AccountInterface::class);
    $url_generator = $this->createMock(UrlGeneratorInterface::class);
    $url_generator->method('generateFromRoute')
      ->willReturn('/admin/config/yalesites/ys_ai/system-instructions');

    $container = new ContainerBuilder();
    $container->set('current_user', $current_user);
    $container->set('url_generator', $url_generator);
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);
  }

  /**
   * Creates an in-memory config double supporting get()/set()/save() chains.
   *
   * A hand-rolled double is used, rather than a PHPUnit mock, because
   * submitForm() chains several ->set() calls before ->save(): expressing
   * that with mock expectations would assert the call sequence rather than
   * the resulting values, which is what actually matters here.
   */
  protected function createFakeConfig(array $initial = []): object {
    return new class($initial) {
      /**
       * The stored config values.
       *
       * @var array
       */
      public array $values;

      /**
       * Constructs the fake config double.
       */
      public function __construct(array $initial) {
        $this->values = $initial;
      }

      /**
       * Mimics Config::get().
       */
      public function get($key) {
        return $this->values[$key] ?? NULL;
      }

      /**
       * Mimics Config::set().
       */
      public function set($key, $value) {
        $this->values[$key] = $value;
        return $this;
      }

      /**
       * Mimics Config::save().
       */
      public function save() {
        return $this;
      }

    };
  }

  /**
   * Creates a system instructions access stub returning a fixed result.
   */
  protected function createAccessStub(bool $allowed): object {
    return new class($allowed) {

      /**
       * Constructs the access stub.
       */
      public function __construct(protected bool $allowed) {
      }

      /**
       * Mimics SystemInstructionsAccessCheck::access().
       */
      public function access($account) {
        return $this->allowed ? AccessResult::allowed() : AccessResult::neutral();
      }

    };
  }

  /**
   * Points the config factory's get()/getEditable() at the given configs.
   */
  protected function configureConfigFactory(object $chat_config, object $embedding_config): void {
    $map = [
      ['ai_engine_chat.settings', $chat_config],
      ['ai_engine_embedding.settings', $embedding_config],
    ];
    $this->configFactory->method('get')->willReturnMap($map);
    $this->configFactory->method('getEditable')->willReturnMap($map);
  }

  /**
   * Builds the form under test.
   *
   * Injects systemInstructionsAccess by reflection since YsAiSettings only
   * sets it from within ::create().
   */
  protected function createForm(object $system_instructions_access): YsAiSettings {
    $typed_config_manager = $this->createMock(TypedConfigManagerInterface::class);
    $form = new YsAiSettings($this->configFactory, $typed_config_manager);
    $form->setStringTranslation($this->getStringTranslationStub());
    $form->setMessenger($this->createMock(MessengerInterface::class));

    $reflection = new \ReflectionClass($form);
    $property = $reflection->getProperty('systemInstructionsAccess');
    $property->setAccessible(TRUE);
    $property->setValue($form, $system_instructions_access);

    return $form;
  }

  /**
   * Default chat config values.
   *
   * Includes baseline array shapes (e.g. 4 prompt slots) so the parent
   * AiEngineChatSettings::buildForm() prompt loop doesn't hit
   * undefined-offset warnings on values this test isn't exercising.
   */
  protected function defaultChatValues(array $overrides = []): array {
    return $overrides + [
      'azure_base_url' => NULL,
      'enable' => NULL,
      'floating_button' => NULL,
      'floating_button_text' => NULL,
      'floating_button_icon' => NULL,
      'prompts' => ['', '', '', ''],
      'disclaimer' => NULL,
      'footer' => NULL,
    ];
  }

  /**
   * Chat widget fields appear when Azure is configured.
   *
   * Values are populated from the saved config.
   *
   * @covers ::buildForm
   */
  public function testBuildFormShowsChatFieldsWhenAzureConfigured(): void {
    $chat_config = $this->createFakeConfig($this->defaultChatValues([
      'azure_base_url' => 'https://example.azure.com',
      'enable' => TRUE,
      'floating_button' => TRUE,
      'floating_button_text' => 'Ask Beacon',
      'floating_button_icon' => 'fa-sparkles',
    ]));
    $embedding_config = $this->createFakeConfig();
    $this->configureConfigFactory($chat_config, $embedding_config);

    $form = $this->createForm($this->createAccessStub(FALSE))
      ->buildForm([], new FormState());

    $this->assertTrue($form['enable']['#default_value']);
    $this->assertTrue($form['floating_button']['#default_value']);
    $this->assertSame('Ask Beacon', $form['floating_button_text']['#default_value']);
    $this->assertSame('fa-sparkles', $form['floating_button_icon']['#default_value']);
  }

  /**
   * The floating button text falls back to "Beacon Chat" when unset.
   *
   * @covers ::buildForm
   */
  public function testBuildFormDefaultsFloatingButtonText(): void {
    $chat_config = $this->createFakeConfig($this->defaultChatValues([
      'azure_base_url' => 'https://example.azure.com',
    ]));
    $embedding_config = $this->createFakeConfig();
    $this->configureConfigFactory($chat_config, $embedding_config);

    $form = $this->createForm($this->createAccessStub(FALSE))
      ->buildForm([], new FormState());

    $this->assertSame('Beacon Chat', (string) $form['floating_button_text']['#default_value']);
    $this->assertSame('fa-comments', $form['floating_button_icon']['#default_value']);
  }

  /**
   * Chat widget fields are entirely omitted when Azure isn't configured.
   *
   * @covers ::buildForm
   */
  public function testBuildFormOmitsChatFieldsWhenAzureNotConfigured(): void {
    $chat_config = $this->createFakeConfig($this->defaultChatValues());
    $embedding_config = $this->createFakeConfig();
    $this->configureConfigFactory($chat_config, $embedding_config);

    $form = $this->createForm($this->createAccessStub(FALSE))
      ->buildForm([], new FormState());

    $this->assertArrayNotHasKey('enable', $form);
    $this->assertArrayNotHasKey('floating_button', $form);
    $this->assertArrayNotHasKey('floating_button_text', $form);
    $this->assertArrayNotHasKey('floating_button_icon', $form);
  }

  /**
   * The embedding toggle appears once all Azure search settings are set.
   *
   * All three of azure_embedding_service_url, azure_search_service_name,
   * and azure_search_service_index must be present.
   *
   * @covers ::buildForm
   */
  public function testBuildFormShowsEmbeddingFieldWhenFullyConfigured(): void {
    $chat_config = $this->createFakeConfig($this->defaultChatValues());
    $embedding_config = $this->createFakeConfig([
      'azure_embedding_service_url' => 'https://example.azure.com',
      'azure_search_service_name' => 'search-service',
      'azure_search_service_index' => 'index-1',
      'enable' => TRUE,
    ]);
    $this->configureConfigFactory($chat_config, $embedding_config);

    $form = $this->createForm($this->createAccessStub(FALSE))
      ->buildForm([], new FormState());

    $this->assertTrue($form['enable_embedding']['#default_value']);
  }

  /**
   * The embedding toggle is omitted when a required setting is missing.
   *
   * Any one of the three required Azure search settings being absent
   * suppresses the toggle.
   *
   * @covers ::buildForm
   */
  public function testBuildFormOmitsEmbeddingFieldWhenPartiallyConfigured(): void {
    $chat_config = $this->createFakeConfig($this->defaultChatValues());
    $embedding_config = $this->createFakeConfig([
      'azure_embedding_service_url' => 'https://example.azure.com',
      'azure_search_service_name' => 'search-service',
      // azure_search_service_index intentionally absent.
    ]);
    $this->configureConfigFactory($chat_config, $embedding_config);

    $form = $this->createForm($this->createAccessStub(FALSE))
      ->buildForm([], new FormState());

    $this->assertArrayNotHasKey('enable_embedding', $form);
  }

  /**
   * The system instructions management link appears when access is allowed.
   *
   * @covers ::buildForm
   */
  public function testBuildFormShowsSystemInstructionsLinkWhenAccessAllowed(): void {
    $chat_config = $this->createFakeConfig($this->defaultChatValues());
    $embedding_config = $this->createFakeConfig();
    $this->configureConfigFactory($chat_config, $embedding_config);

    $form = $this->createForm($this->createAccessStub(TRUE))
      ->buildForm([], new FormState());

    $this->assertArrayHasKey('system_instructions_link', $form);
    $this->assertStringContainsString(
      '/admin/config/yalesites/ys_ai/system-instructions',
      (string) $form['system_instructions_link']['#markup']
    );
  }

  /**
   * The system instructions management link is omitted when access denied.
   *
   * @covers ::buildForm
   */
  public function testBuildFormOmitsSystemInstructionsLinkWhenAccessDenied(): void {
    $chat_config = $this->createFakeConfig($this->defaultChatValues());
    $embedding_config = $this->createFakeConfig();
    $this->configureConfigFactory($chat_config, $embedding_config);

    $form = $this->createForm($this->createAccessStub(FALSE))
      ->buildForm([], new FormState());

    $this->assertArrayNotHasKey('system_instructions_link', $form);
  }

  /**
   * SubmitForm() saves values to their respective config objects.
   *
   * Covers the chat, floating button, and embedding values.
   *
   * @covers ::submitForm
   */
  public function testSubmitFormSavesChatAndEmbeddingConfig(): void {
    $chat_config = $this->createFakeConfig();
    $embedding_config = $this->createFakeConfig();
    $this->configureConfigFactory($chat_config, $embedding_config);

    $form = [];
    $form_state = new FormState();
    $form_state->setValues([
      'enable' => TRUE,
      'floating_button' => FALSE,
      'floating_button_text' => 'Ask Beacon',
      'floating_button_icon' => 'fa-sparkles',
      'enable_embedding' => TRUE,
      'prompts' => ['First prompt', '', 'Third prompt', ''],
      'disclaimer' => 'A disclaimer.',
      'footer' => 'A footer.',
    ]);

    $this->createForm($this->createAccessStub(FALSE))->submitForm($form, $form_state);

    $this->assertTrue($chat_config->values['enable']);
    $this->assertFalse($chat_config->values['floating_button']);
    $this->assertSame('Ask Beacon', $chat_config->values['floating_button_text']);
    $this->assertSame('fa-sparkles', $chat_config->values['floating_button_icon']);
    // The parent AiEngineChatSettings::submitForm() also writes to the same
    // chat config: empty prompt slots are filtered and reindexed.
    $this->assertSame(['First prompt', 'Third prompt'], $chat_config->values['prompts']);
    $this->assertSame('A disclaimer.', $chat_config->values['disclaimer']);
    $this->assertSame('A footer.', $chat_config->values['footer']);
    $this->assertTrue($embedding_config->values['enable']);
  }

  /**
   * @covers ::create
   */
  public function testCreateSetsSystemInstructionsAccessFromContainer(): void {
    $system_instructions_access = $this->createAccessStub(TRUE);
    $typed_config_manager = $this->createMock(TypedConfigManagerInterface::class);

    $container = new ContainerBuilder();
    $container->set('config.factory', $this->configFactory);
    $container->set('config.typed', $typed_config_manager);
    $container->set('ys_ai_system_instructions.access_check', $system_instructions_access);

    $instance = YsAiSettings::create($container);

    $this->assertInstanceOf(YsAiSettings::class, $instance);

    $reflection = new \ReflectionClass($instance);
    $property = $reflection->getProperty('systemInstructionsAccess');
    $property->setAccessible(TRUE);
    $this->assertSame($system_instructions_access, $property->getValue($instance));
  }

}
