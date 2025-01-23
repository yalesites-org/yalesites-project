<?php

namespace Drupal\ys_asset;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Url;

/**
 * Provides a list controller for ys_asset_contact entity.
 *
 * @ingroup ys_asset
 */
class AssetListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   *
   * We override ::render() so that we can add our own content above the table.
   * parent::render() is where EntityListBuilder creates the table using our
   * buildHeader() and buildRow() implementations.
   */
  public function render() {
    $build['description'] = [
      '#markup' => $this->t('Asset Content Entity implements an Assets model. These assets are fieldable entities. You can manage the fields on the <a href="@adminlink">Assets admin page</a>.', [
        '@adminlink' => Url::fromRoute('ys_asset.asset_settings', [], ['absolute' => 'true'])->toString(),
      ]),
    ];

    $build += parent::render();
    return $build;
  }

  /**
   * {@inheritdoc}
   *
   * Building the header and content lines for the contact list.
   *
   * Calling the parent::buildHeader() adds a column for the possible actions
   * and inserts the 'edit' and 'delete' links as defined for the entity type.
   */
  public function buildHeader() {
    $header['id'] = $this->t('AssetID');
    $header['name'] = $this->t('Name');
    $header['source'] = $this->t('Source');
    $header['linked_entity'] = $this->t('Linked Entity');
    $header['URL'] = $this->t('URL');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    /** @var \Drupal\ys_asset\Entity\Contact $entity */
    $row['id'] = $entity->id();
    $row['name'] = $entity->label();
    $row['source'] = $entity->source->value;
    $row['linked_entity'] = $entity->getEntityLabel();
    $row['url']['data'] = $entity->getSourceUrl();

    return $row + parent::buildRow($entity);
  }

}
