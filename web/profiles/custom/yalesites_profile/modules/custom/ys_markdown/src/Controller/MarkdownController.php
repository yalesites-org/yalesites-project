<?php

namespace Drupal\ys_markdown\Controller;

use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Http\Exception\CacheableNotFoundHttpException;
use Drupal\node\NodeInterface;
use Drupal\ys_markdown\MarkdownBuilder;
use Drupal\ys_markdown\MarkdownEligibility;
use Drupal\ys_markdown\PathProcessor\MarkdownPathProcessor;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Serves a node as public Markdown.
 */
class MarkdownController extends ControllerBase {

  public function __construct(
    protected MarkdownEligibility $eligibility,
    protected MarkdownBuilder $builder,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ys_markdown.eligibility'),
      $container->get('ys_markdown.builder'),
    );
  }

  /**
   * Returns the Markdown for a node, or 404 when it may not be public.
   */
  public function node(NodeInterface $node, Request $request): CacheableResponse {
    $requestPath = $request->attributes->get(MarkdownPathProcessor::ORIGINAL_PATH_ATTRIBUTE);
    $cacheability = $this->eligibility->getCacheability($node);
    // The 404 carries the same dependencies, so flipping a setting or the node
    // invalidates it without a cache clear.
    if (!$this->eligibility->isEligible($node, $requestPath)) {
      throw new CacheableNotFoundHttpException($cacheability);
    }
    $built = $this->builder->build($node);
    $response = new CacheableResponse($built['markdown'], 200, [
      'Content-Type' => 'text/markdown; charset=utf-8',
    ]);
    $response->addCacheableDependency($cacheability->merge($built['cacheability']));
    return $response;
  }

}
