<?php

namespace Drupal\Tests\ys_core\Kernel;

use Drupal\Core\Config\Schema\SchemaCheckTrait;

/**
 * Tests that the managed_file config schema matches the values sites hold.
 *
 * A managed_file element yields a list of integer file IDs - core casts with
 * (int) in \Drupal\file\Element\ManagedFile::valueCallback() and otherwise
 * appends $file->id() - so the two sequences that store one have to declare
 * integer members. Declaring them as strings makes every site that has ever
 * uploaded a favicon or a site name image report a schema error, which is the
 * outcome yalesites-org/YaleSites-Internal#1697's first acceptance criterion
 * rules out.
 *
 * Saving is not the check. Config::save() casts the whole tree to the schema
 * before it dispatches ConfigEvents::SAVE, so KernelTestBase's strict schema
 * checker only ever sees post-cast data and a save-only test passes against
 * either declaration. That is also why the mismatch is not self-correcting on
 * the sites that have it: their value was written before any schema existed,
 * ys_core_deploy_10009() skips it because it is already an array, and nothing
 * re-saves it until an editor next submits the form. So the schema is checked
 * here directly against stored-shaped data, the way drush config:inspect reads
 * an existing site, and the round trip is asserted separately.
 *
 * @group ys_core
 */
class ManagedFileConfigSchemaTest extends YsKernelTestBase {

  use SchemaCheckTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'file', 'image', 'ys_core'];

  /**
   * The managed_file keys, and the config object each one lives in.
   */
  const MANAGED_FILE_KEYS = [
    'ys_core.site' => 'custom_favicon',
    'ys_core.header_settings' => 'site_name_image',
  ];

  /**
   * Stored integer file IDs must validate against the declared schema.
   *
   * This is the assertion that fails when the sequence declares strings: it
   * reads the data as it sits in a site's active config, without the cast a
   * save would apply.
   */
  public function testStoredIntegerFileIdsValidate(): void {
    $typed_config = $this->container->get('config.typed');

    foreach (self::MANAGED_FILE_KEYS as $name => $key) {
      $data = $this->config($name)->getRawData();
      $data[$key] = [12];

      $this->assertTrue(
        $this->checkConfigSchema($typed_config, $name, $data),
        "$name:$key rejects the integer file IDs a managed_file element stores."
      );
    }
  }

  /**
   * A saved file ID must still be an integer when it is read back.
   *
   * Config::save() casts to the schema, so a string declaration silently
   * rewrites every site's stored IDs on the next form submit. Readers only
   * ever hand the value to file storage, which accepts either, so nothing
   * breaks visibly - which is exactly why it needs pinning here.
   */
  public function testSavedFileIdsRoundTripAsIntegers(): void {
    foreach (self::MANAGED_FILE_KEYS as $name => $key) {
      $this->config($name)->set($key, [12])->save();

      $this->assertSame(
        [12],
        $this->config($name)->get($key),
        "$name:$key was cast away from the integer file ID it was given."
      );
    }
  }

  /**
   * The deploy hook repairs a list whose IDs were stored as strings.
   *
   * This is not hypothetical: the checkout this was written in held
   * ys_core.site custom_favicon as ["50"]. That shape is already an array, so
   * the hook's original guard passed straight over it, and nothing re-saves
   * the object until an editor next submits the form - so without this the
   * site reports a schema error indefinitely.
   */
  public function testDeployHookCastsStringFileIdsToIntegers(): void {
    $this->seedRawConfig('ys_core.site', ['custom_favicon' => ['50']]);
    $this->seedRawConfig('ys_core.header_settings', ['site_name_image' => ['7']]);

    \Drupal::moduleHandler()->loadInclude('ys_core', 'php', 'ys_core.deploy');
    ys_core_deploy_10009();

    $this->assertSame([50], $this->config('ys_core.site')->get('custom_favicon'));
    $this->assertSame([7], $this->config('ys_core.header_settings')->get('site_name_image'));
  }

  /**
   * The deploy hook leaves a correct value alone rather than rewriting it.
   *
   * The repair runs on every site at every deploy, so the guard has to be a
   * comparison and not just "is it an array" - otherwise it writes and cache
   * invalidates two config objects on every site, forever, to change nothing.
   *
   * Comparing the data before and after cannot show that on its own: a save
   * of an identical array leaves the data identical, so the wasted write and
   * the cache tag invalidation are both invisible to it. The hook's return
   * value is what distinguishes "did nothing" from "wrote the same thing
   * back", so that is the assertion that fails if the guard is dropped.
   */
  public function testDeployHookDoesNotRewriteCorrectFileIds(): void {
    $this->config('ys_core.site')->set('custom_favicon', [50])->save();
    $before = $this->config('ys_core.site')->get();

    \Drupal::moduleHandler()->loadInclude('ys_core', 'php', 'ys_core.deploy');

    $this->assertSame(
      'All ys_core config values already match the new schema.',
      (string) ys_core_deploy_10009(),
      'The hook reported a repair on data that was already correct.'
    );
    $this->assertSame($before, $this->config('ys_core.site')->get());
  }

  /**
   * An unset image stays a valid empty list.
   *
   * The shipped default in config/install is '', which ys_core_deploy_10009()
   * normalises to []. An empty sequence type-checks under any member type, so
   * this is the case that hid the mismatch: a site with no image uploaded
   * reports no error either way.
   */
  public function testEmptyFileListValidates(): void {
    $typed_config = $this->container->get('config.typed');

    foreach (self::MANAGED_FILE_KEYS as $name => $key) {
      $data = $this->config($name)->getRawData();
      $data[$key] = [];

      $this->assertTrue(
        $this->checkConfigSchema($typed_config, $name, $data),
        "$name:$key rejects an empty list."
      );
    }
  }

  /**
   * Writes a value straight to config storage, skipping Config::save().
   *
   * The whole point of these cases is data that does not match the schema,
   * and Config::save() will not produce it: it casts to the schema, and
   * save(TRUE) skips the cast but still dispatches ConfigEvents::SAVE, so
   * KernelTestBase's strict checker throws before the test body runs. Writing
   * to the storage is the only way to stand up a site in the state this hook
   * exists to repair.
   */
  protected function seedRawConfig(string $name, array $values): void {
    $storage = $this->container->get('config.storage');
    $storage->write($name, $values + ($storage->read($name) ?: []));
    $this->container->get('config.factory')->reset($name);
  }

}
