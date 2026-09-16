<?php

namespace Drupal\ys_beacon\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Reads and retires the legacy ai_engine stack that Beacon supersedes.
 *
 * The platform is replacing ai_engine/ys_ai with ys_beacon. While both are
 * installed they must not both be live: Beacon yields to the legacy chat widget
 * whenever it is enabled, so a site is cut over by switching ai_engine off and
 * Beacon on - in that order.
 *
 * That cutover is deliberately a platform admin action rather than something a
 * deploy does on its own (yalesites-org/YaleSites-Internal#1459). Beacon cannot
 * be brought up during a deploy: ys_beacon is enabled by the core.extension
 * diff in the config import's extension step, which runs before the step that
 * creates the key.key.azure_ai_search_* entities and the Beacon Search API
 * server and index it needs - so there is no point in the deploy at which its
 * Azure index can be provisioned. Deferring it to cron means an unattended job
 * takes a live site's working chatbot away at an unpredictable moment, before
 * anyone has confirmed Beacon can actually answer from a populated index. So
 * this service only reports the legacy state and turns it off when asked; the
 * Beacon side is switched on from the Platform Admin Settings page, whose
 * handler provisions the index synchronously and reports real failures.
 *
 * @see \Drupal\ys_beacon\Plugin\PlatformAdminSetting\BeaconPlatformAdminSetting
 */
class LegacyAiEngine {

  /**
   * The legacy enable flags Beacon supersedes, keyed by the owning module.
   *
   * Each entry is the module's config object name and the boolean keys that
   * must be off for that part of ai_engine to be dormant: the chat widget (and
   * its floating button), the embedding pipeline, and the AI metadata fields.
   */
  protected const FLAGS = [
    'ai_engine_chat' => ['ai_engine_chat.settings', ['enable', 'floating_button']],
    'ai_engine_embedding' => ['ai_engine_embedding.settings', ['enable']],
    'ai_engine_metadata' => ['ai_engine_metadata.settings', ['enable']],
  ];

  /**
   * Constructs a LegacyAiEngine object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected ModuleHandlerInterface $moduleHandler,
  ) {
  }

  /**
   * Whether the legacy chat widget is installed and enabled.
   *
   * This is the "two chat widgets" condition: while it holds, Beacon stands
   * down so visitors only ever see one assistant. It is narrower than
   * isActive() - the embedding pipeline and metadata fields render nothing.
   *
   * @return bool
   *   TRUE when ai_engine_chat is installed and its widget is enabled.
   */
  public function chatActive(): bool {
    return $this->anyFlagOn('ai_engine_chat', 'ai_engine_chat.settings', ['enable']);
  }

  /**
   * Whether any part of the legacy stack is still switched on.
   *
   * True exactly when disable() would change something, so the assisted-cutover
   * control appears only while it has work to do. Flags belonging to a module
   * that is not installed are ignored: nothing reads them.
   *
   * @return bool
   *   TRUE when at least one legacy flag is on.
   */
  public function isActive(): bool {
    foreach (self::FLAGS as $module => [$config_name, $flags]) {
      if ($this->anyFlagOn($module, $config_name, $flags)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Turns off the legacy chat widget, embedding pipeline, and metadata fields.
   *
   * Idempotent: a config object is only saved when one of its flags is actually
   * on, so repeat calls write nothing and never needlessly invalidate cache
   * tags. All three config objects are config_ignored, so these runtime changes
   * survive later config imports.
   */
  public function disable(): void {
    foreach (self::FLAGS as $module => [$config_name, $flags]) {
      if (!$this->anyFlagOn($module, $config_name, $flags)) {
        continue;
      }
      $config = $this->configFactory->getEditable($config_name);
      foreach ($flags as $flag) {
        $config->set($flag, FALSE);
      }
      $config->save();
    }
  }

  /**
   * Whether an installed module has any of the given flags switched on.
   *
   * @param string $module
   *   The module owning the config; an uninstalled one has no active flags,
   *   because nothing reads them.
   * @param string $config_name
   *   The config object name holding the flags.
   * @param string[] $flags
   *   The boolean keys to test.
   *
   * @return bool
   *   TRUE when the module is installed and at least one flag is on.
   */
  private function anyFlagOn(string $module, string $config_name, array $flags): bool {
    if (!$this->moduleHandler->moduleExists($module)) {
      return FALSE;
    }
    $config = $this->configFactory->get($config_name);
    foreach ($flags as $flag) {
      if ($config->get($flag)) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
