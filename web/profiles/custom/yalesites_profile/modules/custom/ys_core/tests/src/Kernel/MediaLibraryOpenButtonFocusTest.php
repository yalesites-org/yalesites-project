<?php

namespace Drupal\Tests\ys_core\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\media\Entity\Media;

/**
 * Tests the media_library element does not steal focus on a plain page load.
 *
 * Site Settings (/admin/yalesites/settings) loaded scrolled ~1900px down,
 * landing on the Fallback teaser image field, because the "Add media" button
 * was rendered both visually-hidden and flagged data-disabled-focus="true" on
 * every build once the element was at cardinality. The contrib JS focuses any
 * .js-media-library-open-button[data-disabled-focus="true"] on behavior
 * attach, and focusing an offscreen element makes the browser scroll it into
 * view. Nothing scrolled the page; the focus did.
 *
 * Drupal core's own MediaLibraryWidget only sets that attribute when the
 * widget is being rebuilt from a selection change, precisely so a fresh GET
 * does not move focus. The contrib form element copied core's code without
 * that guard. We patch the guard back in, so this asserts both halves of
 * core's behaviour: no focus flag on a plain build, and the flag still present
 * on a selection change so focus returns to the button for keyboard and screen
 * reader users.
 *
 * This covers every ys_core form placing a '#type' => 'media_library'
 * element -- SiteSettingsForm, HeaderSettingsForm and FooterSettingsForm --
 * all of which use the element at its default cardinality of 1.
 *
 * @see \Drupal\media_library_form_element\Element\MediaLibrary
 * @see https://github.com/yalesites-org/YaleSites-Internal/issues/1589
 *
 * @group ys_core
 * @group yalesites
 */
class MediaLibraryOpenButtonFocusTest extends KernelTestBase {

  use MediaTypeCreationTrait;
  use UserCreationTrait;

  /**
   * The form element key core checks for, and the attribute it gates.
   */
  private const UPDATE_TRIGGER = 'media_library_update_widget';
  private const FOCUS_ATTRIBUTE = 'data-disabled-focus';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'image',
    'filter',
    'views',
    'media',
    'media_library',
    'media_library_form_element',
    'ys_core_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['field', 'system', 'image', 'media', 'media_library']);

    $this->createMediaType('image', ['id' => 'image']);

    // The form is only ever reached by a user who can already administer these
    // settings; media previews are access checked as they render.
    $this->setUpCurrentUser([], ['view media', 'access content'], TRUE);

    $first = $this->createImage('Fallback teaser image');
    $second = $this->createImage('Second image');

    $this->config('ys_core_test.media_library.settings')
      ->set('single', $first)
      ->set('multiple_partial', $first)
      ->set('multiple_full', "$first,$second")
      ->set('unlimited', $first)
      ->save();
  }

  /**
   * A plain build must not flag the button for focus, even when full.
   *
   * This is the regression: 'single' holds one item at cardinality 1, so it is
   * full, which is exactly when the unguarded contrib code set the flag. The
   * button must end up disabled server-side and still hidden, so the fix is
   * neither "focus it anyway" nor "just show it".
   */
  public function testPlainBuildDoesNotFlagOpenButtonForFocus(): void {
    $button = $this->buildOpenButton('single');

    $this->assertArrayNotHasKey(self::FOCUS_ATTRIBUTE, $button['#attributes'], 'A plain build of a full media_library element must not ask the browser to focus the hidden open button.');
    $this->assertSame(TRUE, $button['#disabled'] ?? NULL, 'A full media_library element should disable its open button server-side instead of relying on JS to focus then disable it.');
    $this->assertContains('visually-hidden', $button['#attributes']['class']);
  }

  /**
   * A multi-value element that is full behaves the same as a single one.
   *
   * Guards the arithmetic as well as the guard: 'remaining' reaching zero is
   * what matters, not the cardinality it counted down from.
   */
  public function testFullMultiValueElementIsDisabledNotFlagged(): void {
    $button = $this->buildOpenButton('multiple_full');

    $this->assertArrayNotHasKey(self::FOCUS_ATTRIBUTE, $button['#attributes']);
    $this->assertSame(TRUE, $button['#disabled'] ?? NULL);
    $this->assertContains('visually-hidden', $button['#attributes']['class']);
  }

  /**
   * A partly filled element keeps its open button usable and unflagged.
   */
  public function testPartlyFilledElementLeavesOpenButtonEnabled(): void {
    $button = $this->buildOpenButton('multiple_partial');

    $this->assertArrayNotHasKey(self::FOCUS_ATTRIBUTE, $button['#attributes']);
    $this->assertArrayNotHasKey('#disabled', $button, 'One of two items used, so more may still be added.');
    $this->assertNotContains('visually-hidden', $button['#attributes']['class']);
  }

  /**
   * An unlimited element is never full, so it is never flagged.
   */
  public function testUnlimitedElementNeverFlagsOpenButton(): void {
    $button = $this->buildOpenButton('unlimited');

    $this->assertArrayNotHasKey(self::FOCUS_ATTRIBUTE, $button['#attributes']);
    $this->assertArrayNotHasKey('#disabled', $button);
  }

  /**
   * A selection change must still return focus to the open button.
   *
   * The focus flag exists for a real accessibility reason: after the media
   * library modal closes, keyboard and screen reader users need focus back on
   * the control they left from. Suppressing it on a plain load must not
   * suppress it here.
   */
  public function testSelectionChangeStillFlagsOpenButtonForFocus(): void {
    $button = $this->buildOpenButton('single', ['single', self::UPDATE_TRIGGER]);

    $this->assertSame('true', $button['#attributes'][self::FOCUS_ATTRIBUTE], 'After a selection change the button must still be flagged so focus returns to it.');
    $this->assertContains('visually-hidden', $button['#attributes']['class']);
  }

  /**
   * Recognising the trigger must not depend on how deeply it is nested.
   *
   * FooterSettingsForm nests media_library elements inside a multivalue
   * wrapper, so the triggering element's '#array_parents' is several levels
   * deeper than on Site Settings. The guard inspects only the last parent, and
   * this pins that: swapping it for a fixed index would break the footer form
   * while Site Settings kept working.
   */
  public function testDeeplyNestedSelectionChangeIsStillRecognised(): void {
    $parents = ['footer_logos', 'logos', 0, 'logo', self::UPDATE_TRIGGER];
    $button = $this->buildOpenButton('single', $parents);

    $this->assertSame('true', $button['#attributes'][self::FOCUS_ATTRIBUTE]);
  }

  /**
   * A removal is not a selection change, so it must not flag the button.
   */
  public function testUnrelatedTriggerDoesNotFlagOpenButton(): void {
    $button = $this->buildOpenButton('single', ['single', 'selection', 0, 'preview', 'remove_button']);

    $this->assertArrayNotHasKey(self::FOCUS_ATTRIBUTE, $button['#attributes']);
  }

  /**
   * Creates a saved image media item and returns its ID.
   *
   * @param string $name
   *   The media item name.
   *
   * @return string
   *   The media ID, as the element takes its default value as a string.
   */
  protected function createImage(string $name): string {
    $media = Media::create(['bundle' => 'image', 'name' => $name]);
    $media->save();

    return (string) $media->id();
  }

  /**
   * Builds the test form and returns one element's open button.
   *
   * @param string $element
   *   The media_library element key on the test form.
   * @param array|null $trigger_parents
   *   The '#array_parents' of the element to present as the triggering
   *   element, or NULL for a plain build with no triggering element.
   *
   * @return array
   *   The open button render array.
   */
  protected function buildOpenButton(string $element, ?array $trigger_parents = NULL): array {
    $form_state = new FormState();
    if ($trigger_parents !== NULL) {
      $form_state->setTriggeringElement(['#array_parents' => $trigger_parents]);
    }

    $form = $this->container->get('form_builder')
      ->buildForm('Drupal\ys_core_test\Form\MediaLibraryTestForm', $form_state);

    $this->assertArrayHasKey('media_library_open_button', $form[$element], "The $element element should have been processed into a media_library widget.");

    return $form[$element]['media_library_open_button'];
  }

}
