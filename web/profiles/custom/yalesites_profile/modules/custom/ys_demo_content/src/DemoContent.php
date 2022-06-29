<?php

namespace Drupal\ys_demo_content;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\Core\State\StateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Component\Utility\Html;
use Drupal\paragraphs\Entity\Paragraph;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Url;

/**
 * Defines a helper class for importing default content.
 *
 * @internal
 *   This code is only for use by the YaleSites Demo Content module.
 */
class DemoContent implements ContainerInjectionInterface {

  /**
   * The path alias manager.
   *
   * @var \Drupal\Core\Path\AliasManagerInterface
   */
  protected $aliasManager;

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * State.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * Constructs a new DemoContent object.
   *
   * @param \Drupal\Core\Path\AliasManagerInterface $aliasManager
   *   The path alias manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Entity type manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   Module handler.
   * @param \Drupal\Core\State\StateInterface $state
   *   State service.
   */
  public function __construct(AliasManagerInterface $aliasManager, EntityTypeManagerInterface $entityTypeManager, ModuleHandlerInterface $moduleHandler, StateInterface $state) {
    $this->aliasManager = $aliasManager;
    $this->entityTypeManager = $entityTypeManager;
    $this->moduleHandler = $moduleHandler;
    $this->state = $state;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('path_alias.manager'),
      $container->get('entity_type.manager'),
      $container->get('module_handler'),
      $container->get('state')
    );
  }

  /**
   * Imports default contents.
   */
  public function importContent() {
    $this->importPages();
  }

  /**
   * Imports pages.
   *
   * @return $this
   */
  protected function importPages() {
    
    $importData = $this->get_yml_data();
    $uuids = [];
    foreach ($importData as $key => $data) {

      // Page Title
      $values = [
        'type' => 'page',
        'title' => $data['title'],
      ];

      // Slug
      if (!empty($data['slug'])) {
        $values['path'] = [['alias' => '/' . $data['slug']]];
      }
      
      // Main Paragraphs

      if (!empty($data['field_content'])) {
        
        $paragraph1 = Paragraph::create([
          'type' => 'text',
          'field_text' => [
            'value' => 'paragraph1',
            'format' => 'basic_html'
          ],
        ]);
        $paragraph1->save();

        $paragraph2 = Paragraph::create([
          'type' => 'text',
          'field_text' => [
            'value' => 'paragraph2',
            'format' => 'basic_html'
          ],
        ]);
        $paragraph2->save();
          
        $values['field_content'] = array(
          array(
            'target_id' => $paragraph1->id(),
            'target_revision_id' => $paragraph1->getRevisionId(),
          ),
          array(
            'target_id' => $paragraph2->id(),
            'target_revision_id' => $paragraph2->getRevisionId(),
          ),
        );

      }

      $node = $this->entityTypeManager->getStorage('node')->create($values);
      $node->save();
      $uuids[$node->uuid()] = 'node';
    }
    $this->storeCreatedContentUuids($uuids);




    // if (($handle = fopen($this->moduleHandler->getModule('ys_demo_content')->getPath() . '/default_content/pages.csv', "r")) !== FALSE) {
    //   $headers = fgetcsv($handle);
    //   $uuids = [];
    //   while (($data = fgetcsv($handle)) !== FALSE) {
    //     $data = array_combine($headers, $data);

    //     // Prepare content.
    //     $values = [
    //       'type' => 'page',
    //       'title' => $data['title'],
    //     ];
    //     // Fields mapping starts.
    //     // Set Body Field.
    //     // if (!empty($data['body'])) {
    //     //   $values['body'] = [['value' => $data['body'], 'format' => 'basic_html']];
    //     // }
        
    //     $paragraph = Paragraph::create([
    //       'type' => 'text',
    //       'field_text' => [
    //         'value' => $data['body'],
    //         'format' => 'basic_html'
    //       ],
    //     ]);
    //     $paragraph->save();

    //     $values['field_content'] = [
    //       'target_id' => $paragraph->id(),
    //       'target_revision_id' => $paragraph->getRevisionId()
    //     ];

    //     // Set node alias if exists.
    //     if (!empty($data['slug'])) {
    //       $values['path'] = [['alias' => '/' . $data['slug']]];
    //     }
    //     // Set article author.
    //     if (!empty($data['author'])) {
    //       $values['uid'] = $this->getUser($data['author']);
    //     }

    //     // Create Node.
    //     $node = $this->entityTypeManager->getStorage('node')->create($values);
    //     $node->save();
    //     $uuids[$node->uuid()] = 'node';
    //   }
    //   $this->storeCreatedContentUuids($uuids);
    //   fclose($handle);
    // }
    return $this;
  }

  public function get_yml_data() {
    $ymlFile = $this->moduleHandler->getModule('ys_demo_content')->getPath() . '/default_content/pages.yml';
    $ymlData = \Symfony\Component\Yaml\Yaml::parse(file_get_contents($ymlFile));
    return $ymlData['pages'];
    // echo "<pre>";
    // print_r($ymlData['pages']);
    // echo "</pre>";
  }

  /**
   * Deletes any content imported by this module.
   *
   * @return $this
   */
  public function deleteImportedContent() {
    $uuids = $this->state->get('ys_demo_content_uuids', []);
    $by_entity_type = array_reduce(array_keys($uuids), function ($carry, $uuid) use ($uuids) {
      $entity_type_id = $uuids[$uuid];
      $carry[$entity_type_id][] = $uuid;
      return $carry;
    }, []);
    foreach ($by_entity_type as $entity_type_id => $entity_uuids) {
      $storage = $this->entityTypeManager->getStorage($entity_type_id);
      $entities = $storage->loadByProperties(['uuid' => $entity_uuids]);
      $storage->delete($entities);
    }
    return $this;
  }

/**
   * Looks up a user by name, if it is missing the user is created.
   *
   * @param string $name
   *   Username.
   *
   * @return int
   *   User ID.
   */
  protected function getUser($name) {
    $user_storage = $this->entityTypeManager->getStorage('user');
    $users = $user_storage->loadByProperties(['name' => $name]);;
    if (empty($users)) {
      // Creating user without any email/password.
      $user = $user_storage->create([
        'name' => $name,
        'status' => 1,
      ]);
      $user->enforceIsNew();
      $user->save();
      $this->storeCreatedContentUuids([$user->uuid() => 'user']);
      return $user->id();
    }
    $user = reset($users);
    return $user->id();
  }

  /**
   * Creates a file entity based on an image path.
   *
   * @param string $path
   *   Image path.
   *
   * @return int
   *   File ID.
   */
  protected function createFileEntity($path) {
    $uri = $this->fileUnmanagedCopy($path);
    $file = $this->entityTypeManager->getStorage('file')->create([
      'uri' => $uri,
      'status' => 1,
    ]);
    $file->save();
    $this->storeCreatedContentUuids([$file->uuid() => 'file']);
    return $file->id();
  }

  public function ManuallyGenerateDemoContent() {
    $this->importContent();
    \Drupal::messenger()->addStatus("Created demo content.");
    $url = Url::fromRoute('ys_demo_content.settings');
    return new RedirectResponse($url->toString());
  }

  /**
   * Stores record of content entities created by this import.
   *
   * @param array $uuids
   *   Array of UUIDs where the key is the UUID and the value is the entity
   *   type.
   */
  protected function storeCreatedContentUuids(array $uuids) {
    $uuids = $this->state->get('ys_demo_content_uuids', []) + $uuids;
    $this->state->set('ys_demo_content_uuids', $uuids);
  }

  /**
   * Wrapper around file_unmanaged_copy().
   *
   * @param string $path
   *   Path to image.
   *
   * @return string|false
   *   The path to the new file, or FALSE in the event of an error.
   */
  protected function fileUnmanagedCopy($path) {
    $filename = basename($path);
    return file_unmanaged_copy($path, 'public://' . $filename, FILE_EXISTS_REPLACE);
  }

}
