<?php

namespace Drupal\Tests\ys_core\Traits;

/**
 * Sets protected or inherited properties on an object under test.
 *
 * Drupal form objects reach most of their collaborators through protected
 * properties on FormBase/ConfigFormBase, so a unit test that only exercises
 * submitForm() is better off constructing the object with
 * ReflectionClass::newInstanceWithoutConstructor() and injecting just the few
 * services that code path touches, rather than satisfying a constructor with a
 * dozen arguments it will not use.
 *
 * Extracted here because the same five-line helper had been copy-pasted into
 * several ys_* unit tests independently; new tests should use this instead of
 * adding a sixth copy.
 */
trait ProtectedPropertyTrait {

  /**
   * Sets a protected or inherited property on an object via reflection.
   *
   * @param object $object
   *   The object to modify.
   * @param string $property
   *   The property name, declared on the object's class or any ancestor.
   * @param mixed $value
   *   The value to set.
   */
  protected function setProtectedProperty(object $object, string $property, mixed $value): void {
    $reflection = new \ReflectionProperty($object, $property);
    $reflection->setAccessible(TRUE);
    $reflection->setValue($object, $value);
  }

}
