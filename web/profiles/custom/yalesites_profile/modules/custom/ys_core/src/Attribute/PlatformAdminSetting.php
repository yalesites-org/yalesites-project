<?php

declare(strict_types=1);

namespace Drupal\ys_core\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines a PlatformAdminSetting attribute for plugin discovery.
 *
 * A platform admin setting plugin contributes a self-contained section to the
 * platform-admin-only Platform Admin Settings page. Additional attribute keys
 * can be defined in hook_ys_core_platform_admin_setting_info_alter().
 *
 * @ingroup ys_core
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class PlatformAdminSetting extends Plugin {

  /**
   * Constructs a PlatformAdminSetting attribute.
   *
   * @param string $id
   *   The plugin ID.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $label
   *   (optional) The human-readable name of the settings section.
   * @param int $weight
   *   (optional) The order weight; lower weights render first. Defaults to 0.
   */
  public function __construct(
    public readonly string $id,
    public readonly ?TranslatableMarkup $label = NULL,
    public readonly int $weight = 0,
  ) {}

}
