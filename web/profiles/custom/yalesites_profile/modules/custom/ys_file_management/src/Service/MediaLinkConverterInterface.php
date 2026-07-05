<?php

namespace Drupal\ys_file_management\Service;

/**
 * Converts legacy "media" links in rich text into direct file links.
 *
 * Issue #835 removed the media matcher from the Linkit profile because a media
 * link to a document resolves to the media entity's canonical route, which is
 * not accessible to site visitors. Content authored before that change still
 * carries `<a data-entity-type="media" …>` markup, so this service rewrites
 * those anchors to point at the referenced media's underlying file — the same
 * accessible target the file matcher produces.
 */
interface MediaLinkConverterInterface {

  /**
   * Rewrites media links to file links across all content entities.
   *
   * @return array
   *   Stats with keys: 'entities_updated', 'links_converted', 'links_skipped'.
   */
  public function convertAllContent(): array;

  /**
   * Rewrites the media links found in a single HTML string.
   *
   * @param string $html
   *   The stored field markup.
   *
   * @return array
   *   Result with keys: 'html' (the possibly-rewritten markup), 'converted'
   *   (links pointed at a file) and 'skipped' (media links left as-is because
   *   the media or its file could not be resolved).
   */
  public function convertMarkup(string $html): array;

}
