# Local Development Environment

## Requirements

### Terminus

Pantheon's Terminus is an almost-essential command-line tool for managing sites. The CLI is required to run many of the scripts for building, updating, and deploying changes between the local development environment and the Pantheon platform.

1. [Install Terminus](https://pantheon.io/docs/terminus/install/)
2. [Setup a valid machine token](https://pantheon.io/docs/machine-tokens)
3. Log in to terminus using the machine token\
```terminus auth:login --email=<email@example.com> --machine-token=<machine_token>```
4. [Review documentation](https://pantheon.io/docs/terminus/) to get started with the CLI

### Docker

Docker is an open-source container system for setting up virtualized environments. Containers are hardware-agnostic and platform-agnostic meaning they can run anywhere.

1. [Install Docker](https://docs.docker.com/install)
2. Increase your resources: Locate the 'Resources' section in your Docker preferences. For most architectures, this project requires at least 3GB of memory and 3 CPUs. Additional CPUs and memory may be helpful but should stay under the halfway mark of your total available resources.
3. Disable the _'Start Docker when you log in'_ setting under the 'General' tab.

### Lando

This project supports development with Lando using the Pantheon recipe. This produces a local development environment that matches the hardware and configuration of the Pantheon platform. Most local tooling, including Composer, Drush, Drupal Console, and node applications are available through this recipe.

1. [Review the preflight checks](https://docs.devwithlando.io/installation/preflight.html)
2. [Download and install the latest release](https://github.com/lando/lando/releases)
3. [Optional: Setup local certificate authority](https://docs.devwithlando.io/config/security.html)

### Additional tools

* [Composer](https://getcomposer.org/download/): PHP package manager. Version 2.x.
* [NVM](https://github.com/nvm-sh/nvm#install--update-script): Recommended for installing and switching between Node versions.
* Node.js (>=8.0). We recommend installing via NVM.

## Project Setup

This repository contains a custom Pantheon Upstream used to create and manage every site on the YaleSites platform. Out of the box this project is not connected to an individual Drupal site. To contribute to this project, we need to connect the local development environment to a Drupal site to leverage the site's files and database.

```bash
# Step 1: Clone this repository and enter the project directory.
git clone https://github.com/yalesites-org/yalesites-project.git
cd yalesites-project
```

The Pantheon Lando recipe can connect the local development environment to a remotely hosted site. Connection information is stored in a local Lando settings file and includes a Pantheon site name and UUID. While we can connect to any site running this upstream, a particular integration environment has been provisioned for engineers working on this repository or the associated YaleSites profile. Connection information for the integration environment is stored in the example Lando local file.

```bash
# Step 2: Create a local Lando settings file to connect to the external environment.
cp .lando.local.example.yml .lando.local.yml
```

Starting Lando will provision the containers required to run a local development environment. The `pull` command can then sync the database and files from a Pantheon-hosted site with the new local site.

```bash
# Step 3: Start Lando and import the remote files and database.
lando start
lando pull --database=dev --files=dev --code=none
```

Visit the local dev site [https://yalesites-project.lndo.site/](https://yalesites-project.lndo.site/) or run `lando drush uli` to obtain a login link.
