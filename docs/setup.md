# Local Development Environment

NOTE: Documentation assumes you are using MacOS or a Linux environment.

## Requirements

1. [GitHub](#github)
2. [NVM](#additional-tools)
3. [Node.js (>=8.0)](#additional-tools)
4. [Composer](#additional-tools): Version 2.x.
5. [Terminus](#terminus)
6. [Lando](#lando)
7. Docker: Use the version Lando wants to install and increase memory resources to at least 3GB memory and 3CPUs if possible
8. [Project Files](#project-setup-script)
9. [Project Commands](#project-commands)

### Terminus

Pantheon's Terminus is a command-line tool for managing sites. The CLI is required to run many of the scripts for building, updating, and deploying changes between the local development environment and the Pantheon platform.

1. [Install Terminus](https://pantheon.io/docs/terminus/install/)
2. [Setup a valid machine token](https://pantheon.io/docs/machine-tokens)
3. Log in to terminus using the machine token\
   `terminus auth:login --email=<email@example.com> --machine-token=<machine_token>`
4. [Review documentation](https://pantheon.io/docs/terminus/) to get started with the CLI

### Lando

This project supports development with Lando using the Pantheon recipe. This produces a local development environment that matches the hardware and configuration of the Pantheon platform. Most local tooling, including Composer, Drush, Drupal Console, and node applications are available through this recipe.

1. [Review the preflight checks](https://docs.devwithlando.io/installation/preflight.html)
2. [Download and install the latest release](https://github.com/lando/lando/releases)
3. [Setup local certificate authority](https://docs.devwithlando.io/config/security.html)
4. Increase Docker resources: Locate the 'Resources' section in your Docker preferences. For most architectures, this project requires at least 3GB of memory and 3 CPUs. Additional CPUs and memory may be helpful but should stay under the halfway mark of your total available resources. Also disable the _'Start Docker when you log in'_ setting under the 'General' tab.

### GitHub

While the project can be checked out and run locally without it, one must setup an SSH key on their development machine if they wish to push code on any of the yalesites repositories.
Luckily, [GitHub has an intuitive guide on how to setup an SSH key on your machine and register it with your GitHub Account](https://docs.github.com/en/authentication/connecting-to-github-with-ssh/generating-a-new-ssh-key-and-adding-it-to-the-ssh-agent)

### Additional tools

- [Composer](https://getcomposer.org/download/): PHP package manager. Version 2.x.
- [NVM](https://github.com/nvm-sh/nvm#install--update-script): Recommended for installing and switching between Node versions.
- Node.js (>=8.0). We recommend installing via NVM.

## Project setup script

This repository contains a custom Pantheon Upstream used to create and manage every site on the YaleSites platform. Out of the box this project is not connected to an individual Drupal site. To contribute to this project, we need to connect the local development environment to a Drupal site to leverage the site's files and database.

```bash
# Executing the setup script will prepare the local development environment.
npm run setup
```

## Alternative manual setup instructions

A detailed explanation of the setup script appears below.

```bash
# Step 1: Clone this repository and enter the project directory.
git clone https://github.com/yalesites-org/yalesites-project.git
cd yalesites-project

# Step 2: Ignore the composer.lock file on local dev only.
grep -qxF 'composer.lock' .git/info/exclude || echo 'composer.lock' >> .git/info/exclude
```

The Pantheon Lando recipe can connect the local development environment to a remotely hosted site. Connection information is stored in a local Lando settings file and includes a Pantheon site name and UUID. While we can connect to any site running this upstream, a particular integration environment has been provisioned for engineers working on this repository or the associated YaleSites profile. Connection information for the integration environment is stored in the example Lando local file.

```bash
# Step 3: Create a local Lando settings file to connect to the external environment.
cp .lando.local.example.yml .lando.local.yml
```

Starting Lando will provision the containers required to run a local development environment. The `pull` command can then sync the database and files from a Pantheon-hosted site with the new local site.

```bash
# Step 4: Start Lando and import the remote files and database.
lando start
lando pull --database=dev --files=dev --code=none
lando drush cr
```

Visit the local dev site [https://yalesites-project.lndo.site/](https://yalesites-project.lndo.site/) or run `lando drush uli` to obtain a login link.

## Working on projects within this repository

_The steps in this section are completed in the aforementioned setup script._

The YaleSites platform organizes work across a series of custom modules, themes, and an installation profile. To avoid an unnecessarily monolithic architecture, each of these dependencies exist in unique repositories that are included via composer. The previously created local development environment is an ideal place for working on these projects within the context of a YaleSite.

By default, composer dependencies are downloaded in a dist packaged version of the project with git metadata removed. When working on a Yale-managed package, the originally downloaded composer dependency must be replaced with the source packaged version. This allows any changes to be tracked in version control.

### Atomic theme

The [YaleSites Atomic theme](https://github.com/yalesites-org/atomic) is a flexible Drupal theme based on the YaleSites design system. The theme is included in the YaleSite installation profile and is the default theme for all new web properties.

```bash
# Step 1: Configure Composer to use source packaged versions.
lando composer config --global 'preferred-install.yalesites-org/*' source

# Step 2: Manually remove the originally downloaded dist packaged version.
rm -rf web/themes/contrib/atomic

# Step 3: Use Composer to download the new version of the thme.
lando composer update atomic

# Step 4: Verify that the theme is tracking a remote repository.
git -C web/themes/contrib/atomic ls-remote --get-url
# Returns: https://github.com/yalesites-org/atomic.git

# Step 5: Setup npm linked packages for theme dependencies
npm run local:theme-link
```

## Project Commands

### NPM
```bash
npm run setup                                   # Setup whole environment
npm run build-with-assets                       # Build/Rebuild with replaced assets
npm run build-with-install                      # Rebuild with fresh imports
npm run local:cl-dev                            # npm link component library with local environment
npm run local:review-with-atomic-and-cl-branch  # Use different branches of each repo
npm run local:review-with-atomic-branch         # Use specific atomic branch
npm run local:review-with-cl-branch             # Use specific component library branch
npm run local:git-checkout                      # Sync:
                                                #   yalesites-project: develop branch
                                                #   atomic: main branch
                                                #   component-library: main branch
                                                #   tokens: main branch
npm run local:git-checkout -- -h                # More command help
npm run confex                                  # Export drupal configuration
npm run confim                                  # Import drupal configuration
npm run db:get                                  # Download a dev database locally from a pantheon site
npm run files:get                               # Download dev files locally from a pantheon site
npm run lint                                    # Lints js, php, and styles
npm run fix:js                                  # Fixes js linting errors if possible
npm run local:theme-link                        # Alias for cl-dev but doesn't run CL develop
npm run prettier                                # Runs prettier on repo
npm run test                                    # Runs prettier and linting
```

### Composer
```bash
lando composer code-sniff                       # Test for PHP linting issues that CI tests against
```
