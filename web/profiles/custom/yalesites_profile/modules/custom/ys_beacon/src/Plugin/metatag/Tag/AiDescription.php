<?php

namespace Drupal\ys_beacon\Plugin\metatag\Tag;

use Drupal\metatag\Plugin\metatag\Tag\MetaNameBase;

/**
 * A tag to output a description for AI ingestion.
 *
 * The plugin ID intentionally matches the legacy ai_engine_metadata tag so
 * that values already stored in entity field_metatags data keep working
 * without migration. While both modules are installed, ys_beacon's
 * definition wins plugin discovery; the implementations are equivalent.
 *
 * @MetatagTag(
 *   id = "ai_description",
 *   label = @Translation("AI Description"),
 *   description = @Translation("Additional content to ingest into the AI model for this page."),
 *   name = "ai_description",
 *   group = "ys_beacon",
 *   weight = 4,
 *   type = "label",
 *   long = TRUE,
 *   secure = FALSE,
 *   multiple = FALSE
 * )
 */
class AiDescription extends MetaNameBase {

}
