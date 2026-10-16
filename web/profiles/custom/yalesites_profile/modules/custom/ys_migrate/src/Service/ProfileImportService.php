<?php

namespace Drupal\ys_migrate\Service;

/**
 * Service for importing profile content from CSV data.
 */
class ProfileImportService extends CsvImportServiceBase {

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
   * @return \Drupal\node\NodeInterface
   *   The created node.
   *
   * @throws \Exception
   *   If the node cannot be saved. The reason is logged and re-thrown so the
   *   caller can surface it instead of a generic failure message.
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
      // Re-throw so processImport() can report the real reason to the user
      // instead of a generic "could not create profile" message.
      throw $e;
    }
  }

  /**
   * {@inheritdoc}
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

        // createProfileNode() returns the node or throws with the real reason,
        // which the catch below reports (never silently dropping the row).
        $this->createProfileNode($profile_data);
        $created++;
      }
      catch (\Exception $e) {
        $errors[] = $this->rowError($row, $index, $e);
      }
    }

    return [
      'created' => $created,
      'skipped' => $skipped,
      'errors' => $errors,
    ];
  }

  /**
   * {@inheritdoc}
   *
   * Profiles are keyed by email, and a row with no email is never treated as
   * a duplicate. The importable rows come back under 'valid_profiles'.
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
