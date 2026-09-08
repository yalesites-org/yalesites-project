<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Core\Form\FormState;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_beacon\Form\SystemInstructionsForm;
use Drupal\ys_beacon\Service\MarkdownConverter;
use Drupal\ys_beacon\Service\SystemInstructionsStorage;

/**
 * Tests validation behavior of the Beacon system instructions form.
 *
 * @group ys_beacon
 * @coversDefaultClass \Drupal\ys_beacon\Form\SystemInstructionsForm
 */
class SystemInstructionsFormTest extends UnitTestCase {

  /**
   * Builds a form instance with a mocked Markdown converter.
   *
   * @param string $convertedMarkdown
   *   The value the Markdown converter should return for any HTML input.
   */
  private function createForm(string $convertedMarkdown): SystemInstructionsForm {
    $markdownConverter = $this->createMock(MarkdownConverter::class);
    $markdownConverter->method('toMarkdown')->willReturn($convertedMarkdown);

    $storage = $this->createMock(SystemInstructionsStorage::class);

    $form = new SystemInstructionsForm($storage, $markdownConverter);
    $form->setStringTranslation($this->getStringTranslationStub());

    return $form;
  }

  /**
   * Instructions longer than the recommended length still validate cleanly.
   *
   * The 4,000-character length is a recommendation, not a hard requirement:
   * editors must be able to save instructions that run over it.
   *
   * @covers ::validateForm
   */
  public function testValidateFormAllowsInstructionsOverRecommendedLength(): void {
    $longInstructions = str_repeat('a', 4780);
    $form = $this->createForm($longInstructions);

    $formArray = [];
    $formState = new FormState();
    $formState->setValue('instructions', [
      'value' => '<p>anything</p>',
      'format' => 'restricted_html',
    ]);

    $form->validateForm($formArray, $formState);

    $this->assertSame([], $formState->getErrors());
  }

  /**
   * Whitespace/markup-only input is still rejected as empty.
   *
   * @covers ::validateForm
   */
  public function testValidateFormRejectsMarkupOnlyInput(): void {
    $form = $this->createForm('');

    $formArray = [];
    $formState = new FormState();
    $formState->setValue('instructions', [
      'value' => '<p></p>',
      'format' => 'restricted_html',
    ]);

    $form->validateForm($formArray, $formState);

    $this->assertArrayHasKey('instructions', $formState->getErrors());
  }

}
