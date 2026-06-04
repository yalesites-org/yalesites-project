<?php

namespace Drupal\ys_contoso_chat\Service;

/**
 * Request-scoped holder for RAG source citations.
 *
 * The Beacon RAG tool runs deep inside the AI assistant runner, while the chat
 * controller needs the citation metadata afterwards to build the response
 * envelope. Both run in the same synchronous request, so a singleton service
 * is sufficient to carry the citations between them without persisting them.
 */
class CitationStore {

  /**
   * The citations collected during the current request.
   *
   * @var array[]
   */
  protected array $citations = [];

  /**
   * Stores the citations for the current request.
   *
   * @param array[] $citations
   *   An ordered list of citation rows. The list order must match the
   *   1-based "[docN]" markers placed in the RAG context.
   */
  public function setCitations(array $citations): void {
    $this->citations = array_values($citations);
  }

  /**
   * Returns the citations collected during the current request.
   *
   * @return array[]
   *   The ordered list of citation rows.
   */
  public function getCitations(): array {
    return $this->citations;
  }

  /**
   * Whether any citations have been stored for the current request.
   */
  public function hasCitations(): bool {
    return $this->citations !== [];
  }

  /**
   * Clears any stored citations.
   *
   * Called before each chat request so citations never leak between turns.
   */
  public function reset(): void {
    $this->citations = [];
  }

}
