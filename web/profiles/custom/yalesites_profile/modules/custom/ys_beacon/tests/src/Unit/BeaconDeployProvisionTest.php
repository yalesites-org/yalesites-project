<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Config\MemoryStorage;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests that the deploy hook restores settings config:import just deleted.
 *
 * Seeding ys_beacon.settings from hook_install() cannot survive the install
 * path a first-time site actually takes: config:import is what installs the
 * module there, and the changelist the importer recalculates afterwards reads
 * the freshly seeded object as a delete. ys_beacon.deploy.php carries the full
 * trace and the reasoning for fixing it in the deploy phase
 * (yalesites-org/YaleSites-Internal#1491).
 *
 * The config factory here is real, over a memory storage, so the guard and
 * the restore are exercised against genuine Config read/write semantics; only
 * the module extension list is stubbed, and it points at the real module root
 * so the assertions run against the actual shipped defaults.
 *
 * @group ys_beacon
 */
class BeaconDeployProvisionTest extends UnitTestCase {

  /**
   * The config factory backing the hook under test.
   *
   * @var \Drupal\Core\Config\ConfigFactory
   */
  private ConfigFactory $configFactory;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 3) . '/ys_beacon.install';
    require_once dirname(__DIR__, 3) . '/ys_beacon.deploy.php';

    // ConfigFactory subscribes to its own save events to drop the config it has
    // cached; without that wiring a read after a write returns a stale object,
    // which is a harness artifact rather than anything the hook does.
    $dispatcher = new EventDispatcher();
    $this->configFactory = new ConfigFactory(
      new MemoryStorage(),
      $dispatcher,
      $this->createMock(TypedConfigManagerInterface::class)
    );
    $dispatcher->addSubscriber($this->configFactory);

    // Points at the real module root, so the restore reads the shipped file.
    $list = $this->createMock(ModuleExtensionList::class);
    $list->method('getPath')
      ->with('ys_beacon')
      ->willReturn($this->moduleRoot());

    $container = new ContainerBuilder();
    $container->set('config.factory', $this->configFactory);
    $container->set('extension.list.module', $list);
    // The hook loads the install file through the module handler; setUp() has
    // already required it, so the stub only has to accept the call.
    $container->set(
      'module_handler',
      $this->createMock(ModuleHandlerInterface::class)
    );
    $container->set(
      'cache_tags.invalidator',
      $this->createMock(CacheTagsInvalidatorInterface::class)
    );
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);
  }

  /**
   * The module directory holding the shipped config/install file.
   *
   * @return string
   *   The absolute path to the ys_beacon module root.
   */
  private function moduleRoot(): string {
    return dirname(__DIR__, 3);
  }

  /**
   * The shipped defaults, parsed from the real config/install file.
   *
   * @return array
   *   The shipped defaults.
   */
  private function shippedDefaults(): array {
    return Yaml::parseFile(
      $this->moduleRoot() . '/config/install/ys_beacon.settings.yml'
    );
  }

  /**
   * Settings deleted by the import are restored with the shipped defaults.
   */
  public function testRestoresSettingsDeletedByConfigImport(): void {
    $this->assertTrue(
      $this->configFactory->get('ys_beacon.settings')->isNew(),
      'The settings object starts absent, as on a site the import cleared.'
    );

    ys_beacon_deploy_10001();

    $restored = $this->configFactory->get('ys_beacon.settings');
    $this->assertFalse($restored->isNew(), 'The object is recreated.');
    $this->assertSame(
      $this->shippedDefaults(),
      $restored->getRawData(),
      'The shipped defaults are restored verbatim.'
    );

    // Both ship on and are read without a runtime fallback, so an absent
    // settings object silently inverts them: streaming turns off and the AI
    // metadata fields disappear from every content and media form.
    $this->assertTrue($restored->get('streaming'), 'Streaming is back on.');
    $this->assertTrue(
      $restored->get('enable_metadata_fields'),
      'Metadata fields are back on.'
    );

    // Restoring defaults must never switch a site on.
    $this->assertFalse($restored->get('enable_chat'), 'Chat stays off.');
    $this->assertFalse(
      $restored->get('platform_authorized'),
      'Beacon stays unauthorized.'
    );
  }

  /**
   * An existing site's saved settings are left completely untouched.
   *
   * The hook runs once on every site, including the overwhelming majority
   * whose settings the import never touched, so it must be inert there.
   */
  public function testLeavesExistingSettingsUntouched(): void {
    // A legacy chat is present and holds different values, so the guard on the
    // overlay is genuinely exercised: without it the deploy hook would stamp
    // these over the editor's own choices.
    $this->configFactory->getEditable('ai_engine_chat.settings')
      ->setData([
        'floating_button_text' => 'Ask YaleSites',
        'disclaimer' => 'Legacy disclaimer',
      ])
      ->save();
    $this->configFactory->getEditable('ai_engine_metadata.settings')
      ->setData(['enable' => FALSE])
      ->save();

    $saved = $this->shippedDefaults();
    $saved['streaming'] = FALSE;
    $saved['enable_chat'] = TRUE;
    $saved['floating_button_text'] = 'Editor chose this';
    $saved['enable_metadata_fields'] = TRUE;
    $this->configFactory->getEditable('ys_beacon.settings')
      ->setData($saved)
      ->save();

    ys_beacon_deploy_10001();

    $this->assertSame(
      $saved,
      $this->configFactory->get('ys_beacon.settings')->getRawData(),
      'A complete settings object is not rewritten, legacy values and all.'
    );
  }

  /**
   * A settings object recreated with only a few keys has its gaps filled.
   *
   * After the import deletes the settings, the next runtime write recreates
   * the object holding only its own keys - a platform admin switching Beacon
   * on through BeaconPlatformAdminSetting writes three. An existence check
   * would call that healthy and leave the rest reading NULL for good, and
   * because a deploy hook runs once per site it would never get another
   * chance.
   */
  public function testFillsGapsInPartiallyRecreatedObject(): void {
    $this->configFactory->getEditable('ys_beacon.settings')
      ->setData([
        'platform_authorized' => TRUE,
        'enable_chat' => TRUE,
        'enable_metadata_fields' => TRUE,
      ])
      ->save();

    ys_beacon_deploy_10001();

    $healed = $this->configFactory->get('ys_beacon.settings');
    // The site's live state is preserved - the hook must not switch it off.
    $this->assertTrue($healed->get('enable_chat'), 'The live chat toggle survives.');
    $this->assertTrue($healed->get('platform_authorized'), 'Authorization survives.');
    // ... and the keys that were reading NULL are back.
    $this->assertTrue($healed->get('streaming'), 'Streaming comes back on.');
    $this->assertSame(5, $healed->get('top_k'), 'top_k comes back.');
    $this->assertCount(
      count($this->shippedDefaults()),
      $healed->getRawData(),
      'Every shipped key is present.'
    );
  }

  /**
   * A restore keeps the values hook_install() copied from the legacy chat.
   *
   * Hook_install() overlays the editor-facing display settings from the
   * legacy ai_engine chat onto the shipped defaults. Those values are written
   * into the very object the import then deletes, so restoring the shipped
   * defaults alone would silently reset a migrated site's button text,
   * prompts, disclaimer and footer. The dev database this was reproduced on
   * carries exactly that state: floating_button_text reads 'Ask YaleSites'
   * from the legacy chat, not the shipped 'Beacon Chat'.
   */
  public function testRestoreKeepsLegacyAiEngineValues(): void {
    $this->configFactory->getEditable('ai_engine_chat.settings')
      ->setData([
        'floating_button_text' => 'Ask YaleSites',
        'disclaimer' => 'Legacy disclaimer',
      ])
      ->save();
    $this->configFactory->getEditable('ai_engine_metadata.settings')
      ->setData(['enable' => FALSE])
      ->save();

    ys_beacon_deploy_10001();

    $restored = $this->configFactory->get('ys_beacon.settings');
    $this->assertSame(
      'Ask YaleSites',
      $restored->get('floating_button_text'),
      'The legacy button text survives the restore.'
    );
    $this->assertSame(
      'Legacy disclaimer',
      $restored->get('disclaimer'),
      'The legacy disclaimer survives the restore.'
    );
    $this->assertFalse(
      $restored->get('enable_metadata_fields'),
      'The legacy metadata visibility choice survives the restore.'
    );

    // The overlay must land ON TOP of the shipped defaults, not instead of
    // them: hoisting the getEditable() above the seed would wipe every key the
    // legacy chat does not carry, and only these assertions would catch it.
    $this->assertTrue($restored->get('streaming'), 'Streaming still ships on.');
    $this->assertSame(5, $restored->get('top_k'), 'Untouched defaults survive.');

    // Guards the premise: these differ from the shipped defaults, so the
    // assertions above cannot pass by accident.
    $defaults = $this->shippedDefaults();
    $this->assertSame('Beacon Chat', $defaults['floating_button_text']);
    $this->assertTrue($defaults['enable_metadata_fields']);
  }

}
