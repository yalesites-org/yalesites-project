<?php

declare(strict_types=1);

namespace Drupal\ys_ai_tester;

/**
 * Collects the assistants the tester can run a question list against.
 *
 * Backends arrive through the `ys_ai_tester.answer_backend` service tag, in tag
 * priority order. Resolving for labelling and resolving for running are
 * deliberately kept apart: labelFor() answers for any registered backend so a
 * stored run keeps its assistant's name, while getAvailable() — the only way to
 * reach a backend that will be asked a question — resolves nothing that cannot
 * answer right now.
 */
class AnswerBackendRegistry {

  /**
   * The registered backends, keyed by backend id.
   *
   * @var \Drupal\ys_ai_tester\AnswerBackendInterface[]
   */
  protected array $backends = [];

  /**
   * Constructs the registry.
   *
   * @param iterable $backends
   *   The tagged answer backends.
   */
  public function __construct(iterable $backends) {
    foreach ($backends as $backend) {
      $this->backends[$backend->id()] = $backend;
    }
  }

  /**
   * Returns a registered backend regardless of its availability.
   *
   * Not public: callers that intend to ask a question must go through
   * getAvailable() so they cannot skip the availability check.
   *
   * @param string $id
   *   The backend id.
   *
   * @return \Drupal\ys_ai_tester\AnswerBackendInterface|null
   *   The backend, or NULL when nothing is registered under that id.
   */
  protected function get(string $id): ?AnswerBackendInterface {
    return $this->backends[$id] ?? NULL;
  }

  /**
   * Returns a backend only when it can answer a question now.
   *
   * @param string $id
   *   The backend id.
   *
   * @return \Drupal\ys_ai_tester\AnswerBackendInterface|null
   *   The backend, or NULL when it is unregistered or unavailable.
   */
  public function getAvailable(string $id): ?AnswerBackendInterface {
    $backend = $this->get($id);
    return ($backend !== NULL && $backend->isAvailable()) ? $backend : NULL;
  }

  /**
   * Returns the available backends as form options.
   *
   * @return array
   *   Labels keyed by backend id, in tag priority order.
   */
  public function availableOptions(): array {
    $options = [];
    foreach ($this->backends as $id => $backend) {
      if ($backend->isAvailable()) {
        $options[$id] = $backend->label();
      }
    }
    return $options;
  }

  /**
   * Returns the ids of the backends that can answer a question now.
   *
   * @return string[]
   *   The available backend ids, in tag priority order.
   */
  public function availableIds(): array {
    return array_keys($this->availableOptions());
  }

  /**
   * Returns the display label for a stored backend id.
   *
   * Falls back to the raw id so a run whose backend module has since been
   * uninstalled still reads as something rather than an empty cell.
   *
   * @param string $id
   *   The backend id recorded on a run.
   *
   * @return string|\Stringable
   *   The backend label, or the id itself when it is no longer registered.
   */
  public function labelFor(string $id): string|\Stringable {
    return $this->get($id)?->label() ?? $id;
  }

}
