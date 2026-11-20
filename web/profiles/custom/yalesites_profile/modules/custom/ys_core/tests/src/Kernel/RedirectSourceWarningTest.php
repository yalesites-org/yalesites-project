<?php

namespace Drupal\Tests\ys_core\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;

/**
 * The redirect form's "valid path" warning has no link editors cannot open.
 *
 * The Redirect module links to the "create URL aliases" admin page, which
 * editors cannot access, so the warning is replaced with plain guidance.
 *
 * @see https://github.com/yalesites-org/YaleSites-Internal/issues/1414
 *
 * @group ys_core
 */
class RedirectSourceWarningTest extends YsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'ys_core',
  ];

  /**
   * The contrib warning is replaced and other messages are kept.
   */
  public function testContribWarningIsReplaced(): void {
    $messenger = \Drupal::messenger();
    $messenger->addStatus('Unrelated status.');
    $messenger->addWarning('Unrelated warning.');
    $messenger->addWarning('The source path /about appears to be a valid path. It is preferred to <a href="/admin/config/search/path/add">create URL aliases</a> for existing paths rather than redirects.');

    $form = [];
    ys_core_redirect_source_warning_replace($form, new FormState());

    $warnings = array_map('strval', $messenger->messagesByType(MessengerInterface::TYPE_WARNING));
    $this->assertContains('Unrelated warning.', $warnings);
    $this->assertContains("This path already belongs to a page on your site. To change its address, edit the URL alias in that page's settings.", $warnings);
    $this->assertCount(2, $warnings);
    foreach ($warnings as $warning) {
      $this->assertStringNotContainsString('<a ', $warning);
      $this->assertStringNotContainsString('create URL aliases', $warning);
    }
    $this->assertCount(1, $messenger->messagesByType(MessengerInterface::TYPE_STATUS));
  }

  /**
   * Without the contrib warning, messages are untouched.
   */
  public function testNoWarningLeavesMessagesAlone(): void {
    $messenger = \Drupal::messenger();
    $messenger->addWarning('Unrelated warning.');

    $form = [];
    ys_core_redirect_source_warning_replace($form, new FormState());

    $this->assertCount(1, $messenger->messagesByType(MessengerInterface::TYPE_WARNING));
  }

}
