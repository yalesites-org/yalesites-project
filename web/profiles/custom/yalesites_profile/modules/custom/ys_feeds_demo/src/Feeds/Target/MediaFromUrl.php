<?php

namespace Drupal\ys_feeds_demo\Feeds\Target;

use Drupal\Component\Utility\Bytes;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Utility\Token;
use Drupal\feeds\Exception\EmptyFeedException;
use Drupal\feeds\Exception\TargetValidationException;
use Drupal\feeds\FeedTypeInterface;
use Drupal\feeds\FieldTargetDefinition;
use Drupal\feeds\Plugin\Type\Processor\EntityProcessorInterface;
use Drupal\feeds\Plugin\Type\Target\ConfigurableTargetInterface;
use Drupal\feeds\Plugin\Type\Target\FieldTargetBase;
use Drupal\file\FileRepositoryInterface;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Maps a remote file URL onto an entity reference to a media entity.
 *
 * This is the gap that motivated the whole proof of concept. YaleSites stores
 * files as media entities and references them from node fields, and the
 * existing CSV importer says plainly that "a media reference cannot travel in
 * a CSV cell" — so imported resources arrive without their PDFs and someone
 * attaches them by hand, one at a time.
 *
 * Feeds 3.2 does not close that gap either. Its File and Image targets write
 * to file and image *fields*, not to an entity reference pointing at a media
 * entity, and its EntityReference target can autocreate a media entity by name
 * but has no way to put a file inside it. So this target does the three steps
 * in between: download the URL, wrap the result in a File, and wrap that in a
 * Media entity of the right type.
 *
 * A media target was committed to Feeds' development branch after the 3.2
 * release. When that ships, this class should be deleted rather than
 * maintained.
 *
 * @FeedsTarget(
 *   id = "ys_media_from_url",
 *   field_types = {"entity_reference"}
 * )
 */
class MediaFromUrl extends FieldTargetBase implements ConfigurableTargetInterface, ContainerFactoryPluginInterface {

  /**
   * Schemes we are willing to fetch. Feed sources are untrusted input.
   */
  const ALLOWED_SCHEMES = ['http', 'https'];

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $client;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The file repository.
   *
   * @var \Drupal\file\FileRepositoryInterface
   */
  protected $fileRepository;

  /**
   * The token service.
   *
   * @var \Drupal\Core\Utility\Token
   */
  protected $token;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    array $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    EntityFieldManagerInterface $entity_field_manager,
    ClientInterface $client,
    FileSystemInterface $file_system,
    FileRepositoryInterface $file_repository,
    Token $token,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->client = $client;
    $this->fileSystem = $file_system;
    $this->fileRepository = $file_repository;
    $this->token = $token;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('http_client'),
      $container->get('file_system'),
      $container->get('file.repository'),
      $container->get('token'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * Targets are claimed first come, first served, and the stock
   * entity_reference target would otherwise claim every media field before
   * this plugin is consulted. So claim media reference fields explicitly,
   * overwriting whatever got there first.
   */
  public static function targets(array &$targets, FeedTypeInterface $feed_type, array $definition) {
    $processor = $feed_type->getProcessor();
    if (!$processor instanceof EntityProcessorInterface) {
      return $targets;
    }

    $field_definitions = \Drupal::service('entity_field.manager')
      ->getFieldDefinitions($processor->entityType(), $processor->bundle());

    foreach ($field_definitions as $id => $field_definition) {
      if ($field_definition->getType() !== 'entity_reference') {
        continue;
      }
      if ($field_definition->getSetting('target_type') !== 'media') {
        continue;
      }
      if ($target = static::prepareTarget($field_definition)) {
        $target->setPluginId($definition['id']);
        $targets[$id] = $target;
      }
    }

    return $targets;
  }

  /**
   * {@inheritdoc}
   */
  protected static function prepareTarget(FieldDefinitionInterface $field_definition) {
    if ($field_definition->getSetting('target_type') !== 'media') {
      return NULL;
    }

    return FieldTargetDefinition::createFromFieldDefinition($field_definition)
      ->addProperty('target_id')
      ->addProperty('alt');
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'media_bundle' => NULL,
      'existing' => FileSystemInterface::EXISTS_RENAME,
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $options = [];
    foreach ($this->getAllowedMediaBundles() as $bundle) {
      $type = $this->entityTypeManager->getStorage('media_type')->load($bundle);
      $options[$bundle] = $type ? $type->label() : $bundle;
    }

    $form['media_bundle'] = [
      '#type' => 'select',
      '#title' => $this->t('Media type to create'),
      '#options' => $options,
      '#default_value' => $this->configuration['media_bundle'] ?: $this->getDefaultBundle(),
      '#required' => TRUE,
      '#description' => $this->t('Downloaded files are wrapped in a media entity of this type.'),
    ];

    $form['existing'] = [
      '#type' => 'select',
      '#title' => $this->t('If a file with the same name already exists'),
      '#options' => [
        FileSystemInterface::EXISTS_RENAME => $this->t('Save with a new name'),
        FileSystemInterface::EXISTS_REPLACE => $this->t('Replace the existing file'),
      ],
      '#default_value' => $this->configuration['existing'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getSummary() {
    $bundle = $this->configuration['media_bundle'] ?: $this->getDefaultBundle();

    return $this->t('Creates %bundle media from a remote URL', ['%bundle' => $bundle]);
  }

  /**
   * {@inheritdoc}
   */
  protected function prepareValue($delta, array &$values) {
    $url = trim((string) ($values['target_id'] ?? ''));
    $alt = trim((string) ($values['alt'] ?? ''));

    if ($url === '') {
      throw new EmptyFeedException('No media URL given.');
    }

    $values['target_id'] = $this->resolveMedia($url, $alt);
    unset($values['alt']);
  }

  /**
   * Returns the id of a media entity for the given URL, creating it if needed.
   *
   * @param string $url
   *   The remote URL to fetch.
   * @param string $alt
   *   Alternative text, required by the image media type.
   *
   * @return int
   *   The media entity id.
   *
   * @throws \Drupal\feeds\Exception\TargetValidationException
   *   If the URL is unusable or the download fails.
   */
  protected function resolveMedia($url, $alt) {
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    if (!in_array($scheme, self::ALLOWED_SCHEMES, TRUE)) {
      throw new TargetValidationException(sprintf('Refusing to fetch %s: only http and https URLs are allowed.', $url));
    }

    $bundle = $this->configuration['media_bundle'] ?: $this->getDefaultBundle();
    $source_field = $this->getSourceFieldName($bundle);
    $settings = $this->getSourceFieldSettings($bundle, $source_field);

    $filename = $this->getFileName($url, $settings['file_extensions'] ?? '');
    $directory = $this->getDestinationDirectory($settings);
    $destination = $directory . '/' . $filename;

    // Re-importing the same feed must not pile up duplicate copies of every
    // file, so reuse a media entity that already points at this URI.
    if ($existing = $this->findExistingMedia($destination, $bundle, $source_field)) {
      return $existing;
    }

    $data = $this->download($url, $settings['max_filesize'] ?? NULL);
    $file = $this->fileRepository->writeData($data, $destination, $this->configuration['existing']);

    if (!$file) {
      throw new TargetValidationException(sprintf('Could not save the file downloaded from %s.', $url));
    }

    return $this->createMedia($file, $bundle, $source_field, $settings, $alt, $filename);
  }

  /**
   * Creates and saves a media entity wrapping a file.
   *
   * @param \Drupal\file\FileInterface $file
   *   The saved file.
   * @param string $bundle
   *   The media bundle to create.
   * @param string $source_field
   *   The media type's source field name.
   * @param array $settings
   *   The source field settings.
   * @param string $alt
   *   Alternative text.
   * @param string $filename
   *   The file name, used as a fallback media label.
   *
   * @return int
   *   The media entity id.
   */
  protected function createMedia($file, $bundle, $source_field, array $settings, $alt, $filename) {
    $value = ['target_id' => $file->id()];

    // The image media type requires alt text; a media entity saved without it
    // fails validation and takes the whole row down with it.
    if (!empty($settings['alt_field'])) {
      $value['alt'] = $alt !== '' ? $alt : $filename;
    }

    $media = $this->entityTypeManager->getStorage('media')->create([
      'bundle' => $bundle,
      'name' => $alt !== '' ? $alt : $filename,
      'status' => 1,
      $source_field => $value,
    ]);
    $media->save();

    return $media->id();
  }

  /**
   * Downloads a URL and returns its body.
   *
   * @param string $url
   *   The URL to fetch.
   * @param int|null $max_filesize
   *   Maximum permitted size, from the media source field settings.
   *
   * @return string
   *   The response body.
   *
   * @throws \Drupal\feeds\Exception\TargetValidationException
   *   If the request fails or the payload is too large.
   */
  protected function download($url, $max_filesize) {
    try {
      $response = $this->client->request('GET', $url, ['timeout' => 30]);
    }
    catch (\Exception $e) {
      throw new TargetValidationException(sprintf('Download of %s failed: %s', $url, $e->getMessage()));
    }

    if ($response->getStatusCode() >= 400) {
      throw new TargetValidationException(sprintf('Download of %s failed with status %d.', $url, $response->getStatusCode()));
    }

    $data = (string) $response->getBody();

    if ($max_filesize && strlen($data) > Bytes::toNumber($max_filesize)) {
      throw new TargetValidationException(sprintf('The file at %s is larger than the %s allowed for this field.', $url, $max_filesize));
    }

    return $data;
  }

  /**
   * Derives a safe file name from a URL and checks it against the allow-list.
   *
   * @param string $url
   *   The remote URL.
   * @param string $allowed_extensions
   *   Space separated list of permitted extensions.
   *
   * @return string
   *   The file name.
   *
   * @throws \Drupal\feeds\Exception\TargetValidationException
   *   If the extension is not permitted.
   */
  protected function getFileName($url, $allowed_extensions) {
    $path = (string) parse_url($url, PHP_URL_PATH);
    $filename = trim(basename($path), " \t\n\r\0\x0B.");

    if ($filename === '') {
      throw new TargetValidationException(sprintf('Could not work out a file name from %s.', $url));
    }

    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $allowed = array_filter(explode(' ', strtolower($allowed_extensions)));

    if ($allowed && !in_array($extension, $allowed, TRUE)) {
      throw new TargetValidationException(sprintf(
        'The file at %s has extension %s, which is not one of: %s.',
        $url,
        $extension,
        implode(', ', $allowed)
      ));
    }

    return $filename;
  }

  /**
   * Returns the prepared destination directory for a media source field.
   *
   * @param array $settings
   *   The source field settings.
   *
   * @return string
   *   A stream wrapper URI.
   */
  protected function getDestinationDirectory(array $settings) {
    $scheme = $settings['uri_scheme'] ?? 'public';
    $directory = trim($settings['file_directory'] ?? '', '/');
    $destination = $this->token->replace($scheme . '://' . $directory);

    $this->fileSystem->prepareDirectory(
      $destination,
      FileSystemInterface::MODIFY_PERMISSIONS | FileSystemInterface::CREATE_DIRECTORY
    );

    return rtrim($destination, '/');
  }

  /**
   * Finds a media entity already pointing at a given file URI.
   *
   * @param string $uri
   *   The file URI.
   * @param string $bundle
   *   The media bundle.
   * @param string $source_field
   *   The media source field name.
   *
   * @return int|null
   *   A media id, or NULL if none matches.
   */
  protected function findExistingMedia($uri, $bundle, $source_field) {
    $fids = $this->entityTypeManager->getStorage('file')->getQuery()
      ->accessCheck(FALSE)
      ->condition('uri', $uri)
      ->range(0, 1)
      ->execute();

    if (!$fids) {
      return NULL;
    }

    $mids = $this->entityTypeManager->getStorage('media')->getQuery()
      ->accessCheck(FALSE)
      ->condition('bundle', $bundle)
      ->condition($source_field . '.target_id', reset($fids))
      ->range(0, 1)
      ->execute();

    return $mids ? (int) reset($mids) : NULL;
  }

  /**
   * Returns the media bundles the mapped field will accept.
   *
   * @return string[]
   *   Media bundle machine names.
   */
  protected function getAllowedMediaBundles() {
    $handler = $this->settings['handler_settings'] ?? [];
    $bundles = $handler['target_bundles'] ?? [];

    if ($bundles) {
      return array_values($bundles);
    }

    return array_keys($this->entityTypeManager->getStorage('media_type')->loadMultiple());
  }

  /**
   * Returns the bundle to use when none has been configured.
   *
   * @return string|null
   *   A media bundle machine name.
   */
  protected function getDefaultBundle() {
    $bundles = $this->getAllowedMediaBundles();

    return $bundles ? reset($bundles) : NULL;
  }

  /**
   * Returns a media type's source field name.
   *
   * @param string $bundle
   *   The media bundle.
   *
   * @return string
   *   The source field machine name.
   *
   * @throws \Drupal\feeds\Exception\TargetValidationException
   *   If the media type has no usable source field.
   */
  protected function getSourceFieldName($bundle) {
    $media_type = $this->entityTypeManager->getStorage('media_type')->load($bundle);

    if (!$media_type) {
      throw new TargetValidationException(sprintf('Unknown media type %s.', $bundle));
    }

    $source_field = $media_type->getSource()->getConfiguration()['source_field'] ?? '';

    if ($source_field === '') {
      throw new TargetValidationException(sprintf('Media type %s has no source field.', $bundle));
    }

    return $source_field;
  }

  /**
   * Returns the settings of a media type's source field.
   *
   * @param string $bundle
   *   The media bundle.
   * @param string $source_field
   *   The source field name.
   *
   * @return array
   *   The field settings.
   */
  protected function getSourceFieldSettings($bundle, $source_field) {
    $definitions = $this->entityFieldManager->getFieldDefinitions('media', $bundle);

    return isset($definitions[$source_field]) ? $definitions[$source_field]->getSettings() : [];
  }

}
