<?php

namespace Drupal\Tests\ys_views_wizard\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormState;
use Drupal\Tests\UnitTestCase;
use Drupal\layout_builder\SectionStorageInterface;
use Drupal\ys_views_wizard\Form\ViewsWizardForm;
use Drupal\ys_views_wizard\ViewsWizardOptions;

/**
 * Tests the structural classes ViewsWizardForm::buildForm() renders.
 *
 * These are not cosmetic. gin_lb gives the Layout Builder forms it treats a
 * three-part structure - .glb-canvas-form, .glb-canvas-form__settings and
 * .glb-canvas-form__actions - and .glb-canvas-form escapes the dialog's own
 * side padding with -20px margins that only the two inner elements put back.
 * A missing class there is invisible in PHP and shows up as the questions
 * sitting flush against the modal edge (yalesites-org/yalesites-project#1513
 * review). gin_lb keys its own treatment off a hardcoded form-ID list with no
 * alter hook, so this form has to apply all three itself, and nothing else
 * would catch one going missing.
 *
 * @coversDefaultClass \Drupal\ys_views_wizard\Form\ViewsWizardForm
 *
 * @group yalesites
 */
class ViewsWizardFormTest extends UnitTestCase {

  /**
   * The built form array.
   *
   * @var array
   */
  protected $form;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $options = $this->createMock(ViewsWizardOptions::class);
    $options->method('getContentTypeOptions')->willReturn(['post' => 'Posts']);
    $options->method('getViewModeOptions')->willReturn(['card' => 'Post Card Grid']);

    $container = new ContainerBuilder();
    $container->set('ys_views_wizard.options', $options);
    $container->set('form_builder', $this->createMock(FormBuilderInterface::class));
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);

    $section_storage = $this->createMock(SectionStorageInterface::class);
    $section_storage->method('getStorageType')->willReturn('overrides');
    $section_storage->method('getStorageId')->willReturn('node.54');

    $form = [];
    $this->form = ViewsWizardForm::create($container)
      ->buildForm($form, new FormState(), $section_storage, '2', 'sidebar');
  }

  /**
   * The questions carry gin_lb's settings class and the views-basic opt-ins.
   *
   * The canvas-form__settings class is what puts back the 20px side padding
   * .glb-canvas-form's negative margins take away; views-basic--form-scale is
   * what makes the --vb-* spacing scale resolve on a form that is not the
   * block-configure form; views-basic--wizard is the stable hook the
   * wizard-only CSS keys on instead of a generated wrapper ID.
   *
   * @covers ::buildForm
   */
  public function testQuestionsWrapperCarriesTheGinLbSettingsClass(): void {
    $classes = $this->form['wrapper']['#attributes']['class'];

    $this->assertContains('canvas-form__settings', $classes);
    $this->assertContains('views-basic--group-user-selection', $classes);
    $this->assertContains('views-basic--form-scale', $classes);
    $this->assertContains('views-basic--wizard', $classes);
  }

  /**
   * Both stylesheets are attached, by the exact library IDs that carry them.
   *
   * Same failure mode as the classes above, and the same lack of any other
   * net: a renamed or mistyped library ID throws nothing and logs nothing,
   * it just silently attaches no CSS. ys_views_basic/ys_views_basic carries
   * the cards and the selected state; ys_views_wizard/ys_views_wizard carries
   * the two rules that are the whole fix for the questions clearing the modal
   * edge and for the dead strip under the actions bar.
   *
   * @covers ::buildForm
   */
  public function testBothStylesheetsAreAttached(): void {
    $libraries = $this->form['#attached']['library'];

    $this->assertContains('ys_views_basic/ys_views_basic', $libraries);
    $this->assertContains('ys_views_wizard/ys_views_wizard', $libraries);
  }

  /**
   * Back renders as a gin_lb button so it matches Continue's size.
   *
   * Continue is a #type => submit, which gin_lb's input template renders as
   * .glb-button - 48px tall, 16px type. Back is a #type => link, whose
   * classes gin_lb does not rewrite, so without glb-button it rendered as a
   * plain a.button at 41px and 14px type. 'use-ajax' has to survive: it is
   * what makes Back replace the dialog contents instead of navigating.
   *
   * @covers ::buildForm
   */
  public function testBackLinkMatchesContinueButtonSizing(): void {
    $classes = $this->form['actions']['back']['#attributes']['class'];

    $this->assertContains('glb-button', $classes);
    $this->assertContains('use-ajax', $classes);
  }

  /**
   * The actions element stays a plain container, not an 'actions' element.
   *
   * Load bearing, and easy to "tidy" back into #type => actions. An actions
   * element renders div.form-actions, and core's dialog.ajax.js copies every
   * button inside a .form-actions into the jQuery UI button pane and hides
   * the originals with an inline display:none - which gin_lb's
   * `.glb-button { display: inline-block !important }` overrides, leaving two
   * visible Continue buttons.
   *
   * @covers ::buildForm
   */
  public function testActionsRenderAsPlainContainerNotFormActions(): void {
    $this->assertSame('container', $this->form['actions']['#type']);
    $this->assertContains('canvas-form__actions', $this->form['actions']['#attributes']['class']);
  }

}
