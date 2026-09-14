<?php

namespace Drupal\Tests\ys_core\Unit;

use Drupal\Core\Form\FormState;
use Drupal\Tests\UnitTestCase;

/**
 * Tests that Layout Builder block forms render their own validation messages.
 *
 * Regression test for a rejected value leaving the editor with no explanation.
 * The Layout Builder configure-block submit button carries no #ajax, so a
 * server-side validation failure re-renders the form as a standalone Layout
 * Builder page, and Gin's page--layout-builder.html.twig never prints the
 * highlighted region that holds the site's messages block. The error text ("The
 * path 'hxxp://example.com' is invalid.") therefore stayed in the messenger
 * queue and surfaced later on an unrelated page load, leaving only a
 * red-outlined field with no visible text — which also gives screen reader
 * users no error at all.
 *
 * The example is deliberately "hxxp" rather than "htp": a scheme one edit from
 * http is now auto-corrected by _ys_core_normalize_bare_domain_uri(), so it no
 * longer reaches validation at all. Two edits still does.
 *
 * Placing a status_messages element in the form itself makes the message render
 * wherever the form is rendered, in the dialog or as a standalone page.
 *
 * This is a Unit test rather than a kernel test because every branch
 * ys_core_form_alter() takes for these inputs is plain array manipulation:
 * ys_core_get_block_type() reads the plugin id out of the build info and
 * only reaches the entity type manager for a 36-character UUID block type,
 * and _ys_core_disable_event_fields() and _ys_core_attach_icon_preview()
 * are no-ops for these form ids. Enabling ys_core bought nothing but
 * loading the file the function lives in, which setUp() now does directly.
 *
 * @group ys_core
 * @group yalesites
 */
class LayoutBuilderBlockFormMessagesTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The function under test is procedural, so the file has to be loaded.
    require_once dirname(__DIR__, 3) . '/ys_core.module';
  }

  /**
   * Builds a Layout Builder block form array and its form state.
   *
   * The admin label default must be non-empty: ys_core_form_alter() only
   * reaches into the form object for the current component when it is missing,
   * which a bare form state cannot supply.
   */
  protected function blockForm(string $plugin_id): array {
    $form = [
      'settings' => [
        'label' => ['#default_value' => 'Quick Links'],
        'block_form' => [],
      ],
    ];
    $form_state = new FormState();
    $form_state->setBuildInfo(['args' => [NULL, NULL, NULL, $plugin_id]]);
    return [$form, $form_state];
  }

  /**
   * Both configure-block forms carry a status_messages element.
   */
  public function testConfigureBlockFormCarriesMessagesElement(): void {
    foreach (['layout_builder_add_block', 'layout_builder_update_block'] as $form_id) {
      [$form, $form_state] = $this->blockForm('inline_block:quick_links');

      $this->assertArrayNotHasKey(
        'status_messages',
        $form,
        "The messages element must come from the alter, not the fixture ($form_id)."
      );

      ys_core_form_alter($form, $form_state, $form_id);

      $this->assertArrayHasKey(
        'status_messages',
        $form,
        "A rejected value must be explained inside the block form ($form_id)."
      );
      $this->assertSame('status_messages', $form['status_messages']['#type']);
      // It has to outrank the form's own fields so the editor sees it without
      // scrolling the off-canvas panel.
      $this->assertLessThan(0, $form['status_messages']['#weight']);
    }
  }

  /**
   * Core's own Ajax error element replaces ours instead of joining it.
   *
   * The element has to reuse the key core assigns in
   * AjaxFormHelperTrait::ajaxSubmit(). Two elements would otherwise reduce to
   * the same lazy-builder placeholder, and the renderer substitutes every
   * occurrence of it — so a second element means the editor sees the error
   * twice and a screen reader announces it twice.
   */
  public function testCoreAjaxMessagesElementDoesNotDuplicateOurs(): void {
    [$form, $form_state] = $this->blockForm('inline_block:quick_links');
    ys_core_form_alter($form, $form_state, 'layout_builder_update_block');

    // Exactly what core does when an Ajax submit fails validation.
    $form['status_messages'] = [
      '#type' => 'status_messages',
      '#weight' => -1000,
    ];

    $messages = array_filter(
      $form,
      fn ($element) => is_array($element)
        && ($element['#type'] ?? NULL) === 'status_messages'
    );

    $this->assertCount(
      1,
      $messages,
      'The form must carry one status_messages element, not two.'
    );
  }

  /**
   * The messages element is not added to unrelated forms.
   */
  public function testUnrelatedFormIsNotAltered(): void {
    [$form, $form_state] = $this->blockForm('inline_block:quick_links');

    ys_core_form_alter($form, $form_state, 'user_login_form');

    // The key the alter writes is status_messages; asserting a name that
    // never existed passed no matter what the alter did.
    $this->assertArrayNotHasKey('status_messages', $form);
  }

}
