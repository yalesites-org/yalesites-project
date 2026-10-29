<?php

namespace Drupal\ys_embed\Plugin;

use Drupal\Component\Plugin\PluginBase;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\Markup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for EmbedSource plugins.
 *
 * This plugin is used as a media source for embeded content. It is useful for
 * social media and streaming meadia providers as well as content shared in
 * an iframe. The plugin stores the 'input' (raw embed code added by the user),
 * validates the code, and renders the code through a inline Drupal template.
 *
 * New plugins are discovered through annotations. Several are included in this
 * modules in the ys_embed\Plugin\EmbedSource namespace.
 */
abstract class EmbedSourceBase extends PluginBase implements EmbedSourceInterface, ContainerFactoryPluginInterface {

  /**
   * The media settings config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * A regex to match an embed code to the source plugin.
   *
   * The regex should used named groups that are stored in the 'params' field
   * in the database and used to populated the template.
   *
   * @var string
   */
  protected static $pattern;

  /**
   * The name of the Drupal template for this code.
   *
   * @var string
   */
  protected static $template;

  /**
   * Instructions for finding the embed code in on third party website.
   *
   * @var string
   */
  protected static $instructions;

  /**
   * An example of the embed code.
   *
   * @var string
   */
  protected static $example;

  /**
   * Does the example contain markup. Defaults to false.
   *
   * @var bool
   */
  protected static $exampleContainsMarkup = FALSE;

  /**
   * An array of attributes to add to the template.
   *
   * To support previous implementations, embed_type is set to 'form'.
   *
   * @var array
   */
  protected static $displayAttributes = [
    'embed_type' => 'form',
  ];

  /**
   * The param that holds provider markup printed into the template, if any.
   *
   * When set, isValid() rejects embed code whose captured markup carries
   * script tags or event handler attributes, and build() filters the stored
   * value through Xss::filter() with $allowedTags before it reaches the
   * template. The template must print it without the raw filter.
   *
   * @var string|null
   */
  protected static $markupParam = NULL;

  /**
   * HTML tags kept in $markupParam when the markup is filtered for output.
   *
   * @var string[]
   */
  protected static $allowedTags = [];

  /**
   * The accessible title to use when the editor leaves Title blank.
   *
   * @var string
   */
  protected static $defaultTitle = '';

  /**
   * Creates a plugin instance.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    ConfigFactoryInterface $config_factory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->config = $config_factory->get('media.settings');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultThumbnailUri(): string {
    $thumbnail = $this->getPluginDefinition()['thumbnail'];
    return $this->config->get('icon_base_uri') . '/' . $thumbnail;
  }

  /**
   * {@inheritdoc}
   */
  public static function isValid(?string $input): bool {
    if (empty(preg_match(static::$pattern, $input ?? '', $matches))) {
      return FALSE;
    }
    if (static::$markupParam !== NULL) {
      return !static::containsUnsafeMarkup($matches[static::$markupParam] ?? '');
    }
    return TRUE;
  }

  /**
   * Checks markup for script tags or event handler attributes.
   *
   * @param string $markup
   *   The markup captured from the embed code.
   *
   * @return bool
   *   TRUE if the markup contains a script tag or an on* attribute.
   */
  protected static function containsUnsafeMarkup(string $markup): bool {
    // Cheap fallback for a script tag the parser might hide, such as one
    // inside a comment or in malformed markup.
    if (preg_match('/<\s*script\b/i', $markup)) {
      return TRUE;
    }
    // Parse rather than regex-scan tags, so a quoted attribute value that
    // contains ">" cannot hide an on* attribute that follows it.
    $xpath = new \DOMXPath(Html::load($markup));
    foreach ($xpath->query('//*') as $element) {
      if (strtolower($element->nodeName) === 'script') {
        return TRUE;
      }
      foreach ($element->attributes as $attribute) {
        if (stripos($attribute->nodeName, 'on') === 0) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

  /**
   * Filters provider markup down to the tags the provider's embed needs.
   *
   * Html::normalize() closes any tag the stored markup leaves open, so an
   * unclosed element cannot wrap page content that follows the embed.
   *
   * @param string $markup
   *   The stored provider markup.
   *
   * @return \Drupal\Core\Render\Markup
   *   The filtered markup, safe to print without the raw filter.
   */
  protected static function filterMarkup(string $markup): Markup {
    return Markup::create(Html::normalize(Xss::filter($markup, static::$allowedTags)));
  }

  /**
   * {@inheritdoc}
   */
  public function getParams(string $input): array {
    preg_match(static::$pattern, $input, $matches);
    return $matches ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public static function getInstructions(): string {
    return static::$instructions;
  }

  /**
   * {@inheritdoc}
   */
  public static function getExample(): string {
    return static::$example;
  }

  /**
   * {@inheritdoc}
   */
  public static function exampleContainsMarkup(): bool {
    return static::$exampleContainsMarkup;
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $params): array {
    $markupParam = static::$markupParam;
    if ($markupParam !== NULL && isset($params[$markupParam])) {
      $params[$markupParam] = static::filterMarkup((string) $params[$markupParam]);
    }

    $isIframe = $this->isIframe();
    $displayAttributes = static::$displayAttributes ?? [];
    if (!isset($displayAttributes['isIframe'])) {
      $displayAttributes['isIframe'] = $isIframe;
    }

    return [
      '#theme' => 'embed_wrapper',
      '#embedType' => $this->getPluginId(),
      '#title' => trim($params['title']) !== '' ? $params['title'] : static::$defaultTitle,
      '#url' => $this->getUrl($params),
      '#displayAttributes' => $displayAttributes,
      '#embedSource' => [
        '#type' => 'inline_template',
        '#template' => static::$template,
        '#context' => $params,
      ],
    ];
  }

  /**
   * Retrieves a URL using the params array.
   *
   * @param array $params
   *   An array of params.
   *
   * @return string
   *   The URL.
   */
  public function getUrl(array $params): string {
    return $params['url'] ?? '';
  }

  /**
   * Determines if the template is an iframe.
   *
   * @return bool
   *   TRUE if the template is an iframe, FALSE otherwise.
   */
  protected function isIframe(): bool {
    return strpos(static::$template, 'iframe') !== FALSE ? TRUE : FALSE;
  }

}
