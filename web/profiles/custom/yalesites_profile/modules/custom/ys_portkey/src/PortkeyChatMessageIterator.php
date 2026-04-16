<?php

namespace Drupal\ys_portkey;

use Drupal\ai\OperationType\Chat\StreamedChatMessage;
use Drupal\ai\OperationType\Chat\StreamedChatMessageIterator;

/**
 * Portkey chat message iterator for streaming responses.
 */
class PortkeyChatMessageIterator extends StreamedChatMessageIterator {

  /**
   * {@inheritdoc}
   */
  public function getIterator(): \Generator {
    foreach ($this->iterator->getIterator() as $data) {
      yield new StreamedChatMessage(
        $data->choices[0]->delta->role ?? '',
        $data->choices[0]->delta->content ?? '',
        $data->usage ?? []
      );
    }
  }

}
