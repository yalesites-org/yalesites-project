<?php

namespace Drupal\Tests\ys_layouts\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the block create/configure form heading, labels and field order.
 *
 * Covers issue #1642. Three behaviours of the same form:
 *
 * - The heading names the block, and says whether the block is being created
 *   (the add modal) or configured (the off-canvas sidebar).
 * - The redundant "Block description" item is removed.
 * - The block's guidance renders before the Administrative label.
 *
 * The guidance is exercised through the form's #after_build rather than the
 * alter itself; see ys_layouts_lift_block_instructions() for why it cannot be
 * done in the alter, and for the surface-to-form-ID mapping the headings rely
 * on.
 *
 * Follows the procedural-alter pattern of ys_ai's
 * IntegrationSettingsFormAlterTest: the hook is a plain function, so the module
 * file is loaded and the function called against a form array shaped like the
 * real one.
 *
 * @group ys_layouts
 * @group yalesites
 */
class BlockConfigureFormTest extends UnitTestCase {

  /**
   * The guidance copy configured on the Accordion block type.
   */
  const INSTRUCTIONS = 'Accordions are used to hide and display on-page information.';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 3) . '/ys_layouts.module';

    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);
  }

  /**
   * Builds a form array shaped like the real configure-block form.
   *
   * Mirrors the state hook_form_alter() actually sees: BlockPluginTrait has
   * supplied the admin_label item and the label textfield, and block_form is
   * still an unprocessed container.
   *
   * @param string $admin_label
   *   The block's human-readable name, as BlockPluginTrait resolves it from
   *   the plugin definition.
   *
   * @return array
   *   The form array.
   */
  protected function blockForm(string $admin_label = 'Accordion'): array {
    return [
      'settings' => [
        'admin_label' => [
          '#type' => 'item',
          '#title' => 'Block description',
          '#plain_text' => $admin_label,
        ],
        'label' => [
          '#type' => 'textfield',
          '#title' => 'Administrative label',
          '#default_value' => $admin_label,
        ],
        'label_display' => [
          '#type' => 'checkbox',
          '#title' => 'Display title',
        ],
        'block_form' => [
          '#type' => 'container',
          '#process' => [['Drupal\layout_builder\Plugin\Block\InlineBlock', 'processBlockForm']],
        ],
      ],
    ];
  }

  /**
   * Returns the guidance element the entity form display builds in #process.
   *
   * @return array
   *   A field_instructions render element.
   */
  protected function instructionsElement(): array {
    return [
      '#type' => 'container',
      'widget' => [
        0 => [
          'markup' => [
            '#type' => 'processed_text',
            '#text' => self::INSTRUCTIONS,
            '#format' => 'basic_html',
          ],
        ],
      ],
    ];
  }

  /**
   * Runs the alter hook over a form.
   *
   * @param array $form
   *   The form array.
   * @param string $form_id
   *   The form ID.
   *
   * @return array
   *   The altered form.
   */
  protected function alter(array $form, string $form_id): array {
    $form_state = $this->createMock(FormStateInterface::class);
    ys_layouts_form_alter($form, $form_state, $form_id);
    return $form;
  }

  /**
   * Runs the #after_build callbacks the alter registered.
   *
   * @param array $form
   *   The altered form.
   *
   * @return array
   *   The built form.
   */
  protected function afterBuild(array $form): array {
    $form_state = $this->createMock(FormStateInterface::class);
    foreach ($form['#after_build'] ?? [] as $callback) {
      $form = call_user_func($callback, $form, $form_state);
    }
    return $form;
  }

  /**
   * Returns the children of an element ordered the way it will render.
   *
   * @param array $element
   *   The render element.
   *
   * @return array
   *   The child keys, in weight order.
   */
  protected function renderOrder(array $element): array {
    return Element::children($element, TRUE);
  }

  /**
   * The modal used to create a block is headed "Create [Block Name]".
   */
  public function testAddBlockFormIsHeadedCreateAndNamesTheBlock(): void {
    $form = $this->alter($this->blockForm(), 'layout_builder_add_block');

    $this->assertSame(
      'Create Accordion',
      (string) $form['#title'],
      'The modal creates a new block, so its heading must say Create and name the block.'
    );

    // A second name, so the block cannot be hardcoded into the heading.
    $form = $this->alter($this->blockForm('Views: Recent posts'), 'layout_builder_add_block');
    $this->assertSame('Create Views: Recent posts', (string) $form['#title']);
  }

  /**
   * The off-canvas sidebar for an existing block is headed "Configure [Name]".
   */
  public function testUpdateBlockFormIsHeadedConfigureAndNamesTheBlock(): void {
    $form = $this->alter($this->blockForm(), 'layout_builder_update_block');

    $this->assertSame(
      'Configure Accordion',
      (string) $form['#title'],
      'The sidebar configures a block that already exists, so its heading must say Configure.'
    );
  }

  /**
   * The heading copes with an admin_label that is not a plain string.
   *
   * BlockPluginTrait copies $definition['admin_label'] into #plain_text
   * verbatim, and for an annotated or attributed plugin that is a
   * TranslatableMarkup rather than a string - so both the emptiness check and
   * the placeholder substitution have to be object-safe.
   */
  public function testHeadingAcceptsTranslatableAdminLabel(): void {
    $form = $this->blockForm();
    $form['settings']['admin_label']['#plain_text'] = new TranslatableMarkup(
      'Page Meta Block',
      [],
      [],
      $this->getStringTranslationStub()
    );

    $form = $this->alter($form, 'layout_builder_update_block');

    $this->assertSame('Configure Page Meta Block', (string) $form['#title']);
  }

  /**
   * A block with no resolvable name keeps core's generic heading.
   */
  public function testMissingAdminLabelLeavesTheTitleAlone(): void {
    $form = $this->blockForm();
    unset($form['settings']['admin_label']);

    $form = $this->alter($form, 'layout_builder_add_block');

    $this->assertArrayNotHasKey(
      '#title',
      $form,
      'With no name to substitute, the route title must stand rather than a half-built heading.'
    );
  }

  /**
   * The redundant "Block description" item is gone from both forms.
   */
  public function testRedundantBlockDescriptionItemIsRemoved(): void {
    foreach (['layout_builder_add_block', 'layout_builder_update_block'] as $form_id) {
      $form = $this->alter($this->blockForm(), $form_id);

      $this->assertArrayNotHasKey(
        'admin_label',
        $form['settings'],
        "The item only restates the name the heading now carries ($form_id)."
      );
    }
  }

  /**
   * Guidance renders before the Administrative label it introduces.
   */
  public function testInstructionsRenderBeforeTheAdministrativeLabel(): void {
    $form = $this->alter($this->blockForm(), 'layout_builder_add_block');

    // Only now does the entity form display exist, as it does in a real build.
    $form['settings']['block_form']['field_instructions'] = $this->instructionsElement();
    $this->assertNotContains(
      'field_instructions',
      $this->renderOrder($form['settings']),
      'The fixture must start with the guidance buried in the embedded entity form.'
    );

    $form = $this->afterBuild($form);

    $order = $this->renderOrder($form['settings']);
    $this->assertContains('field_instructions', $order, 'The guidance must be lifted into the settings form.');
    $this->assertLessThan(
      array_search('label', $order, TRUE),
      array_search('field_instructions', $order, TRUE),
      'Guidance is only guidance if it comes before the fields it describes.'
    );

    $this->assertArrayNotHasKey(
      'field_instructions',
      $form['settings']['block_form'],
      'The guidance must be moved, not copied - two copies would render it twice.'
    );
    $this->assertSame(
      self::INSTRUCTIONS,
      $form['settings']['field_instructions']['widget'][0]['markup']['#text'],
      'The moved element must be the real one, with its configured copy intact.'
    );
  }

  /**
   * The lift survives the sorting that already happened during #process.
   *
   * Element::children() refuses to re-sort an element flagged #sorted, so a
   * weight set afterwards would be silently ignored.
   */
  public function testLiftSurvivesAlreadySortedSettings(): void {
    $form = $this->alter($this->blockForm(), 'layout_builder_add_block');
    $form['settings']['block_form']['field_instructions'] = $this->instructionsElement();
    $form['settings']['#sorted'] = TRUE;

    $form = $this->afterBuild($form);

    $order = $this->renderOrder($form['settings']);
    $this->assertLessThan(
      array_search('label', $order, TRUE),
      array_search('field_instructions', $order, TRUE),
      'A stale #sorted flag must not strand the guidance at the bottom.'
    );
  }

  /**
   * Running the callback twice does not move the guidance twice.
   *
   * Core guards #after_build with #after_build_done, so this should not happen
   * in practice - but the callback is cheap to make idempotent and a stray
   * second run would otherwise be free to corrupt the form.
   */
  public function testLiftIsIdempotent(): void {
    $form = $this->alter($this->blockForm(), 'layout_builder_add_block');
    $form['settings']['block_form']['field_instructions'] = $this->instructionsElement();

    $once = $this->afterBuild($form);
    $twice = $this->afterBuild($once);

    $this->assertSame($once, $twice, 'A second run must be a no-op.');
  }

  /**
   * A block type with no guidance field is left alone.
   */
  public function testBlockTypeWithoutInstructionsIsUnchanged(): void {
    $form = $this->afterBuild($this->alter($this->blockForm(), 'layout_builder_add_block'));

    $this->assertArrayNotHasKey('field_instructions', $form['settings']);
  }

  /**
   * A reusable placement keeps its guidance lifted too.
   *
   * Reusable block_content placements build the entity subform at the top
   * level rather than under settings.
   */
  public function testReusablePlacementGuidanceIsAlsoLifted(): void {
    $form = $this->alter($this->blockForm(), 'layout_builder_update_block');
    unset($form['settings']['block_form']);
    $form['block_form']['field_instructions'] = $this->instructionsElement();

    $form = $this->afterBuild($form);

    $order = $this->renderOrder($form['settings']);
    $this->assertLessThan(
      array_search('label', $order, TRUE),
      array_search('field_instructions', $order, TRUE)
    );
    $this->assertArrayNotHasKey('field_instructions', $form['block_form']);
  }

  /**
   * The existing reusable-block relabeling still applies.
   *
   * Regression guard for the behaviour ys_layouts_form_alter() already had.
   */
  public function testReusableBlockRelabelingIsUnaffected(): void {
    $form = $this->blockForm();
    $form['reusable'] = ['#type' => 'checkbox', '#title' => 'Reusable'];
    $form['info'] = ['#type' => 'textfield', '#title' => 'Block description'];

    $form = $this->alter($form, 'layout_builder_update_block');

    $this->assertSame('Reusable Block', (string) $form['reusable']['#title']);
    $this->assertSame('Reusable Block title', (string) $form['info']['#title']);
    $this->assertSame('Configure Accordion', (string) $form['#title']);
  }

  /**
   * Unrelated forms are not touched.
   */
  public function testUnrelatedFormIsNotAltered(): void {
    $form = $this->blockForm();

    $this->assertSame(
      $form,
      $this->alter($form, 'node_page_edit_form'),
      'Only the two configure-block forms are in scope.'
    );
  }

}
