<?php

namespace Drupal\Tests\ys_core\Kernel;

use Drupal\Core\Form\FormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests the drupal selector applied to standalone linkit form elements.
 *
 * Linkit's autocomplete JavaScript refuses to act on any input whose
 * data-drupal-selector does not end in "-uri", so a "#type => linkit" element
 * outside a link field widget can never have a suggestion selected.
 *
 * The test doubles as its own form, following the core convention for kernel
 * tests that need the form builder.
 *
 * @see \Drupal\KernelTests\Core\Form\ExternalFormUrlTest
 *
 * @group yalesites
 * @group ys_core
 */
class LinkitUriSelectorTest extends KernelTestBase implements FormInterface {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'linkit',
    'ys_core',
  ];

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ys_core_linkit_uri_selector_test_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['link_url'] = [
      '#type' => 'linkit',
      '#title' => 'URL',
    ];
    $form['uri'] = [
      '#type' => 'linkit',
      '#title' => 'URI',
    ];
    $form['plain_text'] = [
      '#type' => 'textfield',
      '#title' => 'Plain text',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
  }

  /**
   * Builds the test form through the form builder.
   *
   * The form builder is what sets data-drupal-selector, so the element cannot
   * be inspected by calling buildForm() directly.
   *
   * @return array
   *   The built form render array.
   */
  protected function buildTestForm(): array {
    return $this->container->get('form_builder')->getForm($this);
  }

  /**
   * Tests that a linkit element's selector is suffixed so linkit's JS acts.
   */
  public function testLinkitSelectorEndsInUri() {
    $form = $this->buildTestForm();

    $this->assertStringEndsWith('-uri', $form['link_url']['#attributes']['data-drupal-selector']);
  }

  /**
   * Tests that a selector already ending in "-uri" is left alone.
   */
  public function testSelectorAlreadyEndingInUriIsNotSuffixedTwice() {
    $form = $this->buildTestForm();

    $this->assertSame('edit-uri', $form['uri']['#attributes']['data-drupal-selector']);
  }

  /**
   * Tests that elements other than linkit keep their generated selector.
   */
  public function testNonLinkitElementSelectorIsUnchanged() {
    $form = $this->buildTestForm();

    $this->assertSame('edit-plain-text', $form['plain_text']['#attributes']['data-drupal-selector']);
  }

}
