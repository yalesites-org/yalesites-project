<?php

namespace Drupal\ys_embed\Plugin;

use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
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
   * The third-party script URL a script-based template loads.
   *
   * Declared separately from the template so the URL is written once and the
   * template can render it either as a live src or as Klaro's deferred
   * data-src. Empty for sources that load no script of their own.
   *
   * @var string
   */
  protected static $script = '';

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
   * The accessible title to use when the editor leaves Title blank.
   *
   * @var string
   */
  protected static $defaultTitle = '';

  /**
   * The Klaro consent service (app) ID that gates this source's content.
   *
   * Declare the ID of a klaro_app config entity to hold this source's
   * third-party request until the visitor consents. Leave it NULL to render
   * the content immediately — correct only for Yale-controlled tenants and for
   * sources that make no third-party request at all.
   *
   * Gating is driven by this declaration rather than by isIframe(), which is a
   * substring search over the template and can flip on incidental text.
   *
   * @var string|null
   */
  protected static $klaroService = NULL;

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
    return !empty(preg_match(static::$pattern, $input, $matches));
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
  public function getKlaroService(): ?string {
    return static::$klaroService;
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $params): array {
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
      // The iframe path builds its own element from '#url', so the theme layer
      // is the only place that can swap 'src' for Klaro's 'data-src'.
      // ys_embed_preprocess_embed_wrapper() clears both of these when the site
      // has consent management switched off.
      '#klaroService' => static::$klaroService,
      '#embedSource' => [
        '#type' => 'inline_template',
        '#template' => static::$template,
        // Script-based sources render their own template, so they need the
        // service ID in the template context rather than at the theme layer.
        // These are unioned on the left so they win a key collision: $params
        // comes from named groups in a regex matched against editor-pasted
        // embed code, and must never be able to redefine the consent gate.
        '#context' => [
          'klaro_service' => static::$klaroService,
          'script' => static::$script,
        ] + $params,
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
