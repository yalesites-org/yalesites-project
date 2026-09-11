<?php

declare(strict_types=1);

namespace Drupal\ys_ai_tester_legacy;

use Drupal\ys_ai_tester\UpstreamStatusInterface;

/**
 * An error the legacy assistant reported inside an otherwise-successful stream.
 *
 * The legacy endpoint streams its reply, and a streamed response can carry a
 * failure in its body while the HTTP status stays 200 - openai-php documents
 * the same hazard for its own streamed requests. Those failures arrive with no
 * HTTP response to read a status off, so before this they were
 * indistinguishable from a permanent error and never retried, even when the
 * body plainly said 429.
 *
 * Carrying the code the body reported lets the tester classify the failure the
 * same way it classifies every other one: on a status code, never on the
 * wording of a message.
 */
class LegacyStreamException extends \RuntimeException implements UpstreamStatusInterface {

  /**
   * Constructs a legacy stream exception.
   *
   * @param string $message
   *   The message to record against the question.
   * @param int|null $statusCode
   *   The status code the stream's error payload reported, when it carried one.
   */
  public function __construct(string $message, protected ?int $statusCode = NULL) {
    parent::__construct($message);
  }

  /**
   * {@inheritdoc}
   */
  public function getUpstreamStatusCode(): ?int {
    return $this->statusCode;
  }

}
