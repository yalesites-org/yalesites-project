<?php

declare(strict_types=1);

namespace Drupal\ys_beacon\Exception;

/**
 * Names which upstream call failed while answering one question.
 *
 * Answering a single question makes several calls to different outside
 * services, and the exceptions they throw are indistinguishable once they reach
 * the caller: a 500 from the embeddings request and a 500 from the chat request
 * arrive as the same openai-php ServerException carrying the same message. That
 * ambiguity is what made a production failure impossible to correlate against
 * the gateway's own logs - the two calls authenticate with different Portkey
 * API keys, so "no errors in Portkey" may simply have been the wrong workspace.
 *
 * This wraps the original exception rather than replacing it. The cause stays
 * reachable through ::getPrevious(), which is what lets the AI Tester still
 * decide whether the failure is worth retrying.
 *
 * It lives in ys_beacon, not ys_ai_tester, because the dependency runs in that
 * direction: the tester submodule depends on Beacon, never the reverse.
 */
class BeaconStageException extends \RuntimeException {

  /**
   * Retrieval: embedding the question, then querying the vector index.
   */
  const STAGE_RETRIEVAL = 'retrieval';

  /**
   * The chat completion that produces the answer.
   */
  const STAGE_CHAT = 'chat';

  /**
   * The second chat turn, taken only when the first returned a tool call.
   */
  const STAGE_CHAT_FOLLOW_UP = 'chat_follow_up';

  /**
   * Constructs a staged Beacon exception.
   *
   * @param string $stage
   *   Which upstream call failed - one of the STAGE_* constants.
   * @param string $message
   *   The original exception's message, kept verbatim so anything already
   *   displaying it (the tester's per-question error cell) is unaffected.
   * @param \Throwable|null $previous
   *   The exception this wraps.
   */
  public function __construct(
    protected string $stage,
    string $message = '',
    ?\Throwable $previous = NULL,
  ) {
    parent::__construct($message, 0, $previous);
  }

  /**
   * Returns which upstream call failed.
   *
   * @return string
   *   One of the STAGE_* constants.
   */
  public function getStage(): string {
    return $this->stage;
  }

}
