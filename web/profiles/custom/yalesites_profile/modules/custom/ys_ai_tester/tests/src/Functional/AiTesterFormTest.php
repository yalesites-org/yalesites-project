<?php

declare(strict_types=1);

namespace Drupal\Tests\ys_ai_tester\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\ys_ai_tester\Form\AiTesterForm;

/**
 * Tests the AI Tester form.
 *
 * @group ys_ai_tester
 */
class AiTesterFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $profile = 'yalesites_profile';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['ys_ai_tester'];

  /**
   * {@inheritdoc}
   *
   * Ys_themes ships a component_overrides config object without a schema
   * definition. Disable strict schema checking so the profile installs cleanly
   * in tests. Fixing the schema gap in ys_themes is a separate concern.
   *
   * @see https://www.drupal.org/project/drupal/issues/2500617
   */
  // phpcs:ignore DrupalPractice.Objects.StrictSchemaDisabled
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests that the tester route requires the use ys ai tester permission.
   */
  public function testTesterRouteRequiresPermission(): void {
    $this->drupalGet('/admin/config/yalesites/ys_ai/tester');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Tests that the tester form renders correctly for an authorized user.
   */
  public function testTesterFormRendersForAuthorizedUser(): void {
    $user = $this->drupalCreateUser(['use ys ai tester']);
    $this->drupalLogin($user);
    $this->drupalGet('/admin/config/yalesites/ys_ai/tester');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldExists('files[yaml_file]');
    $this->assertSession()->buttonExists('Run test');
  }

  /**
   * Tests that the form rejects a YAML file containing non-string values.
   */
  public function testFormRejectsInvalidYaml(): void {
    $user = $this->drupalCreateUser(['use ys ai tester']);
    $this->drupalLogin($user);

    $invalid_yaml = "key:\n  - not: a flat list\n  - of: strings";
    $tmp = tempnam(sys_get_temp_dir(), 'yaml_') . '.yml';
    file_put_contents($tmp, $invalid_yaml);

    $this->drupalGet('/admin/config/yalesites/ys_ai/tester');
    $this->submitForm(
      ['files[yaml_file]' => $tmp],
      'Run test',
    );
    $this->assertSession()->pageTextContains('All YAML values must be strings');

    unlink($tmp);
  }

  /**
   * Tests that the form rejects a file without a .yml/.yaml extension.
   */
  public function testFormRejectsNonYamlExtension(): void {
    $user = $this->drupalCreateUser(['use ys ai tester']);
    $this->drupalLogin($user);

    // Valid YAML content, but the wrong file extension.
    $tmp = tempnam(sys_get_temp_dir(), 'yaml_') . '.txt';
    file_put_contents($tmp, "- What is Yale?\n- Where is Yale?\n");

    $this->drupalGet('/admin/config/yalesites/ys_ai/tester');
    $this->submitForm(
      ['files[yaml_file]' => $tmp],
      'Run test',
    );
    $this->assertSession()->pageTextContains('The file must be a .yml or .yaml file');

    unlink($tmp);
  }

  /**
   * Tests that the form rejects a file larger than the allowed maximum.
   */
  public function testFormRejectsOversizedFile(): void {
    $user = $this->drupalCreateUser(['use ys ai tester']);
    $this->drupalLogin($user);

    // A valid YAML list of strings that exceeds MAX_UPLOAD_BYTES.
    $line = "- " . str_repeat('a', 60) . "\n";
    $oversized = str_repeat($line, (int) ceil((AiTesterForm::MAX_UPLOAD_BYTES + 1024) / strlen($line)));
    $tmp = tempnam(sys_get_temp_dir(), 'yaml_') . '.yml';
    file_put_contents($tmp, $oversized);

    $this->drupalGet('/admin/config/yalesites/ys_ai/tester');
    $this->submitForm(
      ['files[yaml_file]' => $tmp],
      'Run test',
    );
    $this->assertSession()->pageTextContains('The file is too large');

    unlink($tmp);
  }

  /**
   * Tests that the form rejects an empty YAML list.
   */
  public function testFormRejectsEmptyYaml(): void {
    $user = $this->drupalCreateUser(['use ys ai tester']);
    $this->drupalLogin($user);

    $tmp = tempnam(sys_get_temp_dir(), 'yaml_') . '.yml';
    file_put_contents($tmp, '[]');

    $this->drupalGet('/admin/config/yalesites/ys_ai/tester');
    $this->submitForm(
      ['files[yaml_file]' => $tmp],
      'Run test',
    );
    $this->assertSession()->pageTextContains('YAML must contain a non-empty list');

    unlink($tmp);
  }

  /**
   * Tests that the run history table shows the empty state message initially.
   */
  public function testHistoryTableShowsNoRunsInitially(): void {
    $user = $this->drupalCreateUser(['use ys ai tester']);
    $this->drupalLogin($user);
    $this->drupalGet('/admin/config/yalesites/ys_ai/tester');
    $this->assertSession()->pageTextContains('No test runs yet');
  }

  /**
   * Tests that the run detail page requires the use ys ai tester permission.
   */
  public function testRunDetailPageRequiresPermission(): void {
    $this->drupalGet('/admin/config/yalesites/ys_ai/tester/999');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Tests that the run detail page returns 404 for a non-existent run ID.
   */
  public function testRunDetailPageReturns404ForMissingRun(): void {
    $user = $this->drupalCreateUser(['use ys ai tester']);
    $this->drupalLogin($user);
    $this->drupalGet('/admin/config/yalesites/ys_ai/tester/999');
    $this->assertSession()->statusCodeEquals(404);
  }

}
