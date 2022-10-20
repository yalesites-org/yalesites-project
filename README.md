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

## Common Scripts

These are the most common npm scripts you may find yourself using:
(Each is prefixed with `npm run `)

### PR Reviews

- `build` Builds your current project branch _on top of your existing db_. Useful when you just need to check a config update, but don't want to lose your local content etc.
- `build-with-assets` Builds your current project branch _on a clean import of your latest reference db_. Useful when you want to see exactly what the current branch will do to the dev Pantheon instance.
- `local:review-with-atomic-branch` Will ask which branch of Atomic you want to review. Useful when you need to review an atomic PR that doesn't have associated component library changes.
- `local:review-with-cl-branch` Will ask which branch of the component library you want to review. Useful when you need to review a CL PR that doesn't have associated Atomic changes.
- `local:review-with-atomic-and-cl-branch` Runs each of the scripts above, in order, so that you can review changes that span both the Atomic and CL repos.

### Database

- `db:get` Download the latest backup from Pantheon's dev instance.
- `db:import` Imports the last db you fetched.
- `db:local-export` Will export the current state of your local db. Handy if you want to switch to another ticket, like a PR review, and want to be able to get your content back.

### Active Development

- `confex` Exports your local drupal config.
- `confim` Imports the current config files to your database.
- `local:cl-dev` Enables a frontend developer to works across all three repositories (`yalesites-project`, `atomic`, and `component-library-twig`) in an environment configured to support both Storybook development, and have the changes reflected in the Drupal instance.
