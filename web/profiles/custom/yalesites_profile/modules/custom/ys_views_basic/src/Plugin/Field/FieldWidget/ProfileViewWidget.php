<?php

namespace Drupal\ys_views_basic\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\ys_views_basic\ViewsBasicManager;

/**
 * Profile (People) listing widget.
 *
 * Serves the profile_card, profile_list_item, profile_condensed, and
 * profile_directory bundles. Profiles differ from other content types in two
 * ways, both expressed declaratively rather than as runtime conditionals
 * (ADR Fear 2): the category control is labelled "Show Affiliations" and uses
 * the affiliation vocabulary / field_affiliation_target_id filter (resolved by
 * getCategoryVocabulary() on the base and by ViewsBasicManager::setupView()),
 * and the extra "directory" display mode is its own bundle (profile_directory)
 * with the thumbnail option disabled via its capability flag.
 *
 * @FieldWidget(
 *   id = "profile_view_widget",
 *   label = @Translation("Profile listing widget"),
 *   field_types = {
 *     "views_basic_params"
 *   }
 * )
 */
class ProfileViewWidget extends ViewsBasicWidgetBase {

  /**
   * {@inheritdoc}
   */
  protected function getContentType(): ?string {
    return ViewsBasicManager::CONTENT_TYPE_PROFILE;
  }

  /**
   * {@inheritdoc}
   *
   * Profiles have no content-type-specific controls beyond the affiliation
   * label override.
   */
  protected function buildEntitySpecificOptions(array &$form, FieldItemListInterface $items, int $delta): void {
  }

  /**
   * {@inheritdoc}
   *
   * Profiles label the category control "Show Affiliations".
   */
  protected function buildCategoryLabel() {
    return $this->t('Show Affiliations');
  }

}
