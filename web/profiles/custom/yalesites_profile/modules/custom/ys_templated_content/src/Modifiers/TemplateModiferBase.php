<?php

namespace Drupal\ys_templated_content\Modifiers;

use Drupal\Component\DependencyInjection\ContainerInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\path_alias\AliasRepositoryInterface;

/**
 * @file
 * Contains Drupal\ys_templated_content\Modifiers\TemplateModiferBase.
 */

/**
 * Modifies a content import for a unique insertion.
 */
class TemplateModiferBase implements TemplateModifierInterface {
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
    return $content_array;
  }

}
