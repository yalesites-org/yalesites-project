<?php

namespace Drupal\Tests\ys_core\Kernel;

/**
 * Renders a build array and parses the markup for DOM assertions.
 *
 * For kernel tests that assert on rendered markup. Use it from a
 * KernelTestBase subclass: it reads the renderer from $this->container.
 */
trait RendersToDomTrait {

  /**
   * Renders a build array and returns an XPath query over its markup.
   *
   * @param array $build
   *   The render array. Taken by value, so the caller's copy is untouched by
   *   renderInIsolation(), which works on its argument by reference.
   *
   * @return \DOMXPath
   *   An XPath object over the parsed markup; its document property is the
   *   underlying \DOMDocument.
   */
  protected function renderAndQuery(array $build): \DOMXPath {
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);

    $document = new \DOMDocument();
    // Without the XML encoding declaration, loadHTML() reads the markup as
    // ISO-8859-1 and mangles any multibyte text. The flags silence libxml's
    // complaints about fragments and HTML5 elements, which no caller asserts
    // on.
    $document->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);

    return new \DOMXPath($document);
  }

}
