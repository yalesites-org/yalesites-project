<?php

namespace Drupal\Tests\ys_views_wizard\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\GeneratedUrl;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\layout_builder\SectionStorageInterface;
use Drupal\ys_views_wizard\Form\ViewsWizardForm;
use Drupal\ys_views_wizard\ViewsWizardOptions;

/**
 * Tests the dialog title the wizard opens the configure form under.
 *
 * Every other add-block dialog reads "Create [block name]"
 * (ys_layouts_form_alter() sets it as the form's #title). The wizard opens
 * the configure form itself, so it has to carry that title into the dialog
 * rather than a fixed "Configure block" (YaleSites-Internal#1925).
 *
 * @coversDefaultClass \Drupal\ys_views_wizard\Form\ViewsWizardForm
 *
 * @group yalesites
 */
class ViewsWizardDialogTitleTest extends UnitTestCase {

  /**
   * Runs openConfigureForm() for a configure form with the given #title.
   *
   * @param array $configure_form
   *   What the form builder returns for the add-block form. It carries an
   *   empty #attached because the mocked renderer does not collect assets.
   *
   * @return string
   *   The title of the dialog the response opens.
   */
  protected function dialogTitle(array $configure_form): string {
    $options = $this->createMock(ViewsWizardOptions::class);
    $options->method('resolvePluginId')->willReturn('inline_block:post_card');
    $options->method('getContentTypeOptions')->willReturn(['post' => 'Posts']);
    $options->method('getViewModeOptions')->willReturn(['card' => 'Card Grid']);

    $form_builder = $this->createMock(FormBuilderInterface::class);
    $form_builder->method('getForm')->willReturn($configure_form);

    $url_generator = $this->createMock(UrlGeneratorInterface::class);
    $url_generator->method('generateFromRoute')->willReturn((new GeneratedUrl())->setGeneratedUrl('/layout_builder/add/block'));

    $renderer = $this->createMock(RendererInterface::class);
    $renderer->method('renderRoot')->willReturn('');
    $renderer->method('renderInIsolation')->willReturn('');

    $container = new ContainerBuilder();
    $container->set('ys_views_wizard.options', $options);
    $container->set('form_builder', $form_builder);
    $container->set('url_generator', $url_generator);
    $container->set('renderer', $renderer);
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);

    $section_storage = $this->createMock(SectionStorageInterface::class);
    $section_storage->method('getStorageType')->willReturn('overrides');
    $section_storage->method('getStorageId')->willReturn('node.54');

    $form_state = new FormState();
    $form_state->set('section_storage', $section_storage);
    $form_state->set('delta', 0);
    $form_state->set('region', 'content');
    $form_state->setValues(['entity_types' => 'post', 'view_mode' => 'card']);

    $form = [];
    $response = ViewsWizardForm::create($container)->openConfigureForm($form, $form_state);
    foreach ($response->getCommands() as $command) {
      if ($command['command'] === 'openDialog') {
        return (string) $command['dialogOptions']['title'];
      }
    }
    $this->fail('No dialog was opened.');
  }

  /**
   * The dialog takes the configure form's "Create [block name]" title.
   *
   * @covers ::openConfigureForm
   */
  public function testDialogUsesTheConfigureFormTitle(): void {
    $title = $this->dialogTitle([
      '#title' => 'Create Posts - Card Grid View',
      '#attached' => [],
    ]);
    $this->assertSame('Create Posts - Card Grid View', $title);
  }

  /**
   * Without a resolved block name the dialog keeps the generic title.
   *
   * @covers ::openConfigureForm
   */
  public function testDialogFallsBackToConfigureBlock(): void {
    $this->assertSame('Configure block', $this->dialogTitle(['#attached' => []]));
  }

}
