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

  // Trims the self-hosted MathJax package down to what pages actually request.
  // web/libraries is gitignored, but CI force-adds it into the Pantheon
  // artifact, so whatever composer leaves here gets committed - the whole
  // 66 MB, when a math-heavy page only ever pulls about 448 KB. unpacked/ is
  // an unminified mirror of the packed tree that MathJax.js never loads, and
  // on its own is roughly a third of the payload;
  // test/ is the upstream sample suite, otherwise publicly reachable at
  // /libraries/MathJax/test/. Composer cannot filter paths inside a package,
  // so this has to run after it places them.
  //
  // Registered on both post-install-cmd and post-update-cmd because composer
  // fires exactly one of the two per command: the deploy build uses
  // `composer update` (.ci/build/build_frontend) while the test job and local
  // installs use `composer install`. Re-running against an already-pruned
  // tree is the normal local case, so it has to be a no-op.
  //
  // The package is required by the profile's composer.json; only its
  // repository definition and this hook sit at root, because composer runs
  // root-package scripts only.
  public static function pruneMathJaxLibrary(Event $event)
  {
    $fs = new Filesystem();
    $library = static::getDrupalRoot(getcwd()) . '/libraries/MathJax';

    // Nothing to do when the library was never installed in this checkout,
    // and a safe stop if the pin ever moved to MathJax 3.x, which ships no
    // root MathJax.js and none of the directories below.
    if (!$fs->exists($library . '/MathJax.js')) {
      return;
    }

    // docs/ only exists in some upstream builds, not in the pinned 2.7.9 dist.
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
        // Deliberately broad. Failing to prune only costs artifact size, so
        // this must never fail a build - and IOException alone is not a wide
        // enough net: Filesystem::remove() renames the target aside and then
        // builds a \FilesystemIterator over it, which throws
        // \UnexpectedValueException (not an IOException) if a directory in
        // the tree cannot be opened. Note that path leaves the renamed
        // `.!XXXX` sibling behind, hence naming the directory here.
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
