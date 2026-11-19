<?php

namespace Drupal\Tests\ys_embed\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * Checks the markup of the embed wrapper template file.
 *
 * @group yalesites
 * @group ys_embed
 */
class EmbedWrapperTemplateTest extends UnitTestCase {

  /**
   * The iframe width attribute is closed before the scrolling attribute.
   */
  public function testIframeWidthAttributeIsClosed(): void {
    $template = file_get_contents(dirname(__DIR__, 3) . '/templates/embed-wrapper.html.twig');
    $this->assertNotFalse($template);
    $this->assertMatchesRegularExpression(
      '/<iframe[^\n]*width="\{\{ displayAttributes\[\'width\'\] \}\}" scrolling=/',
      $template
    );
  }

}
