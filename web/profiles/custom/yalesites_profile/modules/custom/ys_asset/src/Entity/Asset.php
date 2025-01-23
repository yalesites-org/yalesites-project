<?php

namespace Drupal\ys_asset\Entity;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\user\UserInterface;
use Drupal\ys_asset\AssetInterface;
use Drupal\Core\Url;

/**
 * Defines the Asset entity.
 *
 * @ingroup ys_asset
 *
 * This is the main definition of the entity type. From it, an entityType is
 * derived. The most important properties in this example are listed below.
 *
 * id: The unique identifier of this entityType. It follows the pattern
 * 'moduleName_xyz' to avoid naming conflicts.
 *
 * label: Human readable name of the entity type.
 *
 * handlers: Handler classes are used for different tasks. You can use
 * standard handlers provided by D8 or build your own, most probably derived
 * from the standard class. In detail:
 *
 * - view_builder: we use the standard controller to view an instance. It is
 *   called when a route lists an '_entity_view' default for the entityType
 *   (see routing.yml for details. The view can be manipulated by using the
 *   standard drupal tools in the settings.
 *
 * - list_builder: We derive our own list builder class from the
 *   entityListBuilder to control the presentation.
 *   If there is a view available for this entity from the views module, it
 *   overrides the list builder. @todo: any view? naming convention?
 *
 * - form: We derive our own forms to add functionality like additional fields,
 *   redirects etc. These forms are called when the routing list an
 *   '_entity_form' default for the entityType. Depending on the suffix
 *   (.add/.edit/.delete) in the route, the correct form is called.
 *
 * - access: Our own accessController where we determine access rights based on
 *   permissions.
 *
 * More properties:
 *
 *  - base_table: Define the name of the table used to store the data. Make sure
 *    it is unique. The schema is automatically determined from the
 *    BaseFieldDefinitions below. The table is automatically created during
 *    installation.
 *
 *  - entity_keys: How to access the fields. Analog to 'nid' or 'uid'.
 *
 *  - links: Provide links to do standard tasks. The 'edit-form' and
 *    'delete-form' links are added to the list built by the
 *    entityListController. They will show up as action buttons in an additional
 *    column.
 *
 * There are many more properties to be used in an entity type definition. For
 * a complete overview, please refer to the '\Drupal\Core\Entity\EntityType'
 * class definition.
 *
 * The following construct is the actual definition of the entity type which
 * is read and cached. Don't forget to clear cache after changes.
 *
 * @ContentEntityType(
 *   id = "ys_asset",
 *   label = @Translation("Asset entity"),
 *   handlers = {
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\ys_asset\AssetListBuilder",
 *     "views_data" = "Drupal\views\EntityViewsData",
 *     "form" = {
 *       "add" = "Drupal\ys_asset\Form\AssetForm",
 *       "edit" = "Drupal\ys_asset\Form\AssetForm",
 *       "delete" = "Drupal\ys_asset\Form\AssetDeleteForm",
 *     },
 *     "access" = "Drupal\ys_asset\YsAssetAccessControlHandler",
 *   },
 *   base_table = "asset",
 *   admin_permission = "administer asset entity",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "label",
 *     "langcode" = "langcode"
 *   },
 *   links = {
 *     "canonical" = "/ys_asset/{ys_asset}",
 *     "edit-form" = "/ys_asset/{ys_asset}/edit",
 *     "delete-form" = "/ys_asset/{ys_asset}/delete",
 *     "collection" = "/ys_asset/list"
 *   },
 *   field_ui_base_route = "ys_asset.asset_settings",
 * )
 *
 * The 'links' above are defined by their path. For core to find the corresponding
 * route, the route name must follow the correct pattern:
 *
 * entity.<entity-name>.<link-name> (replace dashes with underscores)
 * Example: 'entity.ys_asset_contact.canonical'
 *
 * See routing file above for the corresponding implementation
 *
 * The 'Contact' class defines methods and fields for the contact entity.
 *
 * Being derived from the ContentEntityBase class, we can override the methods
 * we want. In our case we want to provide access to the standard fields about
 * creation and changed time stamps.
 *
 * Our interface (see ContactInterface) also exposes the EntityOwnerInterface.
 * This allows us to provide methods for setting and providing ownership
 * information.
 *
 * The most important part is the definitions of the field properties for this
 * entity type. These are of the same type as fields added through the GUI, but
 * they can be changed in code. In the definition we can define if the user with
 * the rights privileges can influence the presentation (view, edit) of each
 * field.
 */
class Asset extends ContentEntityBase implements AssetInterface {

  // Implements methods defined by EntityChangedInterface.
  use EntityChangedTrait;

  /**
   * {@inheritdoc}
   *
   * When a new entity instance is added, set the user_id entity reference to
   * the current user as the creator of the instance.
   */
  public static function preCreate(EntityStorageInterface $storage_controller, array &$values) {
    parent::preCreate($storage_controller, $values);
    $values += [
      'user_id' => \Drupal::currentUser()->id(),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getCreatedTime() {
    return $this->get('created')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getOwner() {
    return $this->get('user_id')->entity;
  }

  /**
   * {@inheritdoc}
   */
  public function getOwnerId() {
    return $this->get('user_id')->target_id;
  }

  /**
   * {@inheritdoc}
   */
  public function setOwnerId($uid) {
    $this->set('user_id', $uid);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setOwner(UserInterface $account) {
    $this->set('user_id', $account->id());
    return $this;
  }

  /**
   * Retrieves the entity title.
   */
  public function getEntityLabel() {
    $entity_id = $this->get('entity_id');
    if ($entity_id) {
      $entity = $this->get('entity_id')->entity;
      return $entity->label();
    }

    return '';
  }

  /**
   * Retrieves the source URL.
   */
  public function getSourceUrl() {
    $url = $this->get('source_url')->value;
    if (empty($url)) {
      $entity_id = $this->get('entity_id');
      if ($entity_id) {
        $entity = $this->get('entity_id')->entity;
        $url = $entity->toUrl('canonical', ['absolute' => TRUE])->toString();
      }
      else {
        $url = 'internal:/';
      }
    }

    $urlObject = Url::fromUri($url, ['absolute' => TRUE]);

    return [
      '#type' => 'link',
      '#title' => $urlObject->toString(),
      '#url' => $urlObject,
    ];
  }

  /**
   * {@inheritdoc}
   *
   * Define the field properties here.
   *
   * Field name, type and size determine the table structure.
   *
   * In addition, we can define how the field and its content can be manipulated
   * in the GUI. The behaviour of the widgets used can be determined here.
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = [];

    // Standard field, used as unique if primary index.
    $fields['id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('ID'))
      ->setDescription(t('The ID of the Contact entity.'))
      ->setReadOnly(TRUE);

    // Standard field, unique outside of the scope of the current project.
    $fields['uuid'] = BaseFieldDefinition::create('uuid')
      ->setLabel(t('UUID'))
      ->setDescription(t('The UUID of the Contact entity.'))
      ->setReadOnly(TRUE);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Name'))
      ->setDescription(t('The name of the Asset entity.'))
      ->setSettings([
        'default_value' => '',
        'max_length' => 255,
        'text_processing' => 0,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => -6,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -6,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // ListTextType with a drop down menu widget.
    // In the view the field content is shown as a string.
    // In the form the choices are presented as an options list.
    $fields['source'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Source'))
      ->setDescription(t('The source this data came from.'))
      ->setSettings([
        'allowed_values' => [
          'drupal' => t('Drupal'),
          'api' => t('API'),
          'website' => t('Website'),
        ],
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'list_default',
        'weight' => -4,
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => -4,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Owner field of the contact.
    // Entity reference field, holds the reference to the user object.
    // The view shows the user name field of the user.
    // The form presents a auto complete field for the user name.
    $fields['entity_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Entity'))
      ->setDescription(t('The entity to link to.'))
      ->setSetting('target_type', 'node')
      ->setSetting('handler', 'default')
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => -3,
      ])
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => 60,
          'autocomplete_type' => 'tags',
          'placeholder' => '',
        ],
        'weight' => -3,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['source_url'] = BaseFieldDefinition::create('uri')
      ->setLabel(t('Source URL'))
      ->setDescription(t('The URL of the source.'))
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'uri_link',
        'weight' => -2,
      ])
      ->setDisplayOptions('form', [
        'type' => 'uri',
        'weight' => -2,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['original_content'] = BaseFieldDefinition::create('text_long')
      ->setLabel(t('Original content'))
      ->setDescription(t('The original content of the asset.'))
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'text_default',
        'weight' => -3,
      ])
      ->setDisplayOptions('form', [
        'type' => 'text_textarea',
        'weight' => -3,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['synthesized_content'] = BaseFieldDefinition::create('text_long')
      ->setLabel(t('Synthesized content'))
      ->setDescription(t('The synthesized content of the asset.'))
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'text_default',
        'weight' => -3,
      ])
      ->setDisplayOptions('form', [
        'type' => 'text_textarea',
        'weight' => -3,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['langcode'] = BaseFieldDefinition::create('language')
      ->setLabel(t('Language code'))
      ->setDescription(t('The language code of Asset entity.'));
    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDescription(t('The time that the entity was created.'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('The time that the entity was last edited.'));

    $fields['user_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Created by'))
      ->setDescription(t('The user that created the entity.'))
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default')
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'author',
        'weight' => -2,
      ])
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => 60,
          'autocomplete_type' => 'tags',
          'placeholder' => '',
        ],
        'weight' => -2,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    return $fields;
  }

}
