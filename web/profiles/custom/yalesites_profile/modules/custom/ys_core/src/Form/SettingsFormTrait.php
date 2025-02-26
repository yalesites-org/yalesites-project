<?php

namespace Drupal\ys_core\Form;

use Drupal\path_alias\AliasManager;

/**
 * Shared functions for ys_core settings forms.
 */
trait SettingsFormTrait {

  /**
   * Translate internal node links to path links.
   *
   * @param string $link
   *   The path entered from the form.
   */
  protected function translateNodeLinks($link) {
    $pathAliasManager = $this->getPathAliasManager();

    // If link URL is an internal path, use the path alias instead.
    $link = (str_starts_with($link, "/node/")) ? $pathAliasManager->getAliasByPath($link) : $link;
    return $link;
  }

  /**
   * Check that links have both a URL and a link title.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state of the parent form.
   * @param string $field_id
   *   The id of a field on the config form.
   */
  protected function validateLinks($form_state, $field_id) {
    if (($value = $form_state->getValue($field_id))) {
      foreach ($value as $link) {
        if (empty($link['link_url']) || empty($link['link_title'])) {
          $form_state->setErrorByName(
            $field_id,
            $this->t("Any link specified must have both a URL and a link title."),
          );
        }

      }
    }
  }

  /**
   * Makes sure we are providing a pathAliasManager.
   *
   * @return \Drupal\path_alias\AliasManager
   *   The AliasManager.
   */
  abstract protected function getPathAliasManager(): AliasManager;

}
