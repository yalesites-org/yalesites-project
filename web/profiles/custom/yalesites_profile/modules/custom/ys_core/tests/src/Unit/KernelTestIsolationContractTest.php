<?php

namespace Drupal\Tests\ys_core\Unit;

use Drupal\Core\Test\TestDiscovery;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\UnitTestCase;
use Drupal\Tests\ys_core\Kernel\YsKernelTestBase;

/**
 * Guards the runtime contract that keeps the custom kernel suite usable.
 *
 * YsKernelTestBase turns three inherited PHPUnit properties off, and they have
 * to move together: turning isolation off while leaving the two backups on
 * makes the suite dramatically slower rather than faster. The measurements and
 * the mechanism are recorded on that class - read its docblock first.
 *
 * That coupling is why this is a test rather than a comment. The fast and the
 * catastrophic configurations differ by one property, the difference is
 * invisible in review, and nothing else in the repo would catch it.
 *
 * Discovery is driven by the base class a test actually extends, not by the
 * directory it sits in. Directory names drift: this repo already had a
 * KernelTestBase subclass filed under tests/src/Functional, which a
 * Kernel-directory-only check would have missed.
 *
 * @group ys_core
 *
 * @see \Drupal\Tests\ys_core\Kernel\YsKernelTestBase
 */
class KernelTestIsolationContractTest extends UnitTestCase {

  /**
   * Property defaults every custom kernel test must end up with.
   */
  const REQUIRED_DEFAULTS = [
    'runTestInSeparateProcess' => FALSE,
    'backupGlobals' => FALSE,
    'backupStaticAttributes' => FALSE,
  ];

  /**
   * Annotations that re-enable isolation or state backups behind the property.
   *
   * PHPUnit resolves all of these from docblocks and applies them by calling
   * the setters after construction, so an annotation beats the property
   * defaults this test reads by reflection. `@backupStaticAttributes enabled`
   * on a class that inherits the base is exactly the isolation-off,
   * backups-on combination that measured 5.7x slower than doing nothing.
   *
   * @see \PHPUnit\Framework\TestBuilder::configureTestCase()
   */
  const FORBIDDEN_ANNOTATIONS = '/@(runInSeparateProcess|runTestsInSeparateProcesses|runClassInSeparateProcess|backupGlobals|backupStaticAttributes|preserveGlobalState)\b/';

  /**
   * The shared base class declares the whole contract itself.
   *
   * Discovery below skips *TestBase.php, so nothing else asserts this.
   */
  public function testSharedBaseDeclaresTheContract(): void {
    $defaults = (new \ReflectionClass(YsKernelTestBase::class))->getDefaultProperties();

    foreach (self::REQUIRED_DEFAULTS as $property => $expected) {
      $this->assertSame($expected, $defaults[$property] ?? NULL, sprintf(
        '%s must declare $%s as %s.',
        YsKernelTestBase::class,
        $property,
        var_export($expected, TRUE)
      ));
    }
  }

  /**
   * Every kernel test in our own code inherits or restates the contract.
   */
  public function testEveryCustomKernelTestInheritsTheContract(): void {
    $classes = $this->customTestClasses();

    $this->assertNotEmpty(
      $classes,
      'Found no custom test classes - the discovery globs below are wrong.'
    );

    $kernel_tests = 0;
    $offenders = [];
    foreach ($classes as $class => $file) {
      if (!class_exists($class) || !is_subclass_of($class, KernelTestBase::class)) {
        continue;
      }
      $kernel_tests++;

      $reflection = new \ReflectionClass($class);
      if ($reflection->isAbstract()) {
        continue;
      }

      $defaults = $reflection->getDefaultProperties();
      foreach (self::REQUIRED_DEFAULTS as $property => $expected) {
        if (($defaults[$property] ?? NULL) !== $expected) {
          $offenders[$file][] = '$' . $property;
        }
      }

      // The property defaults above are only half the story - see
      // FORBIDDEN_ANNOTATIONS.
      if (preg_match(self::FORBIDDEN_ANNOTATIONS, file_get_contents($file), $matches)) {
        $offenders[$file][] = '@' . $matches[1];
      }
    }

    // A floor rather than an exact count, so adding a kernel test does not
    // fail this test - but low enough to catch discovery silently collapsing,
    // which would make everything below vacuously pass.
    $this->assertGreaterThan(
      50,
      $kernel_tests,
      'Discovery found only ' . $kernel_tests . ' KernelTestBase subclasses, far fewer than this repo has. The globs are wrong and this guard is not really checking anything.'
    );

    $report = [];
    foreach ($offenders as $file => $properties) {
      $report[] = $file . ' (' . implode(', ', $properties) . ')';
    }

    $this->assertSame([], $report, sprintf(
      "%d kernel test(s) still run with PHPUnit process isolation or global-state backups on.\n"
      . "Extend %s instead of KernelTestBase, or - if the class must extend a different\n"
      . "base - declare the same properties in the class itself:\n%s",
      count($offenders),
      YsKernelTestBase::class,
      implode("\n", $report)
    ));
  }

  /**
   * Every file in a tests/src/Kernel directory resolves to a loadable class.
   *
   * The contract check above skips anything it cannot load, which is right -
   * an unloadable Unit test is not this test's business. That tolerance would
   * otherwise let a kernel test with a class name or namespace that disagrees
   * with its path drop out of the guard silently, so it is asserted here.
   */
  public function testKernelDirectoryFilesAllResolveToClasses(): void {
    $unresolved = [];
    foreach ($this->customTestClasses() as $class => $file) {
      if (strpos($file, '/tests/src/Kernel/') !== FALSE && !class_exists($class)) {
        $unresolved[] = "$file (expected $class)";
      }
    }

    $this->assertSame([], $unresolved, sprintf(
      "%d file(s) under tests/src/Kernel do not declare the class their path implies,\n"
      . "so they are invisible to the isolation contract check:\n%s",
      count($unresolved),
      implode("\n", $unresolved)
    ));
  }

  /**
   * Discovers every test class in our own code, keyed by class name.
   *
   * Globs the tests/src directories rather than walking modules/custom, which
   * holds tens of thousands of files. Class names come from core's own PSR-4
   * discovery rather than being parsed out of the source.
   *
   * @return array
   *   Absolute file paths keyed by fully qualified class name.
   */
  protected function customTestClasses(): array {
    // .../ys_core/tests/src/Unit -> <profile>/modules/custom, then -> web.
    $profile_custom = dirname(__DIR__, 4);
    $drupal_root = dirname($profile_custom, 5);

    // Our code, per composer code-sniff: the profile's modules plus any
    // instance-local modules and themes. Sub-modules nest one level.
    $roots = [
      $profile_custom . '/{*,*/modules/*}/tests/src',
      $drupal_root . '/modules/custom/{*,*/modules/*}/tests/src',
      $drupal_root . '/themes/custom/*/tests/src',
    ];

    $classes = [];
    foreach ($roots as $pattern) {
      foreach (glob($pattern, GLOB_ONLYDIR | GLOB_BRACE) ?: [] as $dir) {
        // The extension name is the directory holding tests/, e.g. ys_alert or
        // the nested ys_ai_system_instructions.
        $extension = basename(dirname($dir, 2));
        // TestDiscovery is @internal, but it is the only thing in core that
        // maps a test directory to PSR-4 class names, it needs no container,
        // and a signature change would fail this test loudly rather than
        // quietly. It also skips *TestBase, *Trait and *Interface files.
        $classes += TestDiscovery::scanDirectory('Drupal\\Tests\\' . $extension . '\\', $dir);
      }
    }

    return $classes;
  }

}
