<?php

namespace Drupal\Tests\ys_contoso_chat\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
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

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadMultiple')->willReturn([]);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('ai_assistant')->willReturn($storage);

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

    $this->buildContainer($current_user, $config_factory);

    return new YsContosoChatSettingsForm($entity_type_manager);
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

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadMultiple')->willReturn([]);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('ai_assistant')->willReturn($storage);

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

    $this->buildContainer($current_user, $config_factory);

    $form = new YsContosoChatSettingsForm($entity_type_manager);
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

    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('getEditable')->willReturn($config);
    $config_factory->method('get')->willReturn($config);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadMultiple')->willReturn([]);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('ai_assistant')->willReturn($storage);

    $this->buildContainer($current_user, $config_factory);

    $form = new YsContosoChatSettingsForm($entity_type_manager);
    $form_state = new FormState();
    $form_state->setValue('assistant_id', 'hacked-value');
    $form_state->setValue('enable', FALSE);
    $form_state->setValue('prompts', []);
    $form_state->setValue('disclaimer', ['value' => '']);
    $form_state->setValue('footer', ['value' => '']);
    $form_state->setValue('floating_button', FALSE);
    $form_state->setValue('floating_button_text', '');
    $form_state->setValue('floating_button_icon', 'fa-comments');

    $form_array = [];
    $form->submitForm($form_array, $form_state);

    $this->assertNotContains('assistant_id', $keysSet, 'Non-user-1 submitForm must not write assistant_id to config.');
  }

}
