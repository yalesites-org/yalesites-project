<?php

namespace Drupal\ys_views_basic\Plugin\Field\FieldWidget;

use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ys_views_basic\ViewsBasicManager;

/**
 * Profile (People) listing widget.
 *
 * Serves the profile_card, profile_list_item and profile_condensed bundles.
 * Profiles differ from other content types in two ways, both expressed
 * declaratively rather than as runtime conditionals (ADR Fear 2): the category
 * control is labelled "Show Affiliations" and uses the affiliation vocabulary /
 * field_affiliation_target_id filter (resolved by getCategoryVocabulary() on
 * the base and by ViewsBasicManager::setupView()), and the listing can show
 * the profile data pass-throughs (department, email, phone, pronouns).
 */
#[FieldWidget(
  id: 'profile_view_widget',
  label: new TranslatableMarkup('Profile listing widget'),
  field_types: ['views_basic_params'],
)]
class ProfileViewWidget extends ViewsBasicWidgetBase {

  /**
   * Profile view modes that render the profile data pass-throughs (#1648).
   *
   * These are the modes whose node template embeds the shared reference card,
   * which is what honours the show_department/email/phone/pronouns flags.
   * Listed rather than tested against at each call site so the set is stated
   * once and asserted directly by the tests. Condensed renders none of these
   * fields, so it is left out. The same set decides what the shown search
   * scope searches (#706), so it is stated on the manager.
   */
  const PROFILE_FIELD_VIEW_MODES = ViewsBasicManager::PROFILE_DETAIL_VIEW_MODES;

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
   * pronouns. These used to be available only through the retired profile
   * directory card, which rendered all three unconditionally; as checkboxes on
   * the profile widget they work with whichever design option the listing
   * uses. No #states are needed because this widget only ever serves profiles,
   * which is what keeps the options off other content types' forms.
   */
  protected function buildEntitySpecificOptions(array &$form, FieldItemListInterface $items, int $delta): void {
    if (!in_array($this->getViewMode(), self::PROFILE_FIELD_VIEW_MODES, TRUE)) {
      return;
    }
    $form['group_user_selection']['entity_and_view_mode']['profile_field_options'] = $this->buildResultOptionsSubsection(
      $this->t('People options'),
      [
        'show_department' => $this->t('Show Department'),
        'show_email' => $this->t('Show Email'),
        'show_phone' => $this->t('Show Phone'),
        'show_pronouns' => $this->t('Show Pronouns'),
      ],
      $this->getStoredOptions('profile_field_options', $items[$delta]->params),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function massageEntitySpecificParams(array &$paramData, array $built, array $form, FormStateInterface $form_state): void {
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

  /**
   * {@inheritdoc}
   *
   * The visitor-facing filter is labelled Affiliation, so the toggle that
   * turns it on says the same (#1171).
   */
  protected function getExposedFilterOptions(): array {
    $options = parent::getExposedFilterOptions();
    $options['show_category_filter'] = $this->t('Show Affiliation');
    return $options;
  }

}
