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
   * The AI Tester form route path.
   */
  private const TESTER_PATH = '/admin/config/yalesites/ys_ai/tester';

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
   * Creates a user with the tester permission and logs them in.
   */
  private function loginAsTesterUser(): void {
    $this->drupalLogin($this->drupalCreateUser(['use ys ai tester']));
  }

  /**
   * Uploads YAML content to the tester form and submits it.
   *
   * @param string $content
   *   The file contents to upload.
   * @param string $extension
   *   The temp file extension, including the leading dot.
   */
  private function submitYamlUpload(string $content, string $extension = '.yml'): void {
    $tmp = tempnam(sys_get_temp_dir(), 'yaml_') . $extension;
    file_put_contents($tmp, $content);
    $this->drupalGet(self::TESTER_PATH);
    $this->submitForm(['files[yaml_file]' => $tmp], 'Run test');
    unlink($tmp);
  }

  /**
   * Tests that the tester route requires the use ys ai tester permission.
   */
  public function testTesterRouteRequiresPermission(): void {
    $this->drupalGet(self::TESTER_PATH);
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Tests that the tester form renders correctly for an authorized user.
   */
  public function testTesterFormRendersForAuthorizedUser(): void {
    $this->loginAsTesterUser();
    $this->drupalGet(self::TESTER_PATH);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldExists('files[yaml_file]');
    $this->assertSession()->buttonExists('Run test');
  }

  /**
   * Tests that the form rejects a YAML file containing non-string values.
   */
  public function testFormRejectsInvalidYaml(): void {
    $this->loginAsTesterUser();
    $this->submitYamlUpload("key:\n  - not: a flat list\n  - of: strings");
    $this->assertSession()->pageTextContains('All YAML values must be strings');
  }

  /**
   * Tests that the form rejects a file without a .yml/.yaml extension.
   */
  public function testFormRejectsNonYamlExtension(): void {
    $this->loginAsTesterUser();
    // Valid YAML content, but the wrong file extension.
    $this->submitYamlUpload("- What is Yale?\n- Where is Yale?\n", '.txt');
    $this->assertSession()->pageTextContains('The file must be a .yml or .yaml file');
  }

  /**
   * Tests that the form rejects a file larger than the allowed maximum.
   */
  public function testFormRejectsOversizedFile(): void {
    $this->loginAsTesterUser();
    // A valid YAML list of strings that exceeds MAX_UPLOAD_BYTES.
    $line = "- " . str_repeat('a', 60) . "\n";
    $oversized = str_repeat($line, (int) ceil((AiTesterForm::MAX_UPLOAD_BYTES + 1024) / strlen($line)));
    $this->submitYamlUpload($oversized);
    $this->assertSession()->pageTextContains('The file is too large');
  }

  /**
   * Tests that the form rejects an empty YAML list.
   */
  public function testFormRejectsEmptyYaml(): void {
    $this->loginAsTesterUser();
    $this->submitYamlUpload('[]');
    $this->assertSession()->pageTextContains('YAML must contain a non-empty list');
  }

  /**
   * Tests that the run history table shows the empty state message initially.
   */
  public function testHistoryTableShowsNoRunsInitially(): void {
    $this->loginAsTesterUser();
    $this->drupalGet(self::TESTER_PATH);
    $this->assertSession()->pageTextContains('No test runs yet');
  }

  /**
   * Tests that the run detail page requires the use ys ai tester permission.
   */
  public function testRunDetailPageRequiresPermission(): void {
    $this->drupalGet(self::TESTER_PATH . '/999');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Tests that the run detail page returns 404 for a non-existent run ID.
   */
  public function testRunDetailPageReturns404ForMissingRun(): void {
    $this->loginAsTesterUser();
    $this->drupalGet(self::TESTER_PATH . '/999');
    $this->assertSession()->statusCodeEquals(404);
  }

}
