<?php

namespace Drupal\ys_templated_content\Modifiers;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\path_alias\AliasRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Modifies a content import for a unique insertion.
 */
class TemplateModifier {

  const PLACEHOLDER = 'public://templated-content-images/placeholder.png';

  /**
   * The path alias repository.
   *
   * @var \Drupal\path_alias\AliasRepositoryInterface
   */
  protected $pathAliasRepository;

  /**
   * The UUID service.
   *
   * @var \Drupal\Core\Uuid\UuidInterface
   */
  protected $uuidService;

  /**
   * TemplateModifier constructor.
   *
   * @param \Drupal\Component\Uuid\UuidInterface $uuidService
   *   The UUID service.
   * @param \Drupal\path_alias\AliasRepositoryInterface $pathAliasRepository
   *   The path alias repository.
   */
  public function __construct(
    UuidInterface $uuidService,
    AliasRepositoryInterface $pathAliasRepository,
  ) {
    $this->uuidService = $uuidService;
    $this->pathAliasRepository = $pathAliasRepository;
  }

  /**
   * {@inheritdoc}
   */
  public function create(ContainerInterface $container) {
    return new static(
      $container->get('uuid'),
      $container->get('path_alias.repository'),
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
    $content_array['base_fields']['created'] = $this->getUnixTimestamp();
    $content_array = $this->replaceBrokenImages($content_array);
    $content_array = $this->generateAlias($content_array);
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
   * Generate a unique alias using the current date/time.
   *
   * @param string $alias
   *   The alias.
   *
   * @return string
   *   The unique alias (original alias with date/time).
   */
  protected function generateUniqueAliasWithDate($alias) {
    $date = date('Y-m-d-H-i-s');
    $alias .= '-' . $date;

    return $alias;
  }

  /**
   * Generate a unique alias with a sequential number.
   *
   * @param string $alias
   *   The alias.
   *
   * @return string
   *   The unique alias (original alias with sequential number).
   */
  protected function generateUniqueAliasWithSequentialNumber($alias) {
    $aliasNumber = 1;
    $newAlias = $alias . '-' . $aliasNumber;

    while ($this->pathAliasRepository->lookupByAlias($newAlias, 'en')) {
      $aliasNumber++;
      $newAlias = $alias . '-' . $aliasNumber;
    }

    return $newAlias;
  }

  /**
   * Generate a unique alias.
   *
   * @param string $alias
   *   The alias.
   *
   * @return string
   *   The unique alias.
   */
  protected function generateUniqueAlias($alias) {
    return $this->generateUniqueAliasWithSequentialNumber($alias);
    /* return $this->generateUniqueAliasWithDate($alias); */
  }

  /**
   * Generate a unique alias if the alias already exists.
   *
   * @param array $content_array
   *   The content array.
   *
   * @return array
   *   The content array with a unique alias.
   */
  protected function generateAlias($content_array) {
    if (isset($content_array['base_fields']['url'])) {
      $alias = $content_array['base_fields']['url'];

      if ($this->pathAliasRepository->lookupByAlias($alias, 'en')) {
        $content_array['base_fields']['url'] = $this->generateUniqueAlias($alias);
      }
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
   * Get the current unix timestamp.
   *
   * @return int
   *   The current unix timestamp.
   */
  public function getUnixTimestamp() {
    return time();
  }

}
