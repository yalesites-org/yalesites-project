<?php

namespace Drupal\Tests\ys_views_basic\Unit;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_views_basic\Plugin\Field\FieldWidget\ProfileViewWidget;
use Drupal\ys_views_basic\ViewsBasicManager;

/**
 * Tests ProfileViewWidget (#1167): affiliations label and directory mode.
 *
 * @coversDefaultClass \Drupal\ys_views_basic\Plugin\Field\FieldWidget\ProfileViewWidget
 *
 * @group yalesites
 */
class ProfileViewWidgetTest extends UnitTestCase {

  /**
   * Builds a ProfileViewWidget bound to the given bundle.
   */
  private function widget(string $bundle): ProfileViewWidget {
    $field_definition = $this->createMock(FieldDefinitionInterface::class);
    $field_definition->method('getTargetBundle')->willReturn($bundle);
    $widget = new ProfileViewWidget(
      'profile_view_widget',
      [],
      $field_definition,
      [],
      [],
      $this->createMock(ViewsBasicManager::class),
      $this->createMock(EntityTypeManagerInterface::class),
      $this->getConfigFactoryStub(['ys_core.site' => ['font_pairing' => 'yalenew']]),
    );
    $widget->setStringTranslation($this->getStringTranslationStub());
    return $widget;
  }

  /**
   * Invokes a protected method on the widget.
   */
  private function invoke(object $object, string $method, array $args = []) {
    $ref = new \ReflectionMethod($object, $method);
    $ref->setAccessible(TRUE);
    return $ref->invokeArgs($object, $args);
  }

  /**
   * The widget reports the profile content type.
   *
   * @covers ::getContentType
   */
  public function testGetContentType() {
    $this->assertSame('profile', $this->invoke($this->widget('profile_card'), 'getContentType'));
  }

  /**
   * The category control is labelled "Show Affiliations".
   *
   * @covers ::buildCategoryLabel
   */
  public function testAffiliationsLabel() {
    $this->assertSame('Show Affiliations', (string) $this->invoke($this->widget('profile_card'), 'buildCategoryLabel'));
  }

  /**
   * The category vocabulary resolves to "affiliation" for profiles.
   *
   * @covers \Drupal\ys_views_basic\Plugin\Field\FieldWidget\ViewsBasicWidgetBase::getCategoryVocabulary
   */
  public function testAffiliationVocabulary() {
    $this->assertSame('affiliation', $this->invoke($this->widget('profile_card'), 'getCategoryVocabulary'));
  }

  /**
   * Profiles scope their tag selects to affiliation/tags/audience/custom_vocab.
   *
   * @covers \Drupal\ys_views_basic\Plugin\Field\FieldWidget\ViewsBasicWidgetBase::getTagVocabularies
   */
  public function testTagVocabulariesScopedToAffiliation() {
    $this->assertSame(
      ['affiliation', 'tags', 'audience', 'custom_vocab'],
      $this->invoke($this->widget('profile_card'), 'getTagVocabularies')
    );
  }

  /**
   * The profile-only directory mode resolves and disables the thumbnail.
   *
   * @covers \Drupal\ys_views_basic\Plugin\Field\FieldWidget\ViewsBasicWidgetBase::getViewMode
   */
  public function testDirectoryMode() {
    $this->assertSame('directory', $this->invoke($this->widget('profile_directory'), 'getViewMode'));
    $this->assertFalse(ViewsBasicManager::bundleSupportsThumbnail('profile_directory'));
    $this->assertTrue(ViewsBasicManager::bundleSupportsThumbnail('profile_card'));
  }

  /**
   * The profile data checkboxes are offered on every profile listing (#1648).
   *
   * Department, Email, Phone and Pronouns used to be available only through
   * the one-off directory card. They are plain checkboxes on the profile
   * widget so any profile listing can show them, whichever design option it
   * uses.
   *
   * @covers ::buildEntitySpecificOptions
   */
  public function testBuildEntitySpecificOptionsAddsProfileFields() {
    $item = (object) ['params' => NULL];
    $items = $this->createMock(FieldItemListInterface::class);
    $items->method('offsetGet')->willReturn($item);

    $form = [];
    $this->invoke($this->widget('profile_card'), 'buildEntitySpecificOptions', [&$form, $items, 0]);

    $element = $form['group_user_selection']['entity_and_view_mode']['profile_field_options'] ?? NULL;
    $this->assertIsArray($element, 'profile_field_options element is added.');
    $this->assertSame(
      ['show_department', 'show_email', 'show_phone', 'show_pronouns'],
      array_keys($element['#options'])
    );
    // Plain language, no "directory" terminology now that these are general
    // purpose (#1648 acceptance criteria).
    $this->assertSame('Show Department', (string) $element['#options']['show_department']);
    $this->assertSame('Show Email', (string) $element['#options']['show_email']);
    $this->assertSame('Show Phone', (string) $element['#options']['show_phone']);
    $this->assertSame('Show Pronouns', (string) $element['#options']['show_pronouns']);
    // Only this widget serves profiles, so the options need no #states gating
    // to stay off other content types' forms.
    $this->assertArrayNotHasKey('#states', $element);
  }

  /**
   * The checkboxes appear only where something renders them (#1648).
   *
   * Card and list both embed the shared reference card, which honours the
   * flags. The directory mode renders the separate directory-listing card,
   * which shows department/email/phone unconditionally and would ignore them,
   * and condensed renders none of these fields — offering controls there would
   * be clutter that does nothing.
   *
   * @covers ::buildEntitySpecificOptions
   */
  public function testProfileFieldOptionsScopedToSupportingViewModes() {
    $item = (object) ['params' => NULL];
    $items = $this->createMock(FieldItemListInterface::class);
    $items->method('offsetGet')->willReturn($item);

    foreach (['profile_card', 'profile_list_item'] as $bundle) {
      $form = [];
      $this->invoke($this->widget($bundle), 'buildEntitySpecificOptions', [&$form, $items, 0]);
      $this->assertArrayHasKey(
        'profile_field_options',
        $form['group_user_selection']['entity_and_view_mode'],
        "$bundle offers the profile data options"
      );
    }

    foreach (['profile_directory', 'profile_condensed'] as $bundle) {
      $form = [];
      $this->invoke($this->widget($bundle), 'buildEntitySpecificOptions', [&$form, $items, 0]);
      $this->assertSame([], $form, "$bundle adds no profile data options");
    }
  }

  /**
   * The save path injects profile_field_options into the stored params.
   *
   * @covers ::massageEntitySpecificParams
   */
  public function testMassageEntitySpecificParams() {
    $form = [
      'group_user_selection' => [
        'entity_and_view_mode' => [
          'profile_field_options' => [
            '#value' => [
              'show_department' => 'show_department',
              'show_email' => 'show_email',
            ],
          ],
        ],
      ],
    ];
    $param_data = [];
    $form_state = $this->createMock(FormStateInterface::class);
    $this->invoke($this->widget('profile_card'), 'massageEntitySpecificParams', [&$param_data, $form, $form_state]);

    $this->assertSame(
      ['show_department' => 'show_department', 'show_email' => 'show_email'],
      $param_data['profile_field_options']
    );
  }

  /**
   * An unsaved profile listing stores an empty option set, never NULL.
   *
   * @covers ::massageEntitySpecificParams
   */
  public function testMassageEntitySpecificParamsDefaultsToEmptyArray() {
    $param_data = [];
    $form_state = $this->createMock(FormStateInterface::class);
    $this->invoke($this->widget('profile_card'), 'massageEntitySpecificParams', [&$param_data, [], $form_state]);

    $this->assertSame([], $param_data['profile_field_options']);
  }

}
