<?php

namespace Drupal\ys_templated_content\Modifiers;

/**
 * Modifies a content import for a unique insertion.
 */
class TemplateModifier extends TemplateModiferBase implements TemplateModifierInterface {

  const PLACEHOLDER = 'public://templated-content-images/placeholder.png';

  const UUID_IGNORE_LIST = [
    'field_event_type' => 'findUuidForEventTypeName',
  ];

  /**
   * Process the content array.
   *
   * @param array $content_array
   *   The content array.
   */
  public function process($content_array) {
    $originalUuid = $content_array['uuid'];
    $newUuid = $this->uuidService->generate();
    $content_array['base_fields']['created'] = time();
    $content_array = $this->replaceBrokenImages($content_array);
    $content_array = $this->removeAlias($content_array);
    $content_array = $this->replaceUuids($content_array, $originalUuid, $newUuid);

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

  /**
   * Replace the UUIDs in the content array.
   *
   * Any reference to the main UUID should be replaced so that we reference
   * the newly created one.
   *
   * @param array $content
   *   The content array.
   * @param string $uuid
   *   The UUID to replace.
   * @param string $newUuid
   *   The new UUID.
   *
   * @return array
   *   The content array with the UUIDs replaced.
   */
  public function replaceUuids($content, $uuid, $newUuid) {
    if (array_key_exists('uuid', $content) && $content['uuid'] === $uuid) {
      $content['uuid'] = $newUuid;
    }
    // Find any other element that has the original UUID passed and replace it
    // with the new one.
    foreach ($content as $key => $value) {
      if (is_array($value)) {
        $content[$key] = $this->replaceUuids($value, $uuid, $newUuid);
      }
      elseif (is_string($value) && strpos($value, 'entity:node/') !== FALSE) {
        $content[$key] = str_replace('entity:node/' . $uuid, 'entity:node/' . $newUuid, $value);
      }
      elseif (is_string($value) && strpos($value, $uuid) !== FALSE) {
        $content[$key] = str_replace($uuid, $newUuid, $value);
      }
    }

    return $content;
  }

  /**
   * Remove the alias so Drupal can generate one.
   *
   * @param array $content_array
   *   The content array.
   *
   * @return array
   *   The content array without an alias.
   */
  protected function removeAlias($content_array) {
    if (isset($content_array['base_fields']['url'])) {
      $content_array['base_fields']['url'] = '';
    }

    return $content_array;
  }

  /**
   * Generate a UUID.
   *
   * @return string
   *   The UUID.
   */
  public function generateUuid() {
    return $this->uuidService->generate();
  }

  protected function UuidForEventTypeName($name) {
    // Find all possible values for field_event_type taxonomy_term
    $query = \Drupal::entityQuery('taxonomy_term');
    $query->condition('vid', 'event_type')
      ->accessCheck(TRUE);
    $tids = $query->execute();

    // Return the UUID for the tid that has the same name.
    foreach ($tids as $tid) {
      $term = \Drupal\taxonomy\Entity\Term::load($tid);
      if ($term->getName() == $name) {
        return $term->uuid();
      }
    }
  }

}
