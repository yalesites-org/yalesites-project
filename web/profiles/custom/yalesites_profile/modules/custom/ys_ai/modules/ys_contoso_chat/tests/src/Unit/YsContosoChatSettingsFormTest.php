<?php

namespace Drupal\Tests\ys_contoso_chat\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\ServerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_ai\Service\BeaconIndexProvisioner;
use Drupal\ys_ai\Service\BeaconIndexResult;
use Drupal\ys_contoso_chat\Form\YsContosoChatSettingsForm;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @coversDefaultClass \Drupal\ys_contoso_chat\Form\YsContosoChatSettingsForm
 * @group yalesites
 */
class YsContosoChatSettingsFormTest extends UnitTestCase {

  /**
   * Sets up Drupal's container with current user and config factory mocks.
   *
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The mocked current user account.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The mocked config factory.
   */
  protected function buildContainer(AccountInterface $current_user, ConfigFactoryInterface $config_factory): void {
    $container = new ContainerBuilder();
    $container->set('current_user', $current_user);
    $container->set('config.factory', $config_factory);
    $container->set('string_translation', $this->getStringTranslationStub());
    $container->set('messenger', $this->createMock(MessengerInterface::class));
    \Drupal::setContainer($container);
  }

  /**
   * Builds an entity type manager whose getStorage() returns the given map.
   *
   * @param array<string, \Drupal\Core\Entity\EntityStorageInterface> $storages
   *   Storage mocks keyed by entity type id. An empty 'ai_assistant' storage
   *   is provided by default for the form's assistant options.
   *
   * @return \Drupal\Core\Entity\EntityTypeManagerInterface
   *   The mocked entity type manager.
   */
  protected function mockEntityTypeManager(array $storages = []): EntityTypeManagerInterface {
    if (!isset($storages['ai_assistant'])) {
      $assistant_storage = $this->createMock(EntityStorageInterface::class);
      $assistant_storage->method('loadMultiple')->willReturn([]);
      $storages['ai_assistant'] = $assistant_storage;
    }
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->willReturnCallback(
      fn (string $type): EntityStorageInterface => $storages[$type]
    );
    return $entity_type_manager;
  }

  /**
   * Builds an entity storage mock that returns $entity for load().
   */
  protected function mockStorage(?object $entity): EntityStorageInterface {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturn($entity);
    return $storage;
  }

  /**
   * Builds a config factory whose editable config swallows set()/save().
   */
  protected function mockConfigFactory(): ConfigFactoryInterface {
    $config = $this->createMock(Config::class);
    $config->method('set')->willReturnSelf();
    $config->method('save')->willReturnSelf();
    $config->method('get')->willReturn(NULL);
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('getEditable')->willReturn($config);
    $config_factory->method('get')->willReturn($config);
    return $config_factory;
  }

  /**
   * Returns a populated FormState for the settings form submit handler.
   */
  protected function submitFormState(bool $enable): FormState {
    $form_state = new FormState();
    $form_state->setValue('enable', $enable);
    $form_state->setValue('assistant_id', 'test-assistant');
    $form_state->setValue('prompts', []);
    $form_state->setValue('disclaimer', ['value' => '']);
    $form_state->setValue('footer', ['value' => '']);
    $form_state->setValue('floating_button', FALSE);
    $form_state->setValue('floating_button_text', '');
    $form_state->setValue('floating_button_icon', 'fa-comments');
    return $form_state;
  }

  /**
   * Builds the form with a config factory stub and a mocked current user.
   *
   * @param int $userId
   *   The user ID to mock for the current user.
   *
   * @return \Drupal\ys_contoso_chat\Form\YsContosoChatSettingsForm
   *   The form under test.
   */
  protected function buildForm(int $userId): YsContosoChatSettingsForm {
    $current_user = $this->createMock(AccountInterface::class);
    $current_user->method('id')->willReturn($userId);

    $entity_type_manager = $this->mockEntityTypeManager();

    $config_factory = $this->getConfigFactoryStub([
      'ys_contoso_chat.settings' => [
        'assistant_id' => 'test-assistant',
        'enable' => TRUE,
        'floating_button' => FALSE,
        'floating_button_text' => 'Ask Beacon',
        'floating_button_icon' => 'fa-comments',
        'initial_questions' => [],
        'disclaimer' => '',
        'footer' => '',
      ],
    ]);

    $typed_config_manager = $this->createMock(TypedConfigManagerInterface::class);

    $this->buildContainer($current_user, $config_factory);

    return new YsContosoChatSettingsForm(
      $config_factory,
      $typed_config_manager,
      $entity_type_manager,
      $this->createMock(BeaconIndexProvisioner::class),
    );
  }

  /**
   * @covers ::buildForm
   */
  public function testAssistantFieldIsSelectForUserOne(): void {
    $built = $this->buildForm(1)->buildForm([], new FormState());
    $this->assertSame('select', $built['assistant_id']['#type']);
  }

  /**
   * @covers ::buildForm
   */
  public function testAssistantFieldIsItemForNonUserOne(): void {
    $built = $this->buildForm(2)->buildForm([], new FormState());
    $this->assertSame('item', $built['assistant_id']['#type']);
  }

  /**
   * @covers ::buildForm
   */
  public function testAssistantItemShowsNoneSelectedWhenNoAssistantConfigured(): void {
    $current_user = $this->createMock(AccountInterface::class);
    $current_user->method('id')->willReturn(2);

    $entity_type_manager = $this->mockEntityTypeManager();

    $config_factory = $this->getConfigFactoryStub([
      'ys_contoso_chat.settings' => [
        'assistant_id' => NULL,
        'enable' => FALSE,
        'floating_button' => FALSE,
        'floating_button_text' => '',
        'floating_button_icon' => 'fa-comments',
        'initial_questions' => [],
        'disclaimer' => '',
        'footer' => '',
      ],
    ]);

    $typed_config_manager = $this->createMock(TypedConfigManagerInterface::class);

    $this->buildContainer($current_user, $config_factory);

    $form = new YsContosoChatSettingsForm(
      $config_factory,
      $typed_config_manager,
      $entity_type_manager,
      $this->createMock(BeaconIndexProvisioner::class),
    );
    $built = $form->buildForm([], new FormState());
    $this->assertStringContainsString('None selected', (string) $built['assistant_id']['#markup']);
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitDoesNotWriteAssistantIdForNonUserOne(): void {
    $current_user = $this->createMock(AccountInterface::class);
    $current_user->method('id')->willReturn(2);

    $keysSet = [];
    $config = $this->createMock(Config::class);
    $config->method('set')->willReturnCallback(function (string $key) use (&$keysSet, $config): Config {
      $keysSet[] = $key;
      return $config;
    });
    $config->method('save')->willReturnSelf();
    $config->method('get')->willReturn(NULL);

    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('getEditable')->willReturn($config);
    $config_factory->method('get')->willReturn($config);

    $server = $this->createMock(ServerInterface::class);
    $server->method('status')->willReturn(FALSE);
    $entity_type_manager = $this->mockEntityTypeManager([
      'search_api_server' => $this->mockStorage($server),
    ]);

    $typed_config_manager = $this->createMock(TypedConfigManagerInterface::class);

    $this->buildContainer($current_user, $config_factory);

    $form = new YsContosoChatSettingsForm(
      $config_factory,
      $typed_config_manager,
      $entity_type_manager,
      $this->createMock(BeaconIndexProvisioner::class),
    );

    $form_array = [];
    $form->submitForm($form_array, $this->submitFormState(FALSE));

    $this->assertNotContains('assistant_id', $keysSet, 'Non-user-1 submitForm must not write assistant_id to config.');
  }

  /**
   * Enabling chat provisions Azure, enables the stack, and reindexes.
   *
   * @covers ::submitForm
   * @covers ::enableBeaconStack
   */
  public function testEnableProvisionsEnablesStackAndReindexes(): void {
    $current_user = $this->createMock(AccountInterface::class);
    $current_user->method('id')->willReturn(1);

    $provisioner = $this->createMock(BeaconIndexProvisioner::class);
    $provisioner->expects($this->once())
      ->method('ensureIndexExists')
      ->willReturn(BeaconIndexResult::created('beacon-local'));

    $server = $this->createMock(ServerInterface::class);
    $server->method('status')->willReturn(FALSE);
    $server->expects($this->once())->method('setStatus')->with(TRUE)->willReturnSelf();
    $server->expects($this->once())->method('save');

    $index = $this->createMock(IndexInterface::class);
    $index->method('status')->willReturn(FALSE);
    $index->expects($this->once())->method('setStatus')->with(TRUE)->willReturnSelf();
    $index->expects($this->once())->method('save');
    $index->expects($this->once())->method('reindex');

    $entity_type_manager = $this->mockEntityTypeManager([
      'search_api_server' => $this->mockStorage($server),
      'search_api_index' => $this->mockStorage($index),
    ]);

    $config_factory = $this->mockConfigFactory();
    $this->buildContainer($current_user, $config_factory);

    $form = new YsContosoChatSettingsForm(
      $config_factory,
      $this->createMock(TypedConfigManagerInterface::class),
      $entity_type_manager,
      $provisioner,
    );

    $form_array = [];
    $form->submitForm($form_array, $this->submitFormState(TRUE));
  }

  /**
   * When provisioning fails, the stack is not enabled.
   *
   * @covers ::submitForm
   * @covers ::enableBeaconStack
   */
  public function testEnableLeavesStackDisabledWhenProvisioningFails(): void {
    $current_user = $this->createMock(AccountInterface::class);
    $current_user->method('id')->willReturn(1);

    $provisioner = $this->createMock(BeaconIndexProvisioner::class);
    $provisioner->expects($this->once())
      ->method('ensureIndexExists')
      ->willReturn(BeaconIndexResult::failed('Azure unreachable'));

    $server = $this->createMock(ServerInterface::class);
    $server->expects($this->never())->method('setStatus');

    $index = $this->createMock(IndexInterface::class);
    $index->expects($this->never())->method('setStatus');
    $index->expects($this->never())->method('reindex');

    $entity_type_manager = $this->mockEntityTypeManager([
      'search_api_server' => $this->mockStorage($server),
      'search_api_index' => $this->mockStorage($index),
    ]);

    $config_factory = $this->mockConfigFactory();
    $this->buildContainer($current_user, $config_factory);

    $form = new YsContosoChatSettingsForm(
      $config_factory,
      $this->createMock(TypedConfigManagerInterface::class),
      $entity_type_manager,
      $provisioner,
    );

    $form_array = [];
    $form->submitForm($form_array, $this->submitFormState(TRUE));
  }

  /**
   * Disabling chat disables the server and does not reindex.
   *
   * @covers ::submitForm
   * @covers ::disableBeaconStack
   */
  public function testDisableDisablesServer(): void {
    $current_user = $this->createMock(AccountInterface::class);
    $current_user->method('id')->willReturn(1);

    $provisioner = $this->createMock(BeaconIndexProvisioner::class);
    $provisioner->expects($this->never())->method('ensureIndexExists');

    $server = $this->createMock(ServerInterface::class);
    $server->method('status')->willReturn(TRUE);
    $server->expects($this->once())->method('setStatus')->with(FALSE)->willReturnSelf();
    $server->expects($this->once())->method('save');

    $entity_type_manager = $this->mockEntityTypeManager([
      'search_api_server' => $this->mockStorage($server),
    ]);

    $config_factory = $this->mockConfigFactory();
    $this->buildContainer($current_user, $config_factory);

    $form = new YsContosoChatSettingsForm(
      $config_factory,
      $this->createMock(TypedConfigManagerInterface::class),
      $entity_type_manager,
      $provisioner,
    );

    $form_array = [];
    $form->submitForm($form_array, $this->submitFormState(FALSE));
  }

  /**
   * Enabling when the stack is already enabled does not re-save status.
   *
   * @covers ::submitForm
   * @covers ::enableBeaconStack
   */
  public function testEnableDoesNotResaveWhenAlreadyEnabled(): void {
    $current_user = $this->createMock(AccountInterface::class);
    $current_user->method('id')->willReturn(1);

    $provisioner = $this->createMock(BeaconIndexProvisioner::class);
    $provisioner->method('ensureIndexExists')->willReturn(BeaconIndexResult::alreadyExists('beacon-local'));

    $server = $this->createMock(ServerInterface::class);
    $server->method('status')->willReturn(TRUE);
    $server->expects($this->never())->method('setStatus');

    $index = $this->createMock(IndexInterface::class);
    $index->method('status')->willReturn(TRUE);
    $index->expects($this->never())->method('setStatus');
    // A full reindex is still queued so the (possibly new) Azure index fills.
    $index->expects($this->once())->method('reindex');

    $entity_type_manager = $this->mockEntityTypeManager([
      'search_api_server' => $this->mockStorage($server),
      'search_api_index' => $this->mockStorage($index),
    ]);

    $config_factory = $this->mockConfigFactory();
    $this->buildContainer($current_user, $config_factory);

    $form = new YsContosoChatSettingsForm(
      $config_factory,
      $this->createMock(TypedConfigManagerInterface::class),
      $entity_type_manager,
      $provisioner,
    );

    $form_array = [];
    $form->submitForm($form_array, $this->submitFormState(TRUE));
  }

}
