<?php

namespace Drupal\ys_templated_content\Modifiers;

/**
 * Interface to modify content arrays for template processing.
 */
interface TemplateModifierInterface {

  /**
   * Process the content array.
   *
   * @param array $content_array
   *   The content array.
   */
  public function process($content_array);

}
