<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_beacon\Service\LegacyAiEngine;

/**
 * Tests the legacy ai_engine reader/retirement service.
 *
 * Exercised with array-backed config doubles so set()/get() behave like real
 * config and the writes are observable. Covers the three questions the service
 * answers: is the legacy chat widget rendering, is any part of ai_engine still
 * switched on, and turning all of it off.
 *
 * @group ys_beacon
 * @coversDefaultClass \Drupal\ys_beacon\Service\LegacyAiEngine
 */
class LegacyAiEngineTest extends UnitTestCase {

  /**
   * The three legacy modules Beacon supersedes.
   */
  private const ALL_MODULES = [
    'ai_engine_chat',
    'ai_engine_embedding',
    'ai_engine_metadata',
  ];

  /**
   * The three legacy config objects, with every flag off.
   */
  private const ALL_OFF = [
    'ai_engine_chat.settings' => ['enable' => FALSE, 'floating_button' => FALSE],
    'ai_engine_embedding.settings' => ['enable' => FALSE],
    'ai_engine_metadata.settings' => ['enable' => FALSE],
  ];

  /**
   * The legacy config data, mutated in place by the service under test.
   *
   * @var array
   */
  private array $data;

  /**
   * Names of the config objects the service saved, in order.
   *
   * @var string[]
   */
  private array $saved = [];

  /**
   * Builds the service over $this->data with the given modules installed.
   *
   * @param array $data
   *   Map of config name to backing data.
   * @param string[] $installed
   *   The modules reported as installed.
   *
   * @return \Drupal\ys_beacon\Service\LegacyAiEngine
   *   The service under test.
   */
  private function service(array $data, array $installed = self::ALL_MODULES): LegacyAiEngine {
    $this->data = $data;

    $configs = [];
    foreach (array_keys($data) as $name) {
      $config = $this->createMock(Config::class);
      $config->method('get')->willReturnCallback(fn ($key = '') => $this->data[$name][$key] ?? NULL);
      $config->method('set')->willReturnCallback(function ($key, $value) use ($name, $config) {
        $this->data[$name][$key] = $value;
        return $config;
      });
      $config->method('save')->willReturnCallback(function () use ($name, $config) {
        $this->saved[] = $name;
        return $config;
      });
      $configs[$name] = $config;
    }

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $resolve = function ($name) use ($configs) {
      $this->assertArrayHasKey($name, $configs, "Unexpected config name: $name");
      return $configs[$name];
    };
    $factory->method('get')->willReturnCallback($resolve);
    $factory->method('getEditable')->willReturnCallback($resolve);

    $module_handler = $this->createMock(ModuleHandlerInterface::class);
    $module_handler->method('moduleExists')
      ->willReturnCallback(fn ($name) => in_array($name, $installed, TRUE));

    return new LegacyAiEngine($factory, $module_handler);
  }

  /**
   * The chat widget is not active when ai_engine_chat is not installed.
   *
   * @covers ::chatActive
   */
  public function testChatInactiveWhenModuleMissing(): void {
    $service = $this->service(
      ['ai_engine_chat.settings' => ['enable' => TRUE]],
      []
    );

    $this->assertFalse($service->chatActive());
  }

  /**
   * The chat widget is not active when installed but the toggle is off.
   *
   * @covers ::chatActive
   */
  public function testChatInactiveWhenToggleOff(): void {
    $service = $this->service(self::ALL_OFF);

    $this->assertFalse($service->chatActive());
  }

  /**
   * The chat widget is active when installed and the toggle is on.
   *
   * @covers ::chatActive
   */
  public function testChatActiveWhenToggleOn(): void {
    $data = self::ALL_OFF;
    $data['ai_engine_chat.settings']['enable'] = TRUE;
    $service = $this->service($data);

    $this->assertTrue($service->chatActive());
  }

  /**
   * The floating button alone does not make the chat widget active.
   *
   * The widget itself is gated on "enable"; a stale floating_button renders
   * nothing on its own. It is still cleared by disable() (see
   * testDisableClearsEveryFlag), which is why isActive() reports it.
   *
   * @covers ::chatActive
   * @covers ::isActive
   */
  public function testFloatingButtonAloneIsNotChatButIsActive(): void {
    $data = self::ALL_OFF;
    $data['ai_engine_chat.settings']['floating_button'] = TRUE;
    $service = $this->service($data);

    $this->assertFalse($service->chatActive());
    $this->assertTrue($service->isActive());
  }

  /**
   * Nothing is active when ai_engine is not installed at all.
   *
   * @covers ::isActive
   */
  public function testInactiveWhenNoLegacyModulesInstalled(): void {
    $service = $this->service(self::ALL_OFF, []);

    $this->assertFalse($service->isActive());
  }

  /**
   * Nothing is active when every legacy flag is already off.
   *
   * @covers ::isActive
   */
  public function testInactiveWhenEveryFlagOff(): void {
    $service = $this->service(self::ALL_OFF);

    $this->assertFalse($service->isActive());
  }

  /**
   * Any single legacy flag being on makes the stack active.
   *
   * Each part of ai_engine is superseded by Beacon, so the cutover control has
   * work to do whenever any one of them is still switched on - not only when
   * the chat widget is.
   *
   * @dataProvider providerSingleFlagOn
   *
   * @covers ::isActive
   */
  public function testActiveWhenAnySingleFlagOn(string $config_name, string $flag): void {
    $data = self::ALL_OFF;
    $data[$config_name][$flag] = TRUE;
    $service = $this->service($data);

    $this->assertTrue($service->isActive());
  }

  /**
   * Data provider: each legacy flag, one at a time.
   */
  public static function providerSingleFlagOn(): array {
    return [
      'chat widget' => ['ai_engine_chat.settings', 'enable'],
      'floating button' => ['ai_engine_chat.settings', 'floating_button'],
      'embedding pipeline' => ['ai_engine_embedding.settings', 'enable'],
      'metadata fields' => ['ai_engine_metadata.settings', 'enable'],
    ];
  }

  /**
   * A flag belonging to an uninstalled module is ignored.
   *
   * Stale config can outlive its module; a flag nobody reads is not "active"
   * and must not make the cutover control appear.
   *
   * @covers ::isActive
   */
  public function testIgnoresFlagsOfUninstalledModules(): void {
    $data = self::ALL_OFF;
    $data['ai_engine_embedding.settings']['enable'] = TRUE;
    $service = $this->service($data, ['ai_engine_chat', 'ai_engine_metadata']);

    $this->assertFalse($service->isActive());
  }

  /**
   * Disabling turns every legacy flag off across all three modules.
   *
   * @covers ::disable
   */
  public function testDisableClearsEveryFlag(): void {
    $service = $this->service([
      'ai_engine_chat.settings' => ['enable' => TRUE, 'floating_button' => TRUE],
      'ai_engine_embedding.settings' => ['enable' => TRUE],
      'ai_engine_metadata.settings' => ['enable' => TRUE],
    ]);

    $service->disable();

    $this->assertSame(self::ALL_OFF, $this->data);
    $this->assertFalse($service->isActive());
  }

  /**
   * Disabling only writes the config objects that have a flag on.
   *
   * Writing config invalidates its cache tags, so an already-retired module is
   * left untouched rather than re-saved.
   *
   * @covers ::disable
   */
  public function testDisableSkipsAlreadyOffConfig(): void {
    $data = self::ALL_OFF;
    $data['ai_engine_metadata.settings']['enable'] = TRUE;
    $service = $this->service($data);

    $service->disable();

    $this->assertSame(['ai_engine_metadata.settings'], $this->saved);
  }

  /**
   * A repeat call writes nothing at all.
   *
   * @covers ::disable
   */
  public function testDisableIsIdempotent(): void {
    $service = $this->service([
      'ai_engine_chat.settings' => ['enable' => TRUE, 'floating_button' => TRUE],
      'ai_engine_embedding.settings' => ['enable' => TRUE],
      'ai_engine_metadata.settings' => ['enable' => TRUE],
    ]);

    $service->disable();
    $first_pass = $this->saved;
    $service->disable();

    $this->assertCount(3, $first_pass);
    $this->assertSame($first_pass, $this->saved);
  }

  /**
   * Disabling never touches config owned by an uninstalled module.
   *
   * @covers ::disable
   */
  public function testDisableSkipsUninstalledModules(): void {
    $service = $this->service([
      'ai_engine_chat.settings' => ['enable' => TRUE, 'floating_button' => TRUE],
      'ai_engine_embedding.settings' => ['enable' => TRUE],
      'ai_engine_metadata.settings' => ['enable' => TRUE],
    ], ['ai_engine_chat']);

    $service->disable();

    $this->assertSame([
      'ai_engine_chat.settings' => ['enable' => FALSE, 'floating_button' => FALSE],
      // Not installed, so left exactly as found.
      'ai_engine_embedding.settings' => ['enable' => TRUE],
      'ai_engine_metadata.settings' => ['enable' => TRUE],
    ], $this->data);
  }

}
