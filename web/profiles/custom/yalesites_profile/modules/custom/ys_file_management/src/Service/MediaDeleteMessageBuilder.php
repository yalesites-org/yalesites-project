<?php

namespace Drupal\ys_file_management\Service;

use Drupal\Core\StringTranslation\PluralTranslatableMarkup;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\media\MediaInterface;

/**
 * Service for building consistent error and informational messages.
 *
 * Handles media deletion messaging across individual and bulk operations.
 */
class MediaDeleteMessageBuilder {

  use StringTranslationTrait;

  /**
   * User permission levels.
   */
  const LEVEL_REGULAR = 'regular';
  const LEVEL_SITE_ADMIN = 'site_admin';
  const LEVEL_PLATFORM_ADMIN = 'platform_admin';

  /**
   * Builds an ownership error message.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media entity.
   *
   * @return array
   *   Render array for the message.
   */
  public function buildOwnershipMessage(MediaInterface $media): array {
    $media_owner = $media->getOwner();
    $owner_name = $media_owner ? $media_owner->getDisplayName() : $this->t('Unknown');

    return [
      '#markup' => $this->t('This media item cannot be deleted because you do not own it. This media is owned by %owner. Please contact the owner or a site administrator if you need to delete this media item.', [
        '%owner' => $owner_name,
      ]),
    ];
  }

  /**
   * Builds a usage blocking message based on user permission level.
   *
   * @param int $usage_count
   *   The number of places where media is used.
   * @param string $user_level
   *   The user permission level (regular, site_admin, platform_admin).
   * @param bool $is_media_usage
   *   TRUE if this is media usage (vs file usage).
   *
   * @return array
   *   Render array for the message.
   */
  public function buildUsageMessage(int $usage_count, string $user_level, bool $is_media_usage = TRUE): array {
    if ($is_media_usage) {
      // Base message with usage count and recorded usages reference.
      $base_message = $this->t('Cannot delete: media is used in @count location(s). See "recorded usages" above for details.', [
        '@count' => $usage_count,
      ]);

      $additional_message = match ($user_level) {
        self::LEVEL_SITE_ADMIN => $this->t('Remove references first, or contact a platform administrator for force deletion.'),
        self::LEVEL_PLATFORM_ADMIN => $this->t('Use force delete option below to override.'),
        default => $this->t('Please remove those references first, then try again.'),
      };

      return [
        '#markup' => $base_message . ' ' . $additional_message,
      ];
    }
    else {
      // File usage (not media usage).
      return [
        '#markup' => new PluralTranslatableMarkup($usage_count - 1,
          'This media item cannot be deleted because its file is used in 1 other place. Please remove that reference first, then try again.',
          'This media item cannot be deleted because its file is used in @count other places. Please remove those references first, then try again.'
        ),
      ];
    }
  }

  /**
   * Builds a success message for media deletion.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The deleted media entity.
   *
   * @return array
   *   Render array for the message.
   */
  public function buildSuccessMessage(MediaInterface $media): array {
    return [
      '#markup' => $this->t('The @entity-type %label has been deleted.', [
        '@entity-type' => $media->getEntityType()->getSingularLabel(),
        '%label' => $media->label(),
      ]),
    ];
  }

  /**
   * Builds the standard "action cannot be undone" message.
   *
   * @return array
   *   Render array for the message.
   */
  public function buildActionWarningMessage(): array {
    return [
      '#markup' => $this->t('This action cannot be undone.'),
    ];
  }

  /**
   * Builds force delete warning markup for platform administrators.
   *
   * @param int $usage_count
   *   The number of file usages.
   *
   * @return string
   *   HTML markup for the warning.
   */
  public function buildForceDeleteWarningMarkup(int $usage_count): string {
    if ($usage_count <= 1) {
      return '';
    }

    return '<div class="messages messages--warning">' .
      new PluralTranslatableMarkup($usage_count - 1,
        '<strong>Warning:</strong> This file is used in 1 other place. Force deleting it will break other content.',
        '<strong>Warning:</strong> This file is used in @count other places. Force deleting it will break other content.'
      ) . '</div>';
  }

  /**
   * Gets the user permission level based on permissions.
   *
   * @param bool $can_delete_any_file
   *   Whether user has "delete media files regardless of owner" permission.
   * @param bool $can_force_delete
   *   Whether user has "force delete media files" permission.
   *
   * @return string
   *   The user permission level constant.
   */
  public function getUserLevel(bool $can_delete_any_file, bool $can_force_delete): string {
    if ($can_force_delete) {
      return self::LEVEL_PLATFORM_ADMIN;
    }
    if ($can_delete_any_file) {
      return self::LEVEL_SITE_ADMIN;
    }
    return self::LEVEL_REGULAR;
  }

}
