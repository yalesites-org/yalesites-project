<?php

namespace Drupal\ys_templated_content\Modifiers;

use Drupal\Component\DependencyInjection\ContainerInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\path_alias\AliasRepositoryInterface;

/**
 * Generate a template modifier based on the filename.
 */
class TemplateModifierFactory {
  /**
   * The term storage.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  protected EntityTypeManager $entityTypeManager;

  /**
   * The path alias repository.
   *
   * @var \Drupal\path_alias\AliasRepositoryInterface
   */
  protected $pathAliasRepository;

  /**
   * The UUID service.
   *
   * @var \Drupal\Component\Uuid\UuidInterface
   */
  protected $uuidService;

  /**
   * TemplateModifier constructor.
   *
   * @param \Drupal\Component\Uuid\UuidInterface $uuidService
   *   The UUID service.
   * @param \Drupal\path_alias\AliasRepositoryInterface $pathAliasRepository
   *   The path alias repository.
   * @param \Drupal\taxonomy\Entity\EntityTypeManager $entityTypeManager
   *   The entity type manager to get the taxonoomy term storage.
   */
  public function __construct(
    UuidInterface $uuidService,
    AliasRepositoryInterface $pathAliasRepository,
    EntityTypeManager $entityTypeManager,
  ) {
    $this->uuidService = $uuidService;
    $this->pathAliasRepository = $pathAliasRepository;
    $this->entityTypeManager = $entityTypeManager;
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

  public function getTemplateModifier($extension) {
    switch ($extension) {
      case 'yml':
        return new YamlTemplateModifier($this->uuidService, $this->pathAliasRepository, $this->entityTypeManager);
        break;
      case 'zip':
        return new TemplateModifier($this->uuidService, $this->pathAliasRepository, $this->entityTypeManager);
        break;
      default:
      return new TemplateModifier($this->uuidService, $this->pathAliasRepository, $this->entityTypeManager);
    }
  }
}
