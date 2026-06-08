# YaleSites Project

YaleSites Project is a **Pantheon custom upstream for Drupal 10** that serves as the foundation for Yale's web platform. The YaleSites platform empowers the Yale community to create digital experiences for the web in applications that are secure, cost-effective, accessible, and sustainable.

This is a sophisticated multi-repository system that includes:

- **Pantheon custom upstream for Drupal 10** - Core platform infrastructure
- **Local development environment and tooling** - Lando-based development setup
- **Code quality, testing, and build tools** - Comprehensive linting and testing suite
- **Continuous integration and delivery support** - Automated builds and deployments

## Multi-Repository Architecture

This platform coordinates development across multiple repositories:

1. **yalesites-project** (this repo): Drupal platform and installation profile
2. **atomic**: Theme implementation bridging Drupal and component library
3. **component-library-twig**: Storybook component library with Yale design system
4. **tokens**: Design system tokens from Figma

## Quick Start

```bash
git clone git@github.com:yalesites-org/yalesites-project
cd yalesites-project
npm run setup
```

**Local Development URLs:**
- Primary Site: `https://yalesites-platform.lndo.site/`
- Storybook: Available when component library is linked

## Prerequisites

- **PHP Version**: 8.3
- **Node.js**: >=8.0, <11.0 (managed via NVM)
- **Docker**: 3GB+ memory, 3+ CPUs recommended
- **Lando**: Latest release
- **Composer**: Version 2.x
- **GitHub Personal Access Token**: Required for `@yalesites-org` packages (scope: `write:packages`)
- **Pantheon Terminus**: Machine token for platform integration

## Contributing

- [Review contribution guidelines](/docs/CONTRIBUTING.md)
- [Setup a local development environment](/docs/setup.md)
- [Getting started as a developer](/docs/development.md)
- [Learn about the project architecture](/docs/upstream.md)
- [Frontend review and development](/docs/theming.md)
- [Working with component themes and global themes](/docs/color-theme.md)

## Development Commands

All commands are prefixed with `npm run`. For example: `npm run setup`

### Environment Setup

- `setup` - Complete environment initialization and setup
- `db:get` - Download database from Pantheon dev environment
- `files:get` - Download files from Pantheon dev environment
- `content-import` - Import starterkit content for testing

### Build Commands

- `build` - Standard build preserving local database (for config updates)
- `build-with-assets` - Build with clean Pantheon database import (matches dev environment)
- `build-with-install` - Build with fresh site install (empty database for breaking changes)

### Multi-Repository Development

- `local:theme-link` - Establish npm links to frontend repositories (run once)
- `local:cl-dev` - Full cross-repository development mode (Storybook + Drupal)
- `local:git-checkout` - Sync all repositories to default branches

### Theme/Component Review

- `local:review-with-atomic-branch` - Review Atomic branch with latest CL release
- `local:review-with-cl-branch` - Review Component Library branch with current Atomic
- `local:review-with-atomic-and-cl-branch` - Review branches from both Atomic and CL

### Configuration Management

- `confex` - Export Drupal configuration to YAML files
- `confim` - Import YAML configuration to database

### Code Quality & Testing

- `lint` - Run all linting (JavaScript, PHP, Styles)
- `lint:js` - ESLint for custom JavaScript
- `lint:php` - PHP CodeSniffer with Drupal standards
- `lint:styles` - Stylelint for SCSS files
- `fix:js` - Auto-fix JavaScript linting errors
- `prettier` - Check code formatting (does not auto-fix)
- `test` - Run complete test suite (prettier + all linting)

### Alternative Commands (via Lando)

```bash
lando composer code-sniff    # PHP linting
lando composer code-fix      # Auto-fix PHP code style
lando phpunit               # Run PHPUnit tests
lando drush [command]       # Drush commands
lando xdebug-on            # Enable Xdebug
lando xdebug-off           # Disable Xdebug
```

### Running PHPUnit Tests

The PHPUnit suites are configured in `phpunit.xml` (`unit`, `kernel`,
`functional`, `functional-javascript`). Which database environment a suite needs
depends on its type:

- **Unit tests** need no database — run them directly:

  ```bash
  lando phpunit --testsuite unit
  lando phpunit path/to/SomeUnitTest.php
  ```

- **Kernel and Functional tests** boot a real Drupal database and require the
  `SIMPLETEST_DB` environment variable (it is left blank in `phpunit.xml` so it
  can be supplied per environment). Point it at the Lando database service
  (credentials come from `lando info` — by default database/user/password are all
  `pantheon`, host `database`):

  ```bash
  lando ssh -c "env SIMPLETEST_DB=mysql://pantheon:pantheon@database/pantheon \
    ./vendor/bin/phpunit path/to/SomeKernelTest.php"
  ```

  Kernel suites create and tear down their own prefixed tables in that database,
  so they do not disturb your working site data.

- **Functional tests** (BrowserTestBase) additionally need `SIMPLETEST_BASE_URL`
  pointing at the in-container web server, e.g.
  `SIMPLETEST_BASE_URL=http://appserver_nginx`. Note that the isolated functional
  test-site install may not stand up in every local Lando setup; CI is the
  reliable place to run them.

## Architecture Notes

- **Configuration**: Managed through installation profile at `web/profiles/custom/yalesites_profile/config/sync/`
- **Custom Modules**: Use `ys_` prefix (e.g., `ys_core`, `ys_themes`)
- **Branch Strategy**: `develop` (primary), `master` (releases), `YALB-XXX-description` (features)
- **Semantic Release**: Automated versioning on master branch using conventional commits
