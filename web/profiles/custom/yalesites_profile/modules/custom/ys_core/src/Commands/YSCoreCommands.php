<?php

namespace Drupal\ys_core\Commands;

use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\path_alias\AliasManager;
use Drush\Commands\DrushCommands;

/**
 * Drush command file.
 */
class YSCoreCommands extends DrushCommands {

  use StringTranslationTrait;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected MessengerInterface $messenger;

  /**
   * The path alias manager.
   *
   * @var \Drupal\path_alias\AliasManager
   */
  protected $pathAliasManager;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\path_alias\AliasManager $path_alias_manager
   *   The Path Alias Manager.
   */
  public function __construct(
    MessengerInterface $messenger,
    AliasManager $path_alias_manager,
  ) {
    parent::__construct();
    $this->messenger = $messenger;
    $this->pathAliasManager = $path_alias_manager;

  }

  /**
   * Given a path alias, get the internal path.
   *
   * @param string $alias
   *   The path alias.
   *
   * @command internal-path
   *
   * @aliases intpath
   */
  public function getInternalPath($alias) {

    if (!str_starts_with($alias, "/")) {
      $this->messenger->addError($this->t("The alias must start with a /"));
    }
    else {
      $path = $this->pathAliasManager->getPathByAlias($alias);
      if ($path == $alias) {
        $this->messenger->addError($this->t("There was no internal path found that uses that alias."));
      }
      else {
        $this->output()->writeln($path);
      }

    }

  }

}
