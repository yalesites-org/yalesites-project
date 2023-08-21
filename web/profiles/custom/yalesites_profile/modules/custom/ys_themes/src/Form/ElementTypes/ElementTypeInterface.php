<?php

namespace Drupal\ys_themes\Form\ElementTypes;

/**
 * Interface for element types.
 */
interface ElementTypeInterface {

  /**
   * Generates the form element definition.
   *
   * @return array
   *   Form element definition.
   */
  public function toElementDefinition();

}
