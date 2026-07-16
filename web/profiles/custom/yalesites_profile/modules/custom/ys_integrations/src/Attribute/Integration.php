<?php

declare(strict_types=1);

namespace Drupal\ys_integrations\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines an Integration attribute for plugin discovery.
 *
 * Additional attribute keys for formatters can be defined in
 * hook_integration_info_alter().
 *
 * @ingroup ys_integrations
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class Integration extends Plugin {

  /**
   * Constructs an Integration attribute.
   *
   * @param string $id
   *   The plugin ID.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $label
   *   (optional) The human-readable name of the formatter type.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $description
   *   (optional) A short description of the formatter type.
   */
  public function __construct(
    public readonly string $id,
    public readonly ?TranslatableMarkup $label = NULL,
    public readonly ?TranslatableMarkup $description = NULL,
  ) {}

}
