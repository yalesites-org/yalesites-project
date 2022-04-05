# Local Development Environment

This project supports development with Lando using the Pantheon recipe. Most local tooling, including Composer, Drush, Drupal Console, and node applications are available through this recipe.

## Requirements

* [Composer](https://getcomposer.org/download/): PHP package manager. Version 2.x.
* [Terminus](https://pantheon.io/docs/terminus): Pantheon CLI.
* [Docker](https://docs.docker.com/install): Container system for virtualization.
* [Lando](https://docs.lando.dev/basics/installation.html#system-requirements): Recipes and tools for Docker.

## Setup

* [Lando CA](https://docs.devwithlando.io/config/security.html): Certificate authority setup.
* [Create a machine token](https://pantheon.io/docs/machine-tokens) and log authenticate with Terminus.
* Copy `.lando.local.example.yml` to `.lando.local.yml` and set the Pantheon environmental variables for the site you wish to work on.
* Run `lando start`
* Run `lando pull`
