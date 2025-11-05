<?php

namespace Drupal\ys_migrate\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Service for importing profile content from CSV data.
 */
class ProfileImportService {

  use StringTranslationTrait;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The taxonomy resolver service.
   *
   * @var \Drupal\ys_migrate\Service\TaxonomyResolverService
   */
  protected $taxonomyResolver;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * Constructs a ProfileImportService object.
   *
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\ys_migrate\Service\TaxonomyResolverService $taxonomy_resolver
   *   The taxonomy resolver service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    AccountInterface $current_user,
    TaxonomyResolverService $taxonomy_resolver,
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->currentUser = $current_user;
    $this->taxonomyResolver = $taxonomy_resolver;
    $this->entityTypeManager = $entity_type_manager;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * Prepares profile data from CSV row.
   *
   * @param array $row
   *   The CSV row data.
   *
   * @return array
   *   Prepared profile data.
   */
  public function prepareProfileData(array $row) {
    $data = [
      'display_name' => trim($row['display name']),
      'first_name' => trim($row['first name'] ?? ''),
      'last_name' => trim($row['last name'] ?? ''),
      'honorific_prefix' => trim($row['honorific prefix'] ?? ''),
      'pronouns' => trim($row['pronouns'] ?? ''),
      'position' => trim($row['position'] ?? ''),
      'subtitle' => trim($row['subtitle'] ?? ''),
      'department' => trim($row['department'] ?? ''),
      'email' => trim($row['email'] ?? ''),
      'telephone' => trim($row['telephone'] ?? ''),
      'address' => trim($row['address'] ?? ''),
      'teaser_title' => trim($row['teaser title'] ?? ''),
      'teaser_text' => trim($row['teaser text'] ?? ''),
      'affiliation' => $this->taxonomyResolver->parseCommaSeparatedValues($row['affiliation'] ?? ''),
      'audience' => $this->taxonomyResolver->parseCommaSeparatedValues($row['audience'] ?? ''),
      'tags' => $this->taxonomyResolver->parseCommaSeparatedValues($row['tags'] ?? ''),
      'custom_vocab' => $this->taxonomyResolver->parseCommaSeparatedValues($row['custom vocabulary'] ?? ''),
    ];

    return $data;
  }

  /**
   * Finds an existing profile by email.
   *
   * @param string $email
   *   The email address to search for.
   *
   * @return \Drupal\node\NodeInterface|null
   *   The existing profile node or null.
   */
  public function findExistingProfile($email) {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'profile')
      ->condition('field_email', $email)
      ->range(0, 1)
      ->accessCheck(FALSE);

    $nids = $query->execute();

    if (!empty($nids)) {
      return $this->entityTypeManager->getStorage('node')->load(reset($nids));
    }

    return NULL;
  }

  /**
   * Creates a profile node from the prepared data.
   *
   * @param array $data
   *   The profile data.
   *
   * @return \Drupal\node\NodeInterface|null
   *   The created node or null on failure.
   */
  public function createProfileNode(array $data) {
    $node = $this->entityTypeManager->getStorage('node')->create(
      [
        'type' => 'profile',
        'title' => $data['display_name'],
        'field_first_name' => $data['first_name'],
        'field_last_name' => $data['last_name'],
        'field_honorific_prefix' => $data['honorific_prefix'],
        'field_pronouns' => $data['pronouns'],
        'field_position' => $data['position'],
        'field_subtitle' => $data['subtitle'],
        'field_department' => $data['department'],
        'field_email' => $data['email'],
        'field_telephone' => $data['telephone'],
        'field_address' => $data['address'],
        'field_teaser_title' => $data['teaser_title'],
        'field_teaser_text' => $data['teaser_text'],
        'field_affiliation' => $this->taxonomyResolver->resolveTerms($data['affiliation'], 'affiliation'),
        'field_audience' => $this->taxonomyResolver->resolveTerms($data['audience'], 'audience'),
        'field_tags' => $this->taxonomyResolver->resolveTerms($data['tags'], 'tags'),
        'field_custom_vocab' => $this->taxonomyResolver->resolveTerms($data['custom_vocab'], 'custom_vocab'),
        'uid' => $this->currentUser->id(),
        'status' => 1,
      ]
    );

    try {
      $node->save();
      return $node;
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('ys_migrate')->error('Failed to create profile node: @error', ['@error' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Processes the import and creates profile nodes.
   *
   * @param array $data
   *   The CSV data.
   * @param bool $skip_duplicates
   *   Whether to skip duplicates.
   *
   * @return array
   *   Import results with 'created', 'skipped', and 'errors' keys.
   */
  public function processImport(array $data, $skip_duplicates) {
    $created = 0;
    $skipped = 0;
    $errors = [];

    foreach ($data as $index => $row) {
      try {
        $profile_data = $this->prepareProfileData($row);

        if ($skip_duplicates && !empty($profile_data['email'])) {
          $existing = $this->findExistingProfile($profile_data['email']);
          if ($existing) {
            $skipped++;
            continue;
          }
        }

        $node = $this->createProfileNode($profile_data);
        if ($node) {
          $created++;
        }
      }
      catch (\Exception $e) {
        $errors[] = $this->t(
          'Row @row: @error', [
            // +2 because index starts at 0 and we skip header
            '@row' => $index + 2,
            '@error' => $e->getMessage(),
          ]
        );
      }
    }

    return [
      'created' => $created,
      'skipped' => $skipped,
      'errors' => $errors,
    ];
  }

  /**
   * Previews the import without creating content.
   *
   * @param array $data
   *   The CSV data.
   * @param bool $skip_duplicates
   *   Whether to skip duplicates.
   *
   * @return array
   *   Preview results with 'valid_profiles', 'duplicates', and 'total' keys.
   */
  public function previewImport(array $data, $skip_duplicates) {
    $duplicates = [];
    $valid_profiles = [];

    foreach ($data as $row) {
      $profile_data = $this->prepareProfileData($row);

      if ($skip_duplicates && !empty($profile_data['email'])) {
        $existing = $this->findExistingProfile($profile_data['email']);
        if ($existing) {
          $duplicates[] = $profile_data['display_name'];
          continue;
        }
      }

      $valid_profiles[] = $profile_data;
    }

    return [
      'valid_profiles' => $valid_profiles,
      'duplicates' => $duplicates,
      'total' => count($data),
    ];
  }

}
