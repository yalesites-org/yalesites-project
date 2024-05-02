<?php

namespace Drupal\ys_templated_content\Modifiers;

/**
 * Modifies a content import for a unique insertion for YAML files.
 *
 * In particular, this adds one more item assuming that if the image is not
 * already present on the system, it cannot be included since this is a yaml
 * file, so it uses the placeholder image.
 */
class YamlTemplateModifier extends TemplateModifier implements TemplateModifierInterface {
  const PLACEHOLDER = 'public://templated-content-images/placeholder.png';

  /**
   * Process the content array.
   *
   * @param array $content_array
   *   The content array.
   */
  public function process($content_array) {
    parent::process($content_array);
    $content_array = $this->replaceBrokenImages($content_array);

    return $content_array;
  }

  /**
   * Replace broken images with a placeholder.
   *
   * @param array $content_array
   *   The content array.
   *
   * @return array
   *   The content array with images fixed with placeholder.
   */
  protected function replaceBrokenImages(array $content_array) : array {
    foreach ($content_array as $key => $value) {
      if (is_array($value)) {
        $content_array[$key] = $this->replaceBrokenImages($value);
      }
      elseif ($key == 'uri' && strpos($value, 'public://') !== FALSE) {
        $path = $value;
        $path = str_replace('public://', 'sites/default/files/', $path);
        if (!file_exists($path)) {
          $content_array[$key] = $this::PLACEHOLDER;
        }
      }
    }

    return $content_array;
  }

}
