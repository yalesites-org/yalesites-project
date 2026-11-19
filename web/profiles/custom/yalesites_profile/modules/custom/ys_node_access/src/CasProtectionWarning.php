<?php

namespace Drupal\ys_node_access;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;

/**
 * The warning an editor confirms before CAS protection is turned on.
 *
 * CAS protection is not a security boundary: it limits who can view a page,
 * not where its data lives. Editors are told so every time they enable it, and
 * that copy is shown in two places -- the node form's confirmation modal
 * (js/cas_protection_modal.js, which reads it from drupalSettings) and the bulk
 * action's confirmation page
 * (\Drupal\ys_node_access\Form\CasProtectConfirmForm). It is defined once,
 * here, so the two cannot drift: protecting thirty pages from Manage Content
 * has to be as explicit as protecting one from its form.
 *
 * Removing protection carries no warning, on either surface.
 */
final class CasProtectionWarning {

  /**
   * Yale's data classification policy, which the warning links to.
   */
  public const POLICY_URL = 'https://your.yale.edu/policies-procedures/policies/1604-data-classification-policy#1604.1';

  /**
   * The warning's heading.
   */
  public static function title(): TranslatableMarkup {
    return new TranslatableMarkup('⚠️ Warning: YaleSites is NOT secure for sensitive data');
  }

  /**
   * The warning's body, one paragraph per item.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup[]
   *   The paragraphs, in display order.
   */
  public static function paragraphs(): array {
    return [
      new TranslatableMarkup('Never upload personal information, student records, research data, or confidential documents to YaleSites. All content is stored on the public internet.'),
      new TranslatableMarkup('CAS protection only limits who can view content - it does not make this platform secure for sensitive data.'),
    ];
  }

  /**
   * The text of the link to the data classification policy.
   */
  public static function policyLinkText(): TranslatableMarkup {
    return new TranslatableMarkup("Learn more about Yale's Data Classification Policy");
  }

  /**
   * The label of the button that accepts the warning and enables protection.
   */
  public static function confirmLabel(): TranslatableMarkup {
    return new TranslatableMarkup('I understand - Enable CAS Protection');
  }

  /**
   * Builds the warning as a render array.
   *
   * @return array
   *   A render array: the heading, the body paragraphs, and the policy link.
   */
  public static function build(): array {
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['cas-protection-warning']],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => self::title(),
      ],
    ];
    foreach (self::paragraphs() as $delta => $paragraph) {
      $build['paragraph_' . $delta] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $paragraph,
      ];
    }
    $build['policy'] = [
      '#type' => 'link',
      '#title' => self::policyLinkText(),
      '#url' => Url::fromUri(self::POLICY_URL),
      '#attributes' => ['target' => '_blank', 'rel' => 'noopener'],
      '#prefix' => '<p>',
      '#suffix' => '</p>',
    ];
    return $build;
  }

  /**
   * Returns the warning as plain strings, for the node form's modal.
   *
   * @return array
   *   The copy, keyed as js/cas_protection_modal.js reads it.
   */
  public static function toJsSettings(): array {
    return [
      'title' => (string) self::title(),
      'paragraphs' => array_map('strval', self::paragraphs()),
      'policyLink' => [
        'text' => (string) self::policyLinkText(),
        'url' => self::POLICY_URL,
      ],
      'confirmLabel' => (string) self::confirmLabel(),
    ];
  }

}
