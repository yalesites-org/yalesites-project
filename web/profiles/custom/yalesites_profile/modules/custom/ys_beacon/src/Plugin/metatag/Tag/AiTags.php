<?php

namespace Drupal\ys_beacon\Plugin\metatag\Tag;

use Drupal\metatag\Plugin\metatag\Tag\MetaNameBase;

/**
 * A tag to output a collection of tags for AI ingestion.
 *
 * The plugin ID intentionally matches the legacy ai_engine_metadata tag so
 * that values already stored in entity field_metatags data keep working
 * without migration. While both modules are installed, ys_beacon's
 * definition wins plugin discovery; the implementations are equivalent.
 *
 * @MetatagTag(
 *   id = "ai_tags",
 *   label = @Translation("AI Tags"),
 *   description = @Translation("Additional tags to ingest into the AI model for this page."),
 *   name = "ai_tags",
 *   group = "ys_beacon",
 *   weight = 4,
 *   type = "label",
 *   long = FALSE,
 *   secure = FALSE,
 *   multiple = FALSE
 * )
 */
class AiTags extends MetaNameBase {

}
