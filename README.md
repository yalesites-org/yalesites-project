# YaleSites Project

The YaleSites platform empowers the Yale community to create digital experiences for the web in applications that are secure, cost-effective, accessible, and sustainable. This project repository contains the tooling, configuration, and scaffolding required to create sites on the platform. This project includes:

- Pantheon custom upstream for Drupal 9
- Local development environment and tooling
- Code quality, testing, and build tools
- Continuous integration and delivery support

## Contributing

- [Review contribution guidelines](/docs/CONTRIBUTING.md)
- [Setup a local development environment](/docs/setup.md)
- [Getting started as a developer](/docs/development.md)
- [Learn about the project architecture](/docs/upstream.md)
- [Frontend review and development](/docs/theming.md)
- [Working with component themes and global themes](/docs/color-theme.md)

## Common Scripts

These are the most common npm scripts you may find yourself using:
(Each is prefixed with `npm run `)

### PR Reviews

- `build` Builds your current project branch _on top of your existing db_. Useful when you just need to check a config update, but don't want to lose your local content etc.
- `build-with-assets` Builds your current project branch _on a clean import of your Pantheon reference db_. Useful when you want to see exactly what the current branch will do to the dev Pantheon instance.
- `build-with-install` Builds your current project branch _with a clean site install (empty database)_. Useful when your branch includes a breaking change and the existing database would produce errors.
- `local:review-with-atomic-branch` Will ask which branch of Atomic you want to review. Useful when you need to review an atomic PR that doesn't have associated component library changes.
- `local:review-with-cl-branch` Will ask which branch of the component library you want to review. Useful when you need to review a CL PR that doesn't have associated Atomic changes.
- `local:review-with-atomic-and-cl-branch` Runs each of the scripts above, in order, so that you can review changes that span both the Atomic and CL repos.

_Notes:_

- _For the last three `local:` scripts, you'll still need to run one of the the `build` scripts to import config etc. for Drupal. They just get the Atomic and Component Library setup._
- _During the initial build phase, we're introducing a lot of breaking changes, and instead of writing update hooks, we're just doing fresh site installs. Once the platform is live, we'll need to stop doing that, and we should get rid of the `build-with-install` script, as we'll no longer support that workflow._

### Active Development

- `confex` Exports your local drupal config.
- `confim` Imports the current config files to your database.
- `local:theme-link` Run this script once to establish `npm link`s to all of the frontend-related repositories.
- `local:cl-dev` Enables a frontend developer to work across all of the repositories (`yalesites-project`, `atomic`, and `component-library-twig`) in an environment configured to support both Storybook development, and have the changes reflected in the Drupal instance. Note: This also wires up the Tokens repo, but if you want to watch for changes there, you'll have to run the `npm run develop` script inside the Tokens directory.

*** Generate multidev only ***
