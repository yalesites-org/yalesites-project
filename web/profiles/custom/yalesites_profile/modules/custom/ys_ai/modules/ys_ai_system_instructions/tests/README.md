# YaleSites AI System Instructions Tests

This directory contains tests for the YaleSites AI System Instructions module.

## About TextFormatDetectionService

The `TextFormatDetectionService` detects whether content is markdown or plain text and applies appropriate formatting. This dual-path approach is necessary because:

### Two Formatting Paths

1. **Markdown Path** (detected via CommonMark AST scoring)
   - Preserves content exactly as-is (trimmed only)
   - Maintains markdown structure (headers, lists, formatting)
   - Used for AI-generated system instructions

2. **Plain Text Path** (detected when markdown score is low)
   - Applies formatting transformations:
     - Adds sentence breaks after periods followed by capitals
     - Wraps long lines (>120 chars) at natural boundaries
     - Normalizes multiple spaces to single spaces
     - Cleans up excessive line breaks
   - Used for plain text content that needs readability improvements

### Why Not Always Markdown?

Plain text formatting transformations (sentence breaks, line wrapping) would corrupt markdown structure. For example:
- Plain text: `"First sentence. Second sentence."` → Adds `\n\n` between sentences
- Markdown: `"# Header\n\nContent"` → Preserved exactly (those `\n\n` are intentional structure)

### CommonMark Usage

CommonMark is used for **detection only** (parsing AST to score markdown likelihood), NOT for rendering. The service returns formatted **strings** (markdown or plain text), not HTML. Any markdown-to-HTML conversion happens in Drupal's text format system.

## Running Tests

### Run All Unit Tests for This Module

```bash
lando ssh -c 'export SIMPLETEST_DB="mysql://pantheon:pantheon@database/pantheon" && export SIMPLETEST_BASE_URL="http://appserver" && vendor/bin/phpunit web/profiles/custom/yalesites_profile/modules/custom/ys_ai/modules/ys_ai_system_instructions/tests/src/Unit/'
```

### Run Specific Test File

```bash
lando ssh -c 'export SIMPLETEST_DB="mysql://pantheon:pantheon@database/pantheon" && export SIMPLETEST_BASE_URL="http://appserver" && vendor/bin/phpunit web/profiles/custom/yalesites_profile/modules/custom/ys_ai/modules/ys_ai_system_instructions/tests/src/Unit/TextFormatDetectionServiceTest.php'
```

### Run Specific Test Method

```bash
lando ssh -c 'export SIMPLETEST_DB="mysql://pantheon:pantheon@database/pantheon" && export SIMPLETEST_BASE_URL="http://appserver" && vendor/bin/phpunit --filter testDetectFormatWithHeaders web/profiles/custom/yalesites_profile/modules/custom/ys_ai/modules/ys_ai_system_instructions/tests/src/Unit/TextFormatDetectionServiceTest.php'
```

### Run with Verbose Output

```bash
lando ssh -c 'export SIMPLETEST_DB="mysql://pantheon:pantheon@database/pantheon" && export SIMPLETEST_BASE_URL="http://appserver" && vendor/bin/phpunit --verbose web/profiles/custom/yalesites_profile/modules/custom/ys_ai/modules/ys_ai_system_instructions/tests/src/Unit/'
```

### Run with Code Coverage (requires Xdebug)

```bash
# Enable Xdebug first
lando drush xdebug-on

# Run tests with coverage
lando ssh -c 'export SIMPLETEST_DB="mysql://pantheon:pantheon@database/pantheon" && export SIMPLETEST_BASE_URL="http://appserver" && vendor/bin/phpunit --coverage-html coverage web/profiles/custom/yalesites_profile/modules/custom/ys_ai/modules/ys_ai_system_instructions/tests/src/Unit/'

# Disable Xdebug when done
lando drush xdebug-off
```

## Test Structure

```
tests/
├── README.md                 # This file
└── src/
    └── Unit/                 # Unit tests (no database required)
        └── TextFormatDetectionServiceTest.php
```

## Writing New Tests

### Unit Tests

Unit tests should be placed in `tests/src/Unit/` and should:

- Extend `Drupal\Tests\UnitTestCase`
- Not require database access
- Mock all dependencies
- Test individual methods in isolation
- Follow Drupal coding standards

Example test class structure:

```php
<?php

namespace Drupal\Tests\ys_ai_system_instructions\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for MyService.
 *
 * @coversDefaultClass \Drupal\ys_ai_system_instructions\Service\MyService
 * @group ys_ai_system_instructions
 * @group ys_ai
 * @group yalesites
 */
class MyServiceTest extends UnitTestCase {

  protected function setUp(): void {
    parent::setUp();
    // Set up test fixtures
  }

  /**
   * @covers ::myMethod
   */
  public function testMyMethod() {
    // Test implementation
  }
}
```

### Kernel Tests

If you need database access, create Kernel tests in `tests/src/Kernel/`:

```php
<?php

namespace Drupal\Tests\ys_ai_system_instructions\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Kernel tests for MyService.
 *
 * @group ys_ai_system_instructions
 * @group ys_ai
 * @group yalesites
 */
class MyServiceKernelTest extends KernelTestBase {

  protected static $modules = ['ys_ai_system_instructions'];

  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['ys_ai_system_instructions']);
  }
}
```

## Coding Standards

Before committing tests, ensure they pass coding standards:

```bash
lando composer code-sniff -- web/profiles/custom/yalesites_profile/modules/custom/ys_ai/modules/ys_ai_system_instructions/tests/
```

Auto-fix coding standard issues:

```bash
lando composer code-fix -- web/profiles/custom/yalesites_profile/modules/custom/ys_ai/modules/ys_ai_system_instructions/tests/
```

## Continuous Integration

These tests are automatically run as part of the CI/CD pipeline when:

- Creating pull requests
- Merging to develop
- Deploying to Pantheon environments

## Troubleshooting

### "Class not found" errors

Ensure you're running tests from the project root and that composer dependencies are installed:

```bash
lando composer install
```

### PHPUnit not found

PHPUnit is included via composer. If it's missing:

```bash
lando composer update
```

### Database connection errors

Verify the `SIMPLETEST_DB` environment variable matches your Lando database configuration:

```bash
lando info
```

### Memory limit errors

Increase PHP memory limit in the test command:

```bash
lando ssh -c 'php -d memory_limit=512M vendor/bin/phpunit ...'
```

## Resources

- [Drupal PHPUnit documentation](https://www.drupal.org/docs/testing/phpunit-in-drupal)
- [PHPUnit documentation](https://phpunit.de/documentation.html)
- [YaleSites testing guidelines](../../../../../../../../CLAUDE.md)
