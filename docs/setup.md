# Local Development Environment

__NOTE: Documentation assumes you are using MacOS with at least 8GB of memory on an Intel, M1, or M2 processor__

:clap: Thank you for considering to contribute to the YaleSites Platform!

## Requirements & Setup

1. [Contact YaleSites](mailto:yalesites@yale.edu?subject=Developer%20Access%20Request) for access to resources such as Pantheon, Figma designs, etc.
2. [GitHub](#github)
   1. SSH authorization key
   2. [Github Package Personal Access Token](#package-personal-access-token) in environment variable `YALESITES_BUILD_TOKEN`
3. [NVM](#additional-tools)
4. [Node.js (>= 8.0, < 11.0)](#additional-tools)
5. [Composer](#additional-tools): Version 2.x.
6. [Terminus](#terminus): Machine auth token
7. [Lando](#lando)
8. Docker: Use the version Lando wants to install and increase memory resources to at least 3GB memory and 3 CPUs if possible
9. [Project Setup](#project-setup)
    ```bash
    # Set up local development version of repo
    git clone git@github.com:yalesites-org/yalesites-project
    cd yalesites-project
    npm run setup
    ```
10. [Project Commands](#project-commands)

## Repository Links

Repositories used to make the platform:

* [Yalesites Project](https://github.com/yalesites-org/yalesites-project): Drupal Platform Site (This repo)
   * [Yalsites Project Profile modules](https://github.com/yalesites-org/yalesites-project/tree/develop/web/profiles/custom/yalesites_profile/modules/custom): Each subdirectory should have a README describing the function
* [Atomic Theme](https://github.com/yalesites-org/atomic): Atomic Drupal Theme bridging the Drupal site and the component library
* [Component Library Twig](https://github.com/yalesites-org/component-library-twig): Component Library
   * [Deployed Storybook](https://yalesites-org.github.io/component-library-twig)
* [Tokens](https://github.com/yalesites-org/tokens): Style tokens from Figma used to drive design of the platform and components
* [ESLint Config and Other Formatting](https://github.com/yalesites-org/eslint-config-and-other-formatting): Reusable Linting/Formatting included in the project

## Requirement Details

### GitHub

While the project can be cloned and run locally without it, one must setup an SSH key on their development machine if they wish to push code on any of the yalesites repositories.
Luckily, [GitHub has an intuitive guide on how to setup an SSH key on your machine and register it with your GitHub Account](https://docs.github.com/en/authentication/connecting-to-github-with-ssh/generating-a-new-ssh-key-and-adding-it-to-the-ssh-agent)

#### Package Personal Access Token

Each environment that needs to pull @yalesites-org packages from GitHub needs to be authenticated using a "Personal Access Token". This only needs to be done once per-environment.

- Go to `https://github.com/settings/tokens/new`
  - In the "Note" field add something like "YaleSites GitHub Packages"
  - Choose an expiration value
  - Check the box for "write:packages" (this will automatically check all of the "repo" boxes as well)
  - Click "Generate token"
- On your local machine, create an environment variable. This process varies depending on the shell and operating system you use. It will be something similar to this though: `export KEY=value`.
  - The `key` for YaleSites projects needs to be `YALESITES_BUILD_TOKEN`
  - The `value` is the token you created above
- Done!

- [Here's a stack overflow post showing how to set persistent environment variables for various shells](https://unix.stackexchange.com/questions/117467/how-to-permanently-set-environmental-variables)


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

### Additional tools

- [Composer](https://getcomposer.org/download/): PHP package manager. Version 2.x. (Can use lando instead if you prefer)
- [NVM](https://github.com/nvm-sh/nvm#install--update-script): Node Version Manager
- Node.js (>=8.0,<11.0). Via NVM.

### Cloning the project

To clone the project, the above requirements must be met first.  If you have not already cloned the repository:

`git clone git@github.com:yaleitsites-org/yalesites-project.git`

## Project setup

This repository contains a custom Pantheon Upstream used to create and manage every site on the YaleSites platform. Out of the box this project is not connected to an individual Drupal site. To contribute to this project, we need to connect the local development environment to a Drupal site to leverage the site's files and database.

```bash
# Executing the setup script will prepare the local development environment.
npm run setup
```

Visit the local dev site [https://yalesites-project.lndo.site/](https://yalesites-project.lndo.site/) or run `lando drush uli` to obtain a login link.

## Working on projects within this repository

_The steps in this section are completed in the aforementioned setup script._

The YaleSites platform organizes work across a series of custom modules, themes, and an installation profile. To avoid an unnecessarily monolithic architecture, each of these dependencies exist in unique repositories that are included via composer. The previously created local development environment is an ideal place for working on these projects within the context of a YaleSite.

By default, composer dependencies are downloaded in a dist packaged version of the project with git metadata removed. When working on a Yale-managed package such as Atomic or the Component Library, the originally downloaded composer dependency must be replaced with the source packaged version. This allows any changes to be tracked in version control.

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

__NOTE: There are more commands, but these are those that current developers feel are most used.__

### NPM

```bash
npm run setup                                   # Setup whole environment
```
```bash
npm run build-with-assets                       # Build/Rebuild with replaced assets
```
```bash
npm run build-with-install                      # Rebuild with fresh imports
```
```bash
npm run local:cl-dev                            # npm link component library with local environment
```
```bash
npm run local:review-with-atomic-and-cl-branch  # Use different branches of each repo
```
```bash
npm run local:review-with-atomic-branch         # Use specific atomic branch
```
```bash
npm run local:review-with-cl-branch             # Use specific component library branch
```
```bash
npm run local:git-checkout                      # Sync:
                                                #   yalesites-project: develop branch
                                                #   atomic: main branch
                                                #   component-library: main branch
                                                #   tokens: main branch
```
```bash
npm run local:git-checkout -- -h                # More command help
```
```bash
npm run confex                                  # Export drupal configuration
```
```bash
npm run confim                                  # Import drupal configuration
```
```bash
npm run db:get                                  # Download a dev database locally from a pantheon site
```
```bash
npm run files:get                               # Download dev files locally from a pantheon site
```
```bash
npm run lint                                    # Lints js, php, and styles
```
```bash
npm run fix:js                                  # Fixes js linting errors if possible
```
```bash
npm run local:theme-link                        # Alias for cl-dev but doesn't run CL develop
```
```bash
npm run prettier                                # Runs prettier on repo
```
```bash
npm run test                                    # Runs prettier and linting
```

### Composer
```bash
lando composer code-sniff                       # Test for PHP linting issues that CI tests against
```
