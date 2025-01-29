<?php

namespace Drupal\ys_embed\Plugin\EmbedSource;

use Drupal\ys_embed\Plugin\EmbedSourceBase;
use Drupal\ys_embed\Plugin\EmbedSourceInterface;

/**
 * GitHub Applet embed source.
 *
 * @EmbedSource(
 *   id = "github_applet",
 *   label = @Translation("GitHub Applet"),
 *   description = @Translation("GitHub Applet embed source."),
 *   thumbnail = "github.png",
 *   active = TRUE,
 * )
 */
class GitHubApplet extends EmbedSourceBase implements EmbedSourceInterface {

  /**
   * {@inheritdoc}
   */
  protected static $pattern = '/^(?<embed_url>https:\/\/yalesites-org\.github\.io\/[\w-]+\/?.*)$/';

  /**
   * {@inheritdoc}
   */
  protected static $template = '<script type="module" crossorigin src="{{ embed_url }}/assets/app.js"></script>
<link rel="stylesheet" crossorigin href="{{ embed_url }}/assets/style.css">
<div id="root"></div>';

  /**
   * {@inheritdoc}
   */
  protected static $instructions = 'Provide the full URL to the base of the React app.';

  /**
   * {@inheritdoc}
   */
  protected static $example = 'https://yalesites-org.github.io/yale-po-filter-app/';

}
