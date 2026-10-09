<?php

namespace Drupal\ys_markdown_test\Plugin\metatag\Tag;

use Drupal\ys_beacon\Plugin\metatag\Tag\AiDisableIndexing as BeaconAiDisableIndexing;

/**
 * Ys_beacon's ai_disable_indexing tag, without the AI stack it depends on.
 *
 * @MetatagTag(
 *   id = "ai_disable_indexing",
 *   label = @Translation("Exclude from AI feeds and markdown"),
 *   description = @Translation("Test copy of the ys_beacon tag."),
 *   name = "ai_disable_indexing",
 *   group = "advanced",
 *   weight = 2,
 *   type = "string",
 *   secure = FALSE,
 *   multiple = FALSE
 * )
 */
class AiDisableIndexing extends BeaconAiDisableIndexing {}
