<?php

namespace Drupal\ys_templated_content\Plugin\TemplateModifier;

use Drupal\taxonomy\TermStorageInterface;
use Drupal\ys_templated_content\TemplateModifierBase;
use Drupal\ys_templated_content\TemplateModifierInterface;

/**
 * Provides a Zip template modifier.
 *
 * @TemplateModifier(
 *   id = "zip_template_modifier",
 *   label = @Translation("Zip Template Modifier"),
 *   description = @Translation("Modifier for Zip templates."),
 *   extension = "zip",
 * )
 */
class ZipTemplateModifier extends TemplateModifierBase implements TemplateModifierInterface {
  /**
   * The term storage.
   *
   * @var \Drupal\taxonomy\TermStorageInterface
   */
  protected TermStorageInterface $termStorage;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->termStorage = $configuration['container']->get('entity_type.manager')->getStorage('taxonomy_term');
  }

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
    $content_array = $this->removeAlias($content_array);
    $content_array = $this->replaceUuids($content_array, $originalUuid, $newUuid);
    $content_array = $this->reuseTaxonomyTerms($content_array);
    $content_array = $this->makeUnpublished($content_array);

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

  /**
   * Reuse taxonomy terms.
   *
   * @param array $content_array
   *   The content array.
   *
   * @return array
   *   The content array with reused taxonomy terms.
   */
  public function reuseTaxonomyTerms($content_array) {
    foreach ($content_array as $key => $value) {
      if (
        is_array($value)
          && isset($value[0]['entity_type'])
          && $value[0]['entity_type'] === 'taxonomy_term'
      ) {
        foreach ($value as $item) {
          if (!isset($item['base_fields']['name'])) {
            continue;
          }

          $name = $item['base_fields']['name'];
          $event_type = $item['bundle'];
          $storedUuid = $this->uuidForEntityType($name, $event_type);
          if ($storedUuid) {
            $item['uuid'] = $storedUuid;
          }
        }
      }
      elseif (is_array($value)) {
        $content_array[$key] = $this->reuseTaxonomyTerms($value);
      }
    }
    return $content_array;
  }

  /**
   * Find the UUID for a given entity type name.
   *
   * @param string $name
   *   The name of the event type.
   * @param string $bundle
   *   The bundle name.
   *
   * @return string
   *   The UUID for the taxnonomy name or NULL (create a new one).
   */
  protected function uuidForEntityType($name, $bundle) {
    $tid = $this->termStorage->loadByProperties(['vid' => $bundle, 'name' => $name]);

    if (empty($tid)) {
      return NULL;
    }

    return $tid[key($tid)]->uuid();
  }

  /**
   * Make the content unpublished.
   *
   * @param array $content_array
   *   The content array.
   */
  protected function makeUnpublished($content_array) {
    $content_array['base_fields']['status'] = 0;

    return $content_array;
  }

}
