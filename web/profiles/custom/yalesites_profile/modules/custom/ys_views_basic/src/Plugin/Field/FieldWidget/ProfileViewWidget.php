<?php

namespace Drupal\ys_views_basic\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
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
   * Profile view modes that render the profile data pass-throughs (#1648).
   *
   * These are the modes whose node template embeds the shared reference card,
   * which is what honours the show_department/email/phone/pronouns flags.
   * Listed rather than tested against at each call site so the set is stated
   * once and asserted directly by the tests.
   *
   * Deliberately excludes "directory": that mode renders the separate
   * directory-listing card, which shows department, email and phone
   * unconditionally and would ignore the checkboxes. Offering controls there
   * would be exactly the clutter this ticket set out to remove. Whether the
   * directory bundle survives at all is the open question flagged on #1648
   * for the team, so nothing about it is changed here.
   */
  const PROFILE_FIELD_VIEW_MODES = ['card', 'list_item'];

  /**
   * {@inheritdoc}
   */
  protected function getContentType(): ?string {
    return ViewsBasicManager::CONTENT_TYPE_PROFILE;
  }

  /**
   * {@inheritdoc}
   *
   * Adds the profile data pass-throughs (#1648): department, email, phone and
   * pronouns. These used to be available only through the profile-only
   * directory card, which rendered all three unconditionally; as checkboxes on
   * the profile widget they work with whichever design option the listing
   * uses. No #states are needed because this widget only ever serves profiles,
   * which is what keeps the options off other content types' forms.
   */
  protected function buildEntitySpecificOptions(array &$form, FieldItemListInterface $items, int $delta): void {
    if (!in_array($this->getViewMode(), self::PROFILE_FIELD_VIEW_MODES, TRUE)) {
      return;
    }
    $form['group_user_selection']['entity_and_view_mode']['profile_field_options'] = [
      '#type' => 'checkboxes',
      // Styling hook (#1481) — see
      // EventViewWidget::buildEntitySpecificOptions() for why this needs the
      // fieldset's between-groups gap rather than the tighter sibling-option
      // gap, and why #prefix/#suffix rather than #attributes.
      '#prefix' => '<div class="vb-result-content__subsection">',
      '#suffix' => '</div>',
      '#options' => [
        'show_department' => $this->t('Show Department'),
        'show_email' => $this->t('Show Email'),
        'show_phone' => $this->t('Show Phone'),
        'show_pronouns' => $this->t('Show Pronouns'),
      ],
      '#title' => $this->t('People options'),
      '#tree' => TRUE,
      '#default_value' => $items[$delta]->params
        ? $this->viewsBasicManager->getDefaultParamValue('profile_field_options', $items[$delta]->params)
        : [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function massageEntitySpecificParams(array &$paramData, array $form, FormStateInterface $form_state): void {
    // Looked up by key: groupFieldDisplayRow() moves this into the display row.
    $built = static::flattenBuiltElements($form['group_user_selection']['entity_and_view_mode'] ?? []);
    $paramData['profile_field_options'] = $built['profile_field_options']['#value'] ?? [];
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
