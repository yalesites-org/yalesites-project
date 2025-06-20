# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

YaleSites is a Drupal 10 platform for Yale University's digital presence, built as a Pantheon custom upstream. The project combines a custom Drupal installation profile with external theme and component library repositories to provide a comprehensive content management system.

## Architecture

### Core Structure
- **Drupal Installation Profile**: `web/profiles/custom/yalesites_profile/` - Contains all platform configuration and custom modules
- **Configuration Management**: All structural changes managed through YAML files in `config/sync/`
- **Custom Modules**: Located in `web/profiles/custom/yalesites_profile/modules/custom/` including:
  - `ys_core`: Core functionality and dashboard
  - `ys_alert`: Alert system management
  - `ys_themes`: Theme configuration
  - `ys_layouts`: Custom layout definitions
  - `ys_embed`: External content embedding
  - `ys_localist`: Events platform integration

### External Dependencies
- **Atomic Theme**: Main frontend theme (separate repository)
- **Component Library Twig**: UI components with Storybook (separate repository)
- **Tokens**: Design tokens from Figma (separate repository)

## Essential Development Commands

### Project Setup & Building
```bash
npm run setup                    # Complete environment setup
npm run build                    # Build current branch on existing database
npm run build-with-assets        # Build with clean Pantheon reference database
npm run build-with-install       # Build with fresh site install
```

### Configuration Management
```bash
npm run confex                   # Export Drupal configuration (lando drush cex -y)
npm run confim                   # Import configuration to database (lando drush cim -y)
```

### Development Environment
```bash
npm run local:theme-link         # Link frontend repositories for development
npm run local:cl-dev             # Enable cross-repository development mode
npm run local:git-checkout       # Sync all repos to default branches
```

### PR Review Workflow
```bash
npm run local:review-with-atomic-branch          # Review with Atomic theme changes
npm run local:review-with-cl-branch              # Review with Component Library changes
npm run local:review-with-atomic-and-cl-branch   # Review with both repositories
```

### Code Quality
```bash
npm run test                     # Run all tests and linting
npm run lint                     # Lint JavaScript, PHP, and styles
npm run fix:js                   # Auto-fix JavaScript linting issues
lando composer code-sniff        # PHP code standards testing
```

### Drupal Management
```bash
lando drush cr                   # Clear cache
lando drush uli                  # Generate login link
lando drush cex -y               # Export configuration
lando drush cim -y               # Import configuration
```

### Content Management
```bash
lando drush migrate-import --group ys_starterkit    # Import starter content
lando drush migrate-rollback --group ys_starterkit  # Remove starter content
```

## Development Workflow

1. **Configuration Changes**: All structural changes must be made through Drupal's configuration UI, then exported with `npm run confex`
2. **Cross-Repository Development**: Use `npm run local:theme-link` to enable development across theme and component library repositories
3. **Testing**: Always run `npm run test` before committing changes
4. **Branch Management**: Use PR review scripts when testing changes across multiple repositories

## Content Architecture

### Content Types
- **Page** (page): Basic pages with Layout Builder
- **Post** (post): Blog/news content with categories
- **Event** (event): Event content with dates and locations
- **Profile** (profile): People profiles with contact information

### Block Types
The platform includes 30+ custom block types for Layout Builder:
- Layout: Grand Hero, Image Banner, Content Spotlight
- Interactive: Accordion, Tabs, Gallery, Video
- Content: Text, Callout, Pull Quote, Facts
- Navigation: Quick Links, Link Grid, Custom Cards
- Media: Image, Video, Media Grid, Embed
- Functional: Directory, Post List, Event List, Webform

## Configuration Management Rules

- Never manually edit YAML configuration files
- Always use Drupal's configuration UI for changes
- Export configuration immediately after making changes: `npm run confex`
- Configuration lives in the installation profile, not the site directory
- Test configuration imports/exports in a clean environment before committing

## Testing & Quality

- **PHPUnit**: Unit, kernel, functional, and JavaScript testing configured
- **Code Standards**: Drupal and DrupalPractice coding standards enforced
- **Linting**: JavaScript, PHP, and CSS linting integrated into build process
- **Continuous Integration**: Automated testing runs on all pull requests

## Pantheon Integration

- Uses Pantheon's Drupal 10 upstream
- Database and files can be synced from Pantheon environments
- Deployment managed through Pantheon's workflow
- Environment-specific configuration handled through config splits