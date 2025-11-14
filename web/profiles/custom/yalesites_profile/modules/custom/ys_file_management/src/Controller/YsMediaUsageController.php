<?php

namespace Drupal\ys_file_management\Controller;

use Drupal\block_content\BlockContentInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\entity_usage\Controller\LocalTaskUsageController;
use Drupal\ys_file_management\Service\LayoutBuilderUsageTracker;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\entity_usage\EntityUsageInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Pager\PagerManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Custom usage controller with Layout Builder support.
 *
 * Extends entity_usage's controller to properly resolve inline blocks
 * back to their parent nodes/entities.
 */
class YsMediaUsageController extends LocalTaskUsageController {

  /**
   * The Layout Builder usage tracker service.
   *
   * @var \Drupal\ys_file_management\Service\LayoutBuilderUsageTracker
   */
  protected $layoutBuilderTracker;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    EntityFieldManagerInterface $entity_field_manager,
    EntityUsageInterface $entity_usage,
    ConfigFactoryInterface $config_factory,
    PagerManagerInterface $pager_manager,
    LayoutBuilderUsageTracker $layout_builder_tracker,
  ) {
    parent::__construct(
      $entity_type_manager,
      $entity_field_manager,
      $entity_usage,
      $config_factory,
      $pager_manager
    );
    $this->layoutBuilderTracker = $layout_builder_tracker;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('entity_usage.usage'),
      $container->get('config.factory'),
      $container->get('pager.manager'),
      $container->get('ys_file_management.layout_builder_usage_tracker')
    );
  }

  /**
   * {@inheritdoc}
   *
   * Override to add Layout Builder support for block_content entities.
   */
  protected function getSourceEntityLink(EntityInterface $source_entity, $text = NULL): mixed {
    // Special handling for block_content entities that might be in
    // Layout Builder.
    if ($source_entity instanceof BlockContentInterface) {
      // Check if this is a non-reusable (inline) block.
      $is_reusable = $source_entity->isReusable();

      if (!$is_reusable) {
        // This is an inline block - try to find its parent via Layout Builder.
        $parents = $this->layoutBuilderTracker->findParentEntitiesForBlock($source_entity);

        if (!empty($parents)) {
          // Get the first parent entity.
          $parent = reset($parents);

          // Get the block's location within the parent if possible.
          $location = $this->layoutBuilderTracker->getBlockLocation($parent, $source_entity->uuid());

          // Build link text that includes the parent and location.
          $parent_label = $parent->access('view label') ? $parent->label() : $this->t('- Restricted access -');

          if ($location) {
            $link_text = $this->t('@parent (in @location)', [
              '@parent' => $parent_label,
              '@location' => $location,
            ]);
          }
          else {
            $link_text = $parent_label;
          }

          // Recursively get the link for the parent entity.
          // This ensures we follow the same logic for the parent
          // (e.g., edit form, canonical, etc.).
          return $this->getSourceEntityLink($parent, $link_text);
        }
      }
      // If it's a reusable block or we couldn't find a parent,
      // fall through to parent implementation.
    }

    // For all other cases (including reusable blocks that weren't found
    // in Layout Builder), use the parent implementation.
    return parent::getSourceEntityLink($source_entity, $text);
  }

}
