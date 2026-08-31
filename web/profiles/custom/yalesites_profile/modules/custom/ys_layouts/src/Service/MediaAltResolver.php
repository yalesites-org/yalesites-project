<?php

namespace Drupal\ys_layouts\Service;

use Drupal\media\MediaInterface;

/**
 * Resolves the alt text for a Resource media entity's cover/thumbnail image.
 *
 * The resource detail page (ResourceMetaBlock) and the resource card/list
 * templates (atomic_preprocess_node) both hand-build a responsive_image render
 * array from the media's thumbnail derivative. This service centralizes how
 * their alt text is derived so both surfaces stay identical.
 *
 * For image media the alt configured on the image field is used, preserving an
 * empty value so an image marked decorative renders with alt="". Media bundles
 * without an image field (documents, etc.) fall back to the media label so the
 * auto-generated thumbnail still has a meaningful description.
 */
class MediaAltResolver {

  /**
   * Resolves the alt text for a media entity's cover image.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media entity whose thumbnail is being rendered.
   *
   * @return string
   *   The alt text to apply to the rendered image.
   */
  public function resolve(MediaInterface $media): string {
    if ($media->hasField('field_media_image') && !$media->get('field_media_image')->isEmpty()) {
      // Image media: use the alt configured on the image field. An empty value
      // is preserved so an image marked decorative renders with alt="".
      return (string) $media->get('field_media_image')->alt;
    }

    // Bundles without an image field (documents, etc.) have no configured alt;
    // fall back to the media label so the thumbnail is still described.
    return (string) $media->label();
  }

}
