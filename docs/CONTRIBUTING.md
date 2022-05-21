# How to contribute

## Commit messages

Commit code often and use the imperative mood when describing the nature of the change. This project uses [Conventional Commits](https://www.conventionalcommits.org/) using the [Angular standards](https://github.com/angular/angular/blob/22b96b9/CONTRIBUTING.md#-commit-message-guidelines) as part of the release management strategy.

<details>
  <summary>View all commit types</summary>

| Type            | SemVer | Purpose                                                  |
| --------------- | ------ | -------------------------------------------------------- |
| fix             | patch  | Resolving a bug or issue in existing code                |
| feat            | minor  | Introduces a new feature into the codebase               |
| style           | patch  | Formatting, whitespace, and other non-functional changes |
| chore           | patch  | Changes that do not affect production                    |
| docs            | none   | Adding or editing documentation                          |
| refactor        | patch  | Neither fixes a bug nor adds a feature                   |
| test            | none   | Adds or updates test files                               |
| ci              | patch  | Changes to continuous integration                        |
| build           | patch  | Changes to build files                                   |
| BREAKING CHANGE | major  | Introduces a breaking API change                         |

</details>

```bash
type(scope): commit message
^--^ ^---^   ^------------^
|    |       |
|    |       +-> Summary in present tense, lower case
|    |
|    +----> JIRA ticket number
|
+-----> Type: fix, feat, style, docs, etc...
```

View [example conventional commit](https://www.conventionalcommits.org/en/v1.0.0/#examples) messages.

## Git workflow

This project follows a standard Git flow for managing and deploying changes. The following describes a typical flow for contributing work through standard feature branches or through hotfixes.

### Protected branches

Two branches are protected in GitHub to ensure all changes are tested and that work moves through the approval workflow.

- `master` contains code ready for release in production environments. Work on this branch may appear in the Test and Live Pantheon environments.
- `develop` is the default branch and contains code ready for integration testing. It only contains code that has gone through peer review and is ready to be released. Work on this branch may appear in the Dev Pantheon environment.

### Feature branches

All planned development work should take place on dedicated feature branches outside of the master/main and develop branches. Keep branches as lightweight as possible, restricting pull requests to only the requirements necessary for the assigned ticket.

- Feature branches are created from the `develop` branch to ensure the latest approved changes are included in the work.
- Use the naming convention `YALB-###-short-description` where ### represents a Jira ticket number and the short description expresses the purpose of the feature in a few hyphenated keywords.
- When the feature is complete, a pull request should be created to merge the feature branch into the develop branch.

### Hotfixes

Hotfixes are a mechanism to quickly patch production releases without interrupting the rest of the Git workflow. Hotfixes can be created to fix urgent issues, but are generally discouraged unless of vital importance.

- Hotfix branches are created from the `master` branch to ensure the changes do not interfere with other work pending approval or release.
- Use the naming convention `hotfix/short-description` where the short description expresses the purpose of the patch in a few hyphenated keywords.
- When the work is ready, a pull request should be created to merge the hotfix branch into the master branch.

## Pull requests

All changes are merged into this project using GitHub Pull Requests. The process of moving work through a PR ensures changes are reviewed in a public and collaborative way.

### Review and approval workflow

- Follow the naming convention `YALB-###: Short description` where ### is the JIRA ticket number and a short description expresses the purpose of the work. This should be consistent with the branch naming convention.
- Use the [YaleSite-org PR template](https://github.com/yalesites-org/.github/blob/main/.github/PULL_REQUEST_TEMPLATE.md) as it outlines the minimum information required to describe the change. This includes JIRA ticket numbers, a description of the changes, and step-by-step instructions for validating the work.
- The contributor responsible for the work should mark themselves as the `assignee`. PRs can be made ahead of a review by applying the `work in progress` label.
- When ready for review, add 1 or more `reviewers` to the PR and change the label to `ready for review`. Members of the YaleSites project team should also post a link to the PR in the corresponding JIRA ticket and any relevant Teams channel and tagging the reviewers.
- Reviewers will test code quality, functionality, design, and other disciplines as needed. Add additional reviewers if the work is beyond your skillset. The work is then approved or changes are requested. Please provide meaningful feedback using the GitHub review system.
- Once approved, the `assignee` will merge the changes, close the PR, delete the branch, and move the JIRA ticket to 'Done'.

### GitHub labels

Labels are added and removed to PRs moving through the approval workflow. Typically the changes can be merged when the PR has the ‘pass code review’, ‘pass functional review’, and ‘ready to merge’ labels.

| Label                  | Purpose                                                  |
| ---------------------- | -------------------------------------------------------- |
| work in progress       | The assignee is still making changes to this branch      |
| needs review           | The work is ready for testing and reviewers are assigned |
| review in progress     | Indicates the review process is underway                 |
| pass code review       | All changes follow coding standards and best practices   |
| pass functional review | The work functions as described in the JIRA ticket       |
| ready to merge         | The changes are approved and ready to be merged          |
| needs work             | The PR did not pass review and feedback was provided     |
| needs help             | Use if help is needed; leaving details in the comments   |
| don't merge            | Do not merge the work; explanation in the comments       |

### Best practices

- Generally, pull requests should resolve a single Jira ticket. Try to avoid combining multiple tickets into a single pull request. There may be instances where it makes sense to do otherwise but please use discretion.
- Avoid long-standing PRs as the work can become a bottleneck or create future merge conflicts. Regularly ping reviewers in Teams and JIRA to ensure the review process can move swiftly.
- Try to keep pull requests reasonably small and discrete. If you are beginning to work on a story and it feels like it will result in a giant pull request with lots of custom code, changes across many features, and lots of testing scenarios, think about how you might break down the story into smaller subtasks that could be incrementally developed and tested. Create subtasks or potentially even new stories within JIRA. If you are unsure about how or are unable to do this, please reach out to the project Product Owner.

## Code standards

Development standards will be rigorously enforced on this project. Drupal maintains coding standards that apply to the core and contributed code. This is the basis for code linting and should be considered a reference when there are uncertainties. While linting is required, the following resources are more aspirational:

- [Drupal Coding standards](https://www.drupal.org/docs/develop/standards)
- [Twig coding standards](https://www.drupal.org/docs/develop/coding-standards/twig-coding-standards)
- [JavaScript coding stand](https://www.drupal.org/docs/develop/standards/javascript)
- [CSS Architecture](https://www.drupal.org/docs/develop/standards/css/css-architecture-for-drupal-8)

### Linting

To run the linting, you'll need to create a Github Personal Access token to be able to pull down the Yale package.

- Visit the [Github instructions for creating a Personal Access Token](https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/creating-a-personal-access-token).
- Give it "Write packages" permissions. This will also check the repo permissions.
- Export the token from your ~/.bash_profile or ~/.bashrc file by adding:
  `export YALESITES_BUILD_TOKEN=[token]`
- Source the file with `source ~/.bash_profile` or `source ~/.bashrc`.

To run linting, you'll need to install packages in the project root and in web/core:

- cd into web/core with `cd web/core` and run `yarn`
- cd back to the root with `cd ../../` and run `npm install`

Available npm commands for testing. All commands below can be run with `npm run [command]`. For example, `npm run lint:js`.
Adding 'fix' onto each command will automatically fix the found issues where possible. For example, `npm run lint fix`.

- prettier - runs prettier code beautifier tool on docs, .github, custom modules, and custom themes directories.
- lint:js - uses eslint to validate the JS in custom modules and themes.
- lint:style - uses eslint to validate the CSS in custom modules and themes.
- lint:php - runs PHPCS with Drupal standards.
- lint - runs lint:styles, lint:js, and lint:php.
- test - runs lint:styles, lint:js, lint:php and prettier.
