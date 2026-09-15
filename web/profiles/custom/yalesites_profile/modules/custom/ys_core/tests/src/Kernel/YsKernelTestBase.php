<?php

namespace Drupal\Tests\ys_core\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Base class for YaleSites kernel tests, tuned for a usable suite runtime.
 *
 * Everything this class does is turn three inherited PHPUnit properties off.
 * It exists because those three have to move together and because the reason
 * is not discoverable from the properties themselves.
 *
 * KernelTestBase defaults $runTestInSeparateProcess to TRUE, so PHPUnit forks
 * a fresh PHP process per test method. Every one of those processes repeats two
 * full recursive scans of web/ - one in web/core/tests/bootstrap.php to build
 * the test namespace map, one in ExtensionDiscovery during the kernel boot -
 * and throws both away on exit. Measured in this checkout (2026-09) that is
 * ~20s and ~15s respectively against ~2s of actual test work, so a kernel test
 * method costs ~40s almost regardless of what it asserts.
 *
 * Sharing one process lets Drupal's own per-process static caches do their job:
 * the first test to run pays the scans and the rest do not. Measured on a
 * two-method class, 100s -> 41s, with the second method falling from 17.5s to
 * 0.27s.
 *
 * Note the scope. With isolation off and no per-class annotation, PHPUnit runs
 * the entire invocation in one process, so every class in the targeted path
 * shares it - 18 classes for ys_core's kernel directory, 12 for ys_beacon's -
 * not merely the methods of one class.
 *
 * The two backup properties are not a separate optimisation - they are a
 * prerequisite. PHPUnit skips global-state snapshotting entirely for isolated
 * tests, so those backups cost nothing today. Drop isolation while leaving them
 * on and PHPUnit starts serialising Drupal's entire static object graph between
 * methods: the same two-method class measured 573s, nearly six times SLOWER
 * than the isolated baseline. Turning them off therefore removes no check that
 * is running today; it only prevents one from switching on.
 *
 * This is a deliberate override of explicit core guidance. KernelTestBase's own
 * docblock says "Subclasses should not override this property," and gives a
 * reason distinct from static leakage: kernel tests autoload code from the
 * extensions they enable, and isolation stops one test's enabled module set
 * from leaving classes and procedural functions declared for the rest of the
 * run. That hazard is real and is the thing to suspect first if a test starts
 * failing only when run alongside others.
 *
 * The trade-off is accepted because the failure mode is loud rather than
 * silent, KernelTestBase::tearDown() already resets the container, Settings,
 * drupal_static and the file cache between methods, and each method still gets
 * its own database prefix. It is accepted on evidence, not on principle: see
 * the PR for issue #1640 for which suites were run end to end.
 *
 * If a kernel test genuinely cannot share a process, prefer fixing the leak.
 * When it must extend a different base instead (see
 * \Drupal\Tests\ys_views_basic\Kernel\PostPublishDateFilterTest, which needs
 * ViewsKernelTestBase), declare the same three properties in that class.
 * \Drupal\Tests\ys_core\Unit\KernelTestIsolationContractTest checks this across
 * our own code, and also rejects the docblock annotations that would re-enable
 * isolation or the backups behind the properties' back.
 */
abstract class YsKernelTestBase extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected $runTestInSeparateProcess = FALSE;

  /**
   * {@inheritdoc}
   */
  protected $backupGlobals = FALSE;

  /**
   * {@inheritdoc}
   */
  protected $backupStaticAttributes = FALSE;

}
