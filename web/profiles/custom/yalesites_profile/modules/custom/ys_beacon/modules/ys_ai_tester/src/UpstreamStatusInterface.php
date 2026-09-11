<?php

declare(strict_types=1);

namespace Drupal\ys_ai_tester;

/**
 * Implemented by exceptions that carry an upstream status code of their own.
 *
 * Most failures arrive as an HTTP exception the response can be read off, but
 * not all of them: a streamed endpoint can answer 200 and put the error in the
 * body, which is exactly what the legacy assistant does and what openai-php
 * warns about for its own streamed requests. An exception raised from a parsed
 * body has no HTTP response attached, so without this the tester would have to
 * guess at the failure from its message text - the one signal
 * \Drupal\ys_beacon_portkey\Plugin\AiProvider\PortkeyProvider already documents
 * as unreliable, because the wording varies by provider and by library version.
 *
 * A backend submodule implements this on its own exception to report the code
 * it found in the body, and AiTesterFailure treats it exactly like a status
 * read off a response. The interface lives here, in the tester, so backend
 * submodules depend on the tester rather than the other way round.
 */
interface UpstreamStatusInterface {

  /**
   * Returns the status code the upstream service reported.
   *
   * @return int|null
   *   An HTTP-style status code, or NULL when the payload carried none.
   */
  public function getUpstreamStatusCode(): ?int;

}
