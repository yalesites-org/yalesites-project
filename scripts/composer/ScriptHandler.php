<?php

/**
 * @file
 * Contains \DrupalProject\composer\ScriptHandler.
 */

namespace DrupalProject\composer;

use Composer\Script\Event;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

class ScriptHandler
{

  protected static function getDrupalRoot($project_root)
  {
    return $project_root .  '/web';
  }

  public static function createRequiredFiles(Event $event)
  {
    $fs = new Filesystem();
    $root = static::getDrupalRoot(getcwd());

    $dirs = [
      'modules',
      'profiles',
      'themes',
    ];

    // Required for unit testing
    foreach ($dirs as $dir) {
      if (!$fs->exists($root . '/' . $dir)) {
        $fs->mkdir($root . '/' . $dir);
        $fs->touch($root . '/' . $dir . '/.gitkeep');
      }
    }

    // Create the files directory with chmod 0777
    if (!$fs->exists($root . '/sites/default/files')) {
      $oldmask = umask(0);
      $fs->mkdir($root . '/sites/default/files', 0777);
      umask($oldmask);
      $event->getIO()->write("Create a sites/default/files directory with chmod 0777");
    }
  }

  // Removes the parts of the self-hosted MathJax package that the runtime
  // never loads. web/libraries is gitignored, but the deploy job force-adds
  // all of web into the Pantheon artifact
  // (.github/workflows/build_deploy_and_test.yml), so whatever composer
  // leaves on disk gets committed - all 66 MB of MathJax 2.7.9, across 3,147
  // files.
  //
  // What goes: unpacked/ is an unminified mirror of the packed tree that
  // MathJax.js never loads, and on its own is a third of the package;
  // test/ is the upstream sample suite, which would otherwise be publicly
  // reachable at /libraries/MathJax/test/ - a surface-area reason rather than
  // a size one, since it is only 104 KB.
  //
  // What stays, and why this is not a "trim to what a page requests": the
  // remaining ~44 MB is mostly fonts/, jax/, config/ and localization/, which
  // MathJax fetches lazily per formula. Very little of it is requested by any
  // one page, but it all has to be there. Do not "finish the job" by pruning
  // those.
  //
  // Composer has no way to exclude paths from a package it installs, so this
  // has to run after composer has extracted it.
  //
  // Registered on both post-install-cmd and post-update-cmd because composer
  // dispatches exactly one of the two per command (Installer.php picks on
  // $this->update): the deploy build uses `composer update`
  // (.ci/build/build_frontend), while .ci/test/static/run uses
  // `composer install`. Note a first `composer install` with no composer.lock
  // - the normal case here, since the root lock is untracked - is promoted to
  // an update internally and so fires post-update-cmd. Re-running against an
  // already-pruned tree is the everyday local case, so this must be a no-op.
  //
  // The version pin lives in the profile's composer.json; only the package's
  // repository definition and this hook sit at root, because composer runs
  // root-package scripts only.
  public static function pruneMathJaxLibrary(Event $event)
  {
    $fs = new Filesystem();
    $library = static::getDrupalRoot(getcwd()) . '/libraries/MathJax';

    // Nothing to do when the library was never installed in this checkout.
    // Also a safe stop if the pin ever moved to MathJax 3.x, which ships no
    // root MathJax.js and none of the directories below - belt-and-braces,
    // since the README's pin rule already forbids 3.x outright.
    if (!$fs->exists($library . '/MathJax.js')) {
      return;
    }

    // docs/ cannot appear under the current pin: the repository definition
    // points dist.url at the npm tarball, which has no docs/. It is listed
    // for the case where that is ever repointed at the upstream git tree,
    // which at tag 2.7.9 does ship one. Kept deliberately, not left over.
    //
    // Filesystem::remove() is already a no-op on a missing path, so the
    // exists() check is here only to keep the log line to what was removed.
    foreach (['unpacked', 'test', 'docs'] as $dir) {
      $path = $library . '/' . $dir;
      if (!$fs->exists($path)) {
        continue;
      }
      try {
        $fs->remove($path);
        $event->getIO()->write("Pruned unused MathJax directory: $dir");
      }
      catch (\Throwable $e) {
        // Deliberately broad, \Error included: nothing in a size
        // optimization is worth failing a deploy over. IOException alone is
        // not a wide enough net either - Filesystem::remove() renames the
        // target to a `.!` sibling and then builds a \FilesystemIterator
        // over it, and that constructor throws \UnexpectedValueException,
        // not an IOException, if a directory in the tree cannot be opened.
        //
        // That code path also skips the rename-back that the IOException path
        // does, so the `.!` sibling is left holding the full unpacked tree -
        // and `git add -f ... web` would commit it, making a failed prune
        // ship more than no prune at all. Hence naming the directory to check.
        $event->getIO()->writeError(
          "<warning>Could not prune MathJax $dir; check $library for a "
          . "leftover .! directory: " . $e->getMessage() . "</warning>"
        );
      }
    }
  }

  // This is called by the QuickSilver deploy hook to convert from
  // a 'lean' repository to a 'fat' repository. This should only be
  // called when using this repository as a custom upstream, and
  // updating it with `terminus composer <site>.<env> update`. This
  // is not used in the GitHub PR workflow.
  public static function prepareForPantheon()
  {
    // Get rid of any .git directories that Composer may have added.
    // n.b. Ideally, there are none of these, as removing them may
    // impair Composer's ability to update them later. However, leaving
    // them in place prevents us from pushing to Pantheon.
    $dirsToDelete = [];
    $finder = new Finder();
    foreach ($finder
        ->directories()
        ->in(getcwd())
        ->ignoreDotFiles(false)
        ->ignoreVCS(false)
        ->depth('> 0')
        ->name('.git')
      as $dir) {
      $dirsToDelete[] = $dir;
    }
    $fs = new Filesystem();
    $fs->remove($dirsToDelete);

    // Fix up .gitignore: remove everything above the "::: cut :::" line
    $gitignoreFile = getcwd() . '/.gitignore';
    $gitignoreContents = file_get_contents($gitignoreFile);
    $gitignoreContents = preg_replace('/.*::: cut :::*/s', '', $gitignoreContents);
    file_put_contents($gitignoreFile, $gitignoreContents);
  }
}
