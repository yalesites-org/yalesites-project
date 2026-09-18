<?php

namespace Drupal\ys_feeds_demo\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\File\FileSystemInterface;
use Drush\Commands\DrushCommands;
use GuzzleHttp\ClientInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Drush commands for running and re-running the Feeds demonstrations.
 *
 * A demo that can only be given once is not much use. This resets everything
 * the demo feeds created — nodes, media, files and the feed records themselves
 * — so the whole sequence can be rehearsed from a clean slate as often as
 * needed, and so nothing is left behind afterwards.
 */
class FeedsDemoCommands extends DrushCommands {

  /**
   * The demo feed types, and the fixture each one reads by default.
   */
  const DEMO_FEEDS = [
    'demo_staff_roster' => [
      'title' => 'EPS staff roster (demo)',
      'fixture' => 'staff-roster.csv',
      'source' => 'http',
    ],
    'demo_resource_library' => [
      'title' => 'Special Collections catalogue (demo)',
      'fixture' => 'resources.csv',
      'source' => 'upload',
    ],
    'demo_content_roundtrip' => [
      'title' => 'Bulk edit round trip (demo)',
      'fixture' => NULL,
      'source' => 'upload',
    ],
  ];

  /**
   * Where fixtures are published so the HTTP fetcher can reach them.
   */
  const PUBLIC_DIR = 'public://feeds-demo';

  /**
   * Placeholder the catalogue fixture uses in place of a real site address.
   */
  const BASE_URL_PLACEHOLDER = '__BASE_URL__';

  /**
   * Constructs a FeedsDemoCommands object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system service.
   * @param \Drupal\Core\Extension\ModuleExtensionList $moduleExtensionList
   *   The module extension list, used to locate the fixtures directory.
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The HTTP client, used to check the published fixtures are reachable.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack, used to work out the site's own address.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FileSystemInterface $fileSystem,
    protected ModuleExtensionList $moduleExtensionList,
    protected ClientInterface $httpClient,
    protected RequestStack $requestStack,
  ) {
    parent::__construct();
  }

  /**
   * Publishes the demo fixtures so the feeds can fetch them over HTTP.
   *
   * The staff roster feed exists to show a site following a Google Sheet. For
   * a rehearsal with no sheet — or no network — the same CSV is served from
   * the site itself, so swapping in a real published sheet later is a change
   * to one field on the feed and nothing else.
   *
   * @command ys-feeds-demo:publish-fixtures
   * @aliases ysfd-publish
   * @option variant Which roster fixture to publish: v1, v2 or v3.
   * @option base-url Origin to write into the catalogue fixture. Defaults to
   *   the site's own address.
   * @usage ys-feeds-demo:publish-fixtures --variant=v2
   *   Publish the edited roster, to demonstrate update-in-place.
   */
  public function publishFixtures(array $options = ['variant' => 'v1', 'base-url' => NULL]) {
    $source_dir = $this->fixtureDirectory();
    $base_url = $this->baseUrl($options['base-url'] ?? NULL);
    $dest = self::PUBLIC_DIR;
    $this->fileSystem->prepareDirectory($dest, FileSystemInterface::MODIFY_PERMISSIONS | FileSystemInterface::CREATE_DIRECTORY);
    $dest_real = $this->fileSystem->realpath($dest);

    $roster = match ($options['variant']) {
      'v2' => 'staff-roster-v2.csv',
      'v3' => 'staff-roster-v3.csv',
      default => 'staff-roster.csv',
    };

    copy($source_dir . '/' . $roster, $dest_real . '/staff-roster.csv');

    // The catalogue fixture points at its own PDFs and cover images, which
    // means it has to know the site's address. Committing a hostname would
    // make the fixture work on exactly one machine, so it carries a
    // placeholder and the real address is written in here instead.
    $catalogue = file_get_contents($source_dir . '/resources.csv');
    $catalogue = str_replace(self::BASE_URL_PLACEHOLDER, rtrim($base_url, '/'), $catalogue);
    file_put_contents($dest_real . '/resources.csv', $catalogue);

    // The catalogue's PDFs and cover images are not copied anywhere. They are
    // served straight out of the module directory, which means they are
    // present wherever the module is deployed. Copying them into the files
    // directory used to be a second step that had to happen first, and when it
    // did not, every media URL in the catalogue returned 404 — fourteen
    // confusing errors instead of the two the demo intends.
    $this->logger()->success(dt('Published fixtures for @url (roster variant: @v).', [
      '@url' => $base_url,
      '@v' => $options['variant'],
    ]));

    return $base_url;
  }

  /**
   * Deletes everything the demo feeds created.
   *
   * Feeds' own "delete items" removes the nodes it created but leaves the
   * media, the files behind them and the terms it auto-created. After three
   * rehearsals that debris is louder than the demo, so this sweeps all of it.
   *
   * Order matters: the nodes are found through the feeds_item field, and that
   * tracking is destroyed the moment a feed or feed type is deleted. So the
   * nodes go first, then the media, then the feeds.
   *
   * @command ys-feeds-demo:reset
   * @aliases ysfd-reset
   * @option keep-media Leave imported media and files in place.
   * @option type Reset only this feed type, leaving the others untouched.
   * @usage ys-feeds-demo:reset
   *   Reset all three demos.
   * @usage ys-feeds-demo:reset --type=demo_resource_library
   *   Reset only the catalogue, so a roster feed already pointed at a Google
   *   Sheet keeps its source.
   */
  public function reset(array $options = ['keep-media' => FALSE, 'type' => NULL]) {
    $node_storage = $this->entityTypeManager->getStorage('node');
    $feed_storage = $this->entityTypeManager->getStorage('feeds_feed');

    $nodes = 0;
    $media_ids = [];

    $types = array_keys(self::DEMO_FEEDS);

    if (!empty($options['type'])) {
      if (!in_array($options['type'], $types, TRUE)) {
        $this->logger()->error(dt('Unknown feed type @type. Known: @known', [
          '@type' => $options['type'],
          '@known' => implode(', ', $types),
        ]));

        return 1;
      }
      $types = [$options['type']];
    }

    foreach ($types as $type) {
      foreach ($feed_storage->loadByProperties(['type' => $type]) as $feed) {
        $ids = $node_storage->getQuery()
          ->accessCheck(FALSE)
          ->condition('feeds_item.target_id', $feed->id())
          ->execute();

        foreach ($node_storage->loadMultiple($ids) as $node) {
          // Collect the media before the node that references it is gone.
          foreach (['field_media', 'field_teaser_media'] as $field) {
            if ($node->hasField($field) && !$node->get($field)->isEmpty()) {
              $media_ids[$node->get($field)->target_id] = $node->get($field)->target_id;
            }
          }
        }

        if ($ids) {
          $node_storage->delete($node_storage->loadMultiple($ids));
          $nodes += count($ids);
        }

        $feed->delete();
      }
    }

    $this->logger()->success(dt('Deleted @count imported node(s).', ['@count' => $nodes]));

    if ($options['keep-media'] || !$media_ids) {
      return 0;
    }

    $media_storage = $this->entityTypeManager->getStorage('media');
    $file_storage = $this->entityTypeManager->getStorage('file');
    $files = 0;

    foreach ($media_storage->loadMultiple($media_ids) as $media) {
      $source_field = $media->getSource()->getConfiguration()['source_field'] ?? '';
      if ($source_field && !$media->get($source_field)->isEmpty()) {
        $fid = $media->get($source_field)->target_id;
        if ($file = $file_storage->load($fid)) {
          $file->delete();
          $files++;
        }
      }
      $media->delete();
    }

    $this->logger()->success(dt('Deleted @media media entities and @files file(s).', [
      '@media' => count($media_ids),
      '@files' => $files,
    ]));
    $this->logger()->notice(dt('Auto-created taxonomy terms are left alone; run drush ys-orphaned-blocks to sweep inline blocks left by the Layout Builder spike.'));

    return 0;
  }

  /**
   * Publishes the fixtures, then creates and imports the demo feeds.
   *
   * This is the single command that sets a demo up from nothing, which matters
   * most on a shared environment where nobody wants a runbook to follow.
   *
   * @command ys-feeds-demo:run
   * @aliases ysfd-run
   * @option base-url Origin the feeds should fetch fixtures from. Defaults to
   *   the site's own address.
   * @option variant Which roster fixture to publish first: v1, v2 or v3.
   * @usage ys-feeds-demo:run
   *   Publish the fixtures and import both fetching feeds.
   */
  public function run(array $options = ['base-url' => NULL, 'variant' => 'v1']) {
    $base_url = $this->publishFixtures([
      'variant' => $options['variant'],
      'base-url' => $options['base-url'] ?? NULL,
    ]);

    $feed_storage = $this->entityTypeManager->getStorage('feeds_feed');

    foreach (self::DEMO_FEEDS as $type => $info) {
      if (!$this->entityTypeManager->getStorage('feeds_feed_type')->load($type)) {
        $this->logger()->warning(dt('Feed type @type is not installed; skipping.', ['@type' => $type]));
        continue;
      }

      // The round-trip demo consumes a file the presenter exports and edits
      // during the demo itself, so there is no fixture to advertise — but the
      // feed should still exist and be waiting, so the demo is a file upload
      // rather than a detour through the feed-creation form.
      if ($info['fixture'] === NULL) {
        $this->createEmptyFeed($feed_storage, $type, $info['title']);
        $this->logger()->success(dt('Created @type, ready for a file you export during the demo.', [
          '@type' => $type,
        ]));
        continue;
      }

      if ($info['source'] === 'upload') {
        // An upload feed has no URL to import from until somebody attaches a
        // file, so create it empty and ready rather than importing it. The
        // file to attach is published alongside the fixtures.
        //
        // Check one of the media URLs the catalogue points at before handing
        // over the file. If the media is not reachable the import still
        // "works" and produces a resource for every row, just with no
        // documents attached and a wall of download errors — much better to
        // say so now, in one sentence.
        if (!$this->mediaIsReachable($base_url)) {
          return 1;
        }

        $this->createEmptyFeed($feed_storage, $type, $info['title']);
        $this->logger()->success(dt('Created @type, ready for a file. Upload this: @url', [
          '@type' => $type,
          '@url' => rtrim($base_url, '/') . '/sites/default/files/feeds-demo/' . $info['fixture'],
        ]));
        continue;
      }

      $source = rtrim($base_url, '/') . '/sites/default/files/feeds-demo/' . $info['fixture'];

      if (!$this->isReachable($source)) {
        return 1;
      }

      $feed = $feed_storage->create([
        'type' => $type,
        'title' => $info['title'],
        'source' => $source,
        'uid' => 1,
        'status' => 1,
      ]);
      $feed->save();
      $feed->import();

      $this->logger()->success(dt('Imported @type.', ['@type' => $type]));
    }

    return 0;
  }

  /**
   * Creates a feed with no source, ready for someone to attach a file.
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $feed_storage
   *   The feed storage.
   * @param string $type
   *   The feed type id.
   * @param string $title
   *   The feed label.
   */
  protected function createEmptyFeed($feed_storage, string $type, string $title) {
    $feed = $feed_storage->create([
      'type' => $type,
      'title' => $title,
      'source' => '',
      'uid' => 1,
      'status' => 1,
    ]);
    $feed->save();
  }

  /**
   * Checks that a published fixture can actually be fetched.
   *
   * The feeds read their fixtures back over HTTP from the site itself, so an
   * environment that will not serve them produces a wall of confusing import
   * errors. One request up front turns that into a sentence.
   *
   * @param string $url
   *   The fixture URL the feed will be pointed at.
   *
   * @return bool
   *   TRUE if the URL responds successfully.
   */
  protected function isReachable(string $url) {
    try {
      $response = $this->httpClient->request('GET', $url, [
        'timeout' => 15,
        'http_errors' => FALSE,
      ]);
    }
    catch (\Exception $e) {
      $this->logger()->error(dt('Could not reach @url: @message', [
        '@url' => $url,
        '@message' => $e->getMessage(),
      ]));

      return FALSE;
    }

    $code = $response->getStatusCode();

    if ($code === 200) {
      return TRUE;
    }

    $this->logger()->error(dt('@url returned @code, so the feeds cannot read it.', [
      '@url' => $url,
      '@code' => $code,
    ]));

    if ($code === 401 || $code === 403) {
      $this->logger()->notice(dt('That usually means the environment is locked. Unlock it with: terminus lock:disable <site>.<env>'));
    }
    else {
      $this->logger()->notice(dt('Check the address is right, and pass --base-url if the site does not know its own public URL from the command line.'));
    }

    return FALSE;
  }

  /**
   * Checks that the catalogue's media is reachable before a demo relies on it.
   *
   * Reads the first media URL out of the published catalogue and requests it.
   * One sample is enough: every row points into the same directory, so if one
   * resolves they all do.
   *
   * @param string $base_url
   *   The origin the fixtures were published for.
   *
   * @return bool
   *   TRUE if the media is reachable, or if no media URL could be found to
   *   test, which is not this check's business to fail on.
   */
  protected function mediaIsReachable(string $base_url) {
    $published = $this->fileSystem->realpath(self::PUBLIC_DIR) . '/resources.csv';

    if (!is_readable($published)) {
      return TRUE;
    }

    // Stop at commas and quotes: this is a CSV, and a greedy match runs
    // straight out of one column and into the next.
    if (!preg_match('#https?://[^\s,"\']+\.pdf#', (string) file_get_contents($published), $match)) {
      return TRUE;
    }

    if ($this->isReachable($match[0])) {
      return TRUE;
    }

    $this->logger()->error(dt('The catalogue points at media that is not reachable, so an import would create resources with no documents attached.'));

    return FALSE;
  }

  /**
   * Resolves the origin the feeds should fetch fixtures from.
   *
   * Order of preference: an explicit --base-url, then the Pantheon platform
   * domain when running there, then whatever address the site thinks it has.
   * Whatever comes out is checked before use, so a wrong guess produces one
   * clear message rather than a pile of import errors.
   *
   * @param string|null $override
   *   An explicit --base-url, if one was given.
   *
   * @return string
   *   The origin, with no trailing slash.
   */
  protected function baseUrl($override) {
    if (!empty($override)) {
      return rtrim($override, '/');
    }

    // On Pantheon the command runs over the CLI, where the site does not
    // reliably know its own public address. The platform domain is derivable
    // from the environment though, and it is the address a multidev is
    // actually reachable at, so prefer it. Format: env-site.pantheonsite.io,
    // which covers dev, test, live and every pr-N multidev alike.
    // Lando's Pantheon recipe also sets PANTHEON_ENVIRONMENT, to the literal
    // "lando", so exclude that sentinel — settings.php treats it the same way.
    $env = getenv('PANTHEON_ENVIRONMENT');
    $site = getenv('PANTHEON_SITE_NAME');
    if ($env && $site && $env !== 'lando') {
      return 'https://' . $env . '-' . $site . '.pantheonsite.io';
    }

    return rtrim($this->requestStack->getCurrentRequest()->getSchemeAndHttpHost(), '/');
  }

  /**
   * Returns the absolute path of the module's fixtures directory.
   *
   * @return string
   *   The fixtures directory path.
   */
  protected function fixtureDirectory() {
    $module_path = $this->moduleExtensionList->getPath('ys_feeds_demo');

    return DRUPAL_ROOT . '/' . $module_path . '/fixtures';
  }

}
