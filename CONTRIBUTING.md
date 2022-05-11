# Contributing to Atomic
We love your input! We want to make contributing to this project as easy and transparent as possible, whether it's:

- Reporting a bug
- Submitting a fix
- Discussing the current state of the code

## We Develop with Github
We use github to host code, to track issues and feature requests, as well as accept pull requests.

## We Use Github Flow, So All Code Changes Happen Through Pull Requests
Pull requests are the best way to propose changes to the codebase (we use [Github Flow](https://docs.github.com/en/get-started/quickstart/github-flow)). We actively welcome your pull requests:

1. Clone or Fork the repo and create your branch from `develop`.
2. If you've added code that should be tested, add tests.
3. Ensure the test suite passes.
4. Make sure your code lints.
5. Submit that pull request!

## Write bug reports with detail, background, and sample code or link to view the issue

**Great Bug Reports** tend to have:

- A quick summary and/or background
- Steps to reproduce
  - Be specific!
  - Give sample code if you can.
- What you expected would happen
- What actually happens
- Notes (possibly including why you think this might be happening, or stuff you tried that didn't work)

People *love* thorough bug reports.

## Use a Consistent Coding Style
We have linters and formatters running against the codebase. You can run these manually, or try to commit, and if they fail, your commit will be prohibited.

## Releases
We utilize [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/) with [Commitlint](https://commitlint.js.org/#/), and [semantic-release](https://github.com/semantic-release/semantic-release) to automatically generate semantically incremented releases based on commit messages.

Review the Conventional Commits documentation for full details, but the gist is that commit messages that start with:
- `fix:` will produce a "bug fix" release. e.g. 0.0.X
- `feat:` will produce a "feature" release. e.g. 0.X.0
- a `!` appended to the typ, like: `refactor!:` will produce a "major" release. e.g. X.0.0

There are many other "types" that can be used, and you should read the docs to acquaint yourself. But the point is that the commit messages will be automatically validated (to adhere to the standard) by Commitlint, and parsed by semantic-release to generate a release (including release notes) any time code is merged into `main`. (Typically through a "Release" PR from `develop` into `main`.)
