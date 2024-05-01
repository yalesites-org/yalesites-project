<?php

namespace Drupal\ys_templated_content\Modifiers;

use Drupal\Component\DependencyInjection\ContainerInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\path_alias\AliasRepositoryInterface;
use Drupal\taxonomy\TermStorageInterface;

/**
 * Modifies a content import for a unique insertion.
 */
class TemplateModifier extends TemplateModiferBase implements TemplateModifierInterface {
  /**
   * The term storage.
   *
   * @var \Drupal\taxonomy\TermStorageInterface
   */
  protected TermStorageInterface $termStorage;

  /**
   * TemplateModifier constructor.
   *
   * @param \Drupal\Component\Uuid\UuidInterface $uuidService
   *   The UUID service.
   * @param \Drupal\path_alias\AliasRepositoryInterface $pathAliasRepository
   *   The path alias repository.
   * @param \Drupal\Core\Entity\EntityTypeManager $entityTypeManager
   *   The entity type manager to get the taxonoomy term storage.
   */
  public function __construct(
    UuidInterface $uuidService,
    AliasRepositoryInterface $pathAliasRepository,
    EntityTypeManager $entityTypeManager,
  ) {
    parent::__construct($uuidService, $pathAliasRepository);
    $this->termStorage = $entityTypeManager->getStorage('taxonomy_term');
  }

  /**
   * {@inheritdoc}
   */
  public function create(ContainerInterface $container) {
    return new static(
      $container->get('uuid'),
      $container->get('path_alias.repository'),
      $container->get('entity_type.manager'),
    );
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
        $name = $value[0]['base_fields']['name'];
        $event_type = $value[0]['bundle'];
        $storedUuid = $this->uuidForEntityType($name, $event_type);
        if ($storedUuid) {
          $content_array[$key][0]['uuid'] = $storedUuid;
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

}
