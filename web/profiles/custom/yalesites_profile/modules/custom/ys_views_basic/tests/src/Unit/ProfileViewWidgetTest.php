<?php

namespace Drupal\Tests\ys_views_basic\Unit;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
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
   * The profile-only directory mode resolves and disables the thumbnail.
   *
   * @covers \Drupal\ys_views_basic\Plugin\Field\FieldWidget\ViewsBasicWidgetBase::getViewMode
   */
  public function testDirectoryMode() {
    $this->assertSame('directory', $this->invoke($this->widget('profile_directory'), 'getViewMode'));
    $this->assertFalse(ViewsBasicManager::bundleSupportsThumbnail('profile_directory'));
    $this->assertTrue(ViewsBasicManager::bundleSupportsThumbnail('profile_card'));
  }

}
