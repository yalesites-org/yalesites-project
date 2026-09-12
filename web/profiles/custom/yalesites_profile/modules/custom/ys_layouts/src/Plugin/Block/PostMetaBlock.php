<?php

namespace Drupal\ys_layouts\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Datetime\DateFormatter;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\node\NodeInterface;
use Drupal\ys_core\Plugin\Block\LayoutBuilderEntityContextTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Block for post meta data that appears above posts.
 *
 * The "layout_builder.entity" context slot and its name are explained on
 * \Drupal\ys_core\Plugin\Block\LayoutBuilderEntityContextTrait. An
 * annotation cannot be inherited from a trait, so the slot is declared here.
 *
 * @Block(
 *   id = "post_meta_block",
 *   admin_label = @Translation("Post Meta Block"),
 *   category = @Translation("YaleSites Layouts"),
 *   context_definitions = {
 *     "layout_builder.entity" = @ContextDefinition("entity",
 *       label = @Translation("Entity being viewed"),
 *       required = FALSE
 *     )
 *   }
 * )
 */
class PostMetaBlock extends BlockBase implements ContainerFactoryPluginInterface {

  use LayoutBuilderEntityContextTrait;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The date formatter.
   *
   * @var \Drupal\Core\Datetime\DateFormatter
   */
  protected $dateFormatter;

  /**
   * Constructs a new PostMetaBlock object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \Drupal\Core\Datetime\DateFormatter $date_formatter
   *   The date formatter.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, RequestStack $request_stack, DateFormatter $date_formatter) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->requestStack = $request_stack;
    $this->dateFormatter = $date_formatter;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('request_stack'),
      $container->get('date.formatter'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {

    $node = $this->getCurrentNode();
    if (!$node || $node->bundle() !== 'post') {
      return [];
    }

    // Post fields.
    $title = $node->getTitle();
    $author = ($node->field_author->first()) ? $node->field_author->first()->getValue()['value'] : NULL;
    // field_publish_date is required in config, but content created
    // programmatically or before that requirement can still be missing one --
    // and every sibling read here already guards first().
    $publishDate = $node->field_publish_date->first() ? strtotime($node->field_publish_date->first()->getValue()['value']) : NULL;
    $dateFormatted = $publishDate ? $this->dateFormatter->format($publishDate, '', 'c') : NULL;
    $showReadTime = ($node->field_show_read_time->first()) ? $node->field_show_read_time->first()->getValue()['value'] : NULL;
    $showSocialMediaSharingLinks = ($node->field_show_social_media_sharing->first()) ? $node->field_show_social_media_sharing->first()->getValue()['value'] : NULL;
    $post_authors = [];
    if ($author) {
      $post_authors[] = ['title' => $author, 'url' => NULL, 'isLink' => FALSE];
    }
    $post_authors = array_merge($post_authors, $this->getPostAuthorLinks($node->field_authors));

    return [
      '#theme' => 'ys_post_meta_block',
      '#label' => $title,
      '#author' => $author,
      '#date_formatted' => $dateFormatted,
      '#show_read_time' => $showReadTime,
      '#show_social_media_sharing_links' => $showSocialMediaSharingLinks,
      '#post_authors' => $post_authors,
    ];
  }

  /**
   * Gets the node being rendered.
   *
   * The entity Layout Builder hands over is preferred over the node named by
   * the request; the request lookup is kept as a fallback tier for the
   * contexts Layout Builder offers nothing in.
   *
   * @return \Drupal\node\NodeInterface|null
   *   The node being rendered, or NULL when neither source resolves one.
   */
  protected function getCurrentNode(): ?NodeInterface {
    $node = $this->getRenderedEntitySavedNode();
    if ($node) {
      return $node;
    }

    $request = $this->requestStack->getCurrentRequest();
    $node = $request ? $request->attributes->get('node') : NULL;

    return $node instanceof NodeInterface ? $node : NULL;
  }

  /**
   * Get post author links to be rendered ourselves.
   *
   * @param array $authorReferences
   *   The author references.
   *
   * @return array
   *   The authors title and url.
   */
  protected function getPostAuthorLinks($authorReferences) {
    $authors = [];

    if ($authorReferences) {
      foreach ($authorReferences as $authorReference) {
        $author = $authorReference->entity;
        // The referenced profile can have been deleted, leaving a reference
        // item with no entity behind it.
        if (!$author) {
          continue;
        }

        $authors[] = [
          'title' => $author->getTitle(),
          'url' => $author->toUrl()->toString(),
          'isLink' => TRUE,
        ];
      }
    }
    return $authors;

  }

}
