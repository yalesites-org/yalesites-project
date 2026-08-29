<?php

namespace Drupal\ys_views_wizard_spike;

use Drupal\gin_lb\Service\ContextValidatorInterface;

/**
 * Teaches gin_lb that the wizard form is a Layout Builder form.
 *
 * SPIKE ONLY, and one of this spike's more important findings.
 *
 * gin_lb decides whether to apply its Gin styling in two independent places:
 *
 * - isLayoutBuilderRoute() matches the route name against
 *   /^(layout_builder\.([^.]+\.)?)/ and then fires
 *   hook_gin_lb_is_layout_builder_route_alter(), so a custom route CAN opt in
 *   with a hook. The spike does that in the .module file.
 * - isLayoutBuilderFormId() matches a hardcoded protected $formIds list plus a
 *   str_contains($form_id, 'layout_builder_form') fallback, and fires NO
 *   alter. A custom form therefore cannot opt in with a hook.
 *
 * Decorating gin_lb.context_validator is the only way to answer the second
 * question without patching contrib or naming the form something misleading
 * like ..._layout_builder_form_.... The maintenance cost is that this class
 * implements a contrib interface and would break on a gin_lb major.
 *
 * Note this still does not fix everything: ThemeSuggestionsAlter carries its
 * own hardcoded $routesWithSuggestions array with neither an alter nor a
 * service seam, so *__gin_lb template suggestions stay out of reach for a
 * custom route.
 */
class GinLbContextValidator implements ContextValidatorInterface {

  /**
   * The decorated context validator.
   *
   * @var \Drupal\gin_lb\Service\ContextValidatorInterface
   */
  protected $inner;

  /**
   * Constructs a GinLbContextValidator.
   *
   * @param \Drupal\gin_lb\Service\ContextValidatorInterface $inner
   *   The decorated gin_lb context validator.
   */
  public function __construct(ContextValidatorInterface $inner) {
    $this->inner = $inner;
  }

  /**
   * {@inheritdoc}
   */
  public function isLayoutBuilderFormId(string $form_id, array $form): bool {
    if ($form_id === 'ys_views_wizard_spike_choose') {
      return $this->isValidTheme();
    }
    return $this->inner->isLayoutBuilderFormId($form_id, $form);
  }

  /**
   * {@inheritdoc}
   */
  public function isLayoutBuilderRoute(): bool {
    return $this->inner->isLayoutBuilderRoute();
  }

  /**
   * {@inheritdoc}
   */
  public function isValidTheme(): bool {
    return $this->inner->isValidTheme();
  }

}
