# YaleSites Twig Component Library

All YaleSites projects have access to components in the YaleSites component library. Themes may choose which variations of components to implement, and options are available.

## Installation

<details><summary>Prerequisites</summary>

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

</details>

### Installing the component library locally

If you want to work directly on the component library locally, you can clone this repo and install the dependencies as described below.

1. Clone the repository `git clone git@github.com:yalesites-org/component-library-twig.git`
2. Change directories into the repo `cd component-library-twig`
3. Verify you're using the correct version of node `nvm use`
4. Install dependencies `npm install`
5. Run the develop script `npm run develop`
6. Make your changes and commit them!

### Installing the package in another project

There must be a `.npmrc` file in the project root that tells npm to get `@yalesites-org` packages from GitHub rather than npm.

- Create a `.npmrc` file in your project root (or modify an existing one) and add the following:

```bash
@yalesites-org:registry=https://npm.pkg.github.com
```

Then you can install the package like any other npm dependency.

```bash
npm install @yalesites-org/component-library-twig
```

## Releases

Any time something is pushed to the `main` branch on GitHub, a [GitHub Action](.github/workflows/release.yml) is run to determine whether a new release is needed (via semantic-release.)

This is an entirely automated process, so whether changes are pushed directly to `main` or if they go through the preferred PR workflow the release process will be run. Merges into `main` should be performed using a merge commit.

## Live Component Library

You can view the latest version of the component library at any time by visiting the [Deployed Storybook](https://yalesites-org.github.io/component-library-twig). All new commits to the `main` branch trigger a rebuild and deploy of the component library, so it will always be up-to-date with the latest released code.

## Development Component Libraries

The `develop` branch also has an auto-deployed component library. This will contain all of the work that will go out in the next release. It is [deployed to netlify](https://dev-component-library-twig.netlify.app).

PRs also have auto-deployed component libraries, which allow reviewers to load the latest state of a PR at any given time without having to fiddle with local tooling. Each PR will have a link posted to the PR comments by the "netlify bot" when the link is ready. See screenshot below.

![PR preview link is the last link in the Netlify bot comment](./.github/docs/pr-preview-link.png)

## Visual Regression Testing

This project uses Percy's StoryBook integration to visually test and verify components during Pull Requests in GitHub.

View the status of any build here: https://percy.io/95144ff9/component-library-twig

### Running Visreg Tests

**NOTE:** Percy is temporarily auto-approving all branches for now. (Original was only auto-approving `main`)  This will be reverted once we have a better handle on how to manage the visreg tests.

Visual regression tests are automatically run on Percy any time a PR is changed from "Draft" state to "Ready for Review". So there are a few things to keep in mind when creating PRs.

- When you initially create a PR that needs visreg tests, click the "Create draft PR" button instead of the "Create Pull Request" button.

![Click the Create draft PR button](./.github/docs/draft-pr.png)

- Then, when the PR is ready for review, you can click the "Ready for Review" button at the bottom of the page. This will trigger the visual regression tests to be run.

![Click the Ready for Review button when the PR is complete](./.gihub/docs/../../.github/docs/ready-for-review.png)

- If you've already created a PR and need forgot to create a draft first, or you need to re-run the visual regression tests after changes were made, you can click the "Convert to draft" link at the bottom of the page.

![Click the Convert to draft button](./.github/docs/convert-to-draft.png)

- Then click the "Ready for Review" button mentioned above to trigger visual regression tests.

### Configuring Percy Tests

Official Docs: https://docs.percy.io/docs/storybook

By default all new Stories are tested, but stories can be excluded if a test does not provide value in isolation.

In the project root, there is a `.percyrc` file that can be used to modify Percy's configuration, including custom css, and which Stories to skip when testing.

#### Percy-specific CSS

For example, we use the following percy-specific css to hide images from screenshots (since we use a random image service, which would otherwise cause regressions on every test that includes an image.)

```css
img {
  visibility: hidden;
}
```

#### Excluding Stories from Testing

Since Percy bills by the screenshot, it's best to only test the Stories that provide value when tested visually. Some reasons stories should be excluded include:

- Anything that is simply a representation of a base or "primitive" token. These will be represented in the larger components that implement them, so in isolation they don't provide significant value to visual testing.
- Dynamic stories that automatically update when new tokens are added upstream. e.g. The colors story. Since colors aren't really a concern at this level (they're defined in Figma by a designer) we don't really need to test them in isolation here.
- "Playground" types of stories. e.g. The site "Header" story. Since there are a number of choices, or props, that can affect how the site header looks, we have a "playground" story that allows a visitor to toggle all of the controls to see what can be generated with the design system options. What SHOULD be tested is one or more examples of the component with various decisions selected. That is why we have a "Header Examples" story that demonstrates the allowed color combinations.

To exclude stories, add an item to the `exclude` array in a regex format. Some examples of what we currently exclude are:

```yml
storybook:
  exclude:
    - 'Tokens/Breakpoints: Breakpoints' # This specific story (must match exactly)
    - 'Tokens/Effects: [a-zA-Z]+' # Any story in the `Tokens/Effects` section, since they are dynamically generated.
```
