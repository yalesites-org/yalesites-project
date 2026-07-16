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
  protected static $pattern = '/^https:\/\/yalesites-org\.github\.io\/(?<repo_name>[\w-]+)\/(?<app_directory>[^?]*?)\/?(?:\?.*)?$/';

  /**
   * {@inheritdoc}
   */
  protected static $template = '
    <script type="module" crossorigin src="https://yalesites-org.github.io/{{ repo_name }}/{{ app_directory }}/app.js"></script>
    <link rel="stylesheet" crossorigin href="https://yalesites-org.github.io/{{ repo_name }}/{{ app_directory }}/app.css">
    <div id="{{ repo_name }}"{% for key, value in data_attrs %} data-{{ key }}="{{ value }}"{% endfor %}></div>';

  /**
   * {@inheritdoc}
   */
  public function getParams(string $input): array {
    $pathOnly = strtok($input, '?');
    $params = parent::getParams($pathOnly);

    $parsed = parse_url($input);
    $dataAttrs = [];
    if (!empty($parsed['query'])) {
      parse_str($parsed['query'], $query);
      foreach ($query as $key => $value) {
        $safeKey = preg_replace('/[^a-z0-9-]/', '', strtolower($key));
        if ($safeKey !== '' && $value !== '') {
          $dataAttrs[$safeKey] = $value;
        }
      }
    }
    $params['data_attrs'] = $dataAttrs;

    return $params;
  }

  /**
   * {@inheritdoc}
   */
  protected static $instructions = 'Provide the URL to the GitHub Pages hosted repo and directory where app.js and app.css reside for the app. For more information, on how to set up a hosted repo, see the README.md file in the ys_embed module.';

  /**
   * {@inheritdoc}
   */
  protected static $example = 'https://yalesites-org.github.io/yale-po-filter-app/assets';

}
