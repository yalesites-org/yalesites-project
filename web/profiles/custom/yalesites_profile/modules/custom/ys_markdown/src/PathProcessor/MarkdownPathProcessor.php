<?php

namespace Drupal\ys_markdown\PathProcessor;

use Drupal\path_alias\AliasManagerInterface;
use Drupal\Core\PathProcessor\InboundPathProcessorInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Maps "<page url>.md" onto the node's Markdown route.
 */
class MarkdownPathProcessor implements InboundPathProcessorInterface {

  /**
   * Request attribute holding the path as the visitor typed it, minus ".md".
   */
  const ORIGINAL_PATH_ATTRIBUTE = '_ys_markdown_path';

  /**
   * Matches an internal node path.
   */
  const NODE_PATH = '#^/node/\d+$#';

  public function __construct(
    protected AliasManagerInterface $aliasManager,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function processInbound($path, Request $request) {
    // "/.md" is never a page: there must be something in front of the suffix.
    if (!str_ends_with($path, '.md') || strlen($path) <= 4) {
      return $path;
    }
    $original = substr($path, 0, -3);
    $internal = preg_match(self::NODE_PATH, $original) ? $original : $this->aliasManager->getPathByAlias($original);
    if (!preg_match(self::NODE_PATH, $internal)) {
      return $path;
    }
    $request->attributes->set(self::ORIGINAL_PATH_ATTRIBUTE, $original);
    return $internal . '/md';
  }

}
