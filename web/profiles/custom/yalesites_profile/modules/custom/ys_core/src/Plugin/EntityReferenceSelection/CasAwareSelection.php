<?php

namespace Drupal\ys_core\Plugin\EntityReferenceSelection;

use Drupal\Core\Entity\Plugin\EntityReferenceSelection\DefaultSelection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;

/**
 * Custom entity reference selection handler for CAS indicator and content type.
 *
 * @EntityReferenceSelection(
 *   id = "cas_aware",
 *   label = @Translation("CAS Aware Selection"),
 *   entity_types = {"node"},
 *   group = "cas_aware",
 *   weight = 1
 * )
 */
class CasAwareSelection extends DefaultSelection {

  /**
   * {@inheritdoc}
   */
  public function getReferenceableEntities($match = NULL, $match_operator = 'CONTAINS', $limit = 0): array {
    $entities = parent::getReferenceableEntities($match, $match_operator, $limit);

    foreach ($entities as &$bundle_entities) {
      foreach ($bundle_entities as $entity_id => &$label) {
        $entity = $this->loadEntity($entity_id);
        if ($entity) {
          $label = $this->enhanceEntityLabel($label, $entity);
        }
      }
    }

    return $entities;
  }

  /**
   * Loads an entity by ID.
   *
   * @param string|int $entity_id
   *   The entity ID.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The loaded entity or NULL if not found.
   */
  protected function loadEntity($entity_id): ?EntityInterface {
    return $this->entityTypeManager
      ->getStorage($this->getConfiguration()['target_type'])
      ->load($entity_id);
  }

  /**
   * Enhances the entity label with additional information.
   *
   * @param string $original_label
   *   The original entity label.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity object.
   *
   * @return string
   *   The enhanced label.
   */
  protected function enhanceEntityLabel(string $original_label, EntityInterface $entity): string {
    $enhancements = $this->buildEntityEnhancements($entity);
    return $this->formatLabelWithAdditions($original_label, $enhancements);
  }

  /**
   * Builds the list of enhancements for an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity object.
   *
   * @return array
   *   Array of enhancement strings.
   */
  protected function buildEntityEnhancements(EntityInterface $entity): array {
    $enhancements = [];

    $enhancements[] = $this->buildContentTypeEnhancement($entity);
    $enhancements[] = $this->buildSecurityEnhancement($entity);

    // Filter out empty enhancements.
    return array_filter($enhancements);
  }

  /**
   * Builds the content type enhancement.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity object.
   *
   * @return string|null
   *   The content type enhancement.
   */
  protected function buildContentTypeEnhancement(EntityInterface $entity): ?string {
    return $this->getContentTypeLabel($entity);
  }

  /**
   * Builds the security enhancement.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity object.
   *
   * @return string|null
   *   The security enhancement.
   */
  protected function buildSecurityEnhancement(EntityInterface $entity): ?string {
    return $this->requiresLogin($entity) ? 'CAS' : NULL;
  }

  /**
   * Gets the human-readable content type label for an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity object.
   *
   * @return string|null
   *   The content type label or NULL if not available.
   */
  protected function getContentTypeLabel(EntityInterface $entity): ?string {
    $bundle_info = $this->entityTypeBundleInfo->getBundleInfo($entity->getEntityTypeId());
    return $bundle_info[$entity->bundle()]['label'] ?? $entity->bundle();
  }

  /**
   * Checks if an entity requires login (CAS authentication).
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity object.
   *
   * @return bool
   *   TRUE if the entity requires login, FALSE otherwise.
   */
  protected function requiresLogin(EntityInterface $entity): bool {
    // Ensure the entity is fieldable before checking for fields.
    if (!$entity instanceof FieldableEntityInterface) {
      return FALSE;
    }

    if (!$entity->hasField('field_login_required')) {
      return FALSE;
    }

    $login_required = $entity->get('field_login_required')->value;

    // Handle different field types (boolean, checkbox, etc.).
    return !empty($login_required);
  }

  /**
   * Formats the label with additional information.
   *
   * @param string $original_label
   *   The original label.
   * @param array $additions
   *   Array of additional information to append.
   *
   * @return string
   *   The formatted label.
   */
  protected function formatLabelWithAdditions(string $original_label, array $additions): string {
    if (empty($additions)) {
      return $original_label;
    }

    // Format: "Original Label (Addition1) (Addition2)".
    return $original_label . ' (' . implode(') (', $additions) . ')';
  }

}
