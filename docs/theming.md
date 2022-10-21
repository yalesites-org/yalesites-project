## Reviewing and Developing Theme and/or Component Library Changes

_NOTE: All commands must be run from the project root!_

### Active Development

_Note: This workflow applies when you are making active changes to the Atomic and/or component library repos, and want to validate everything in Drupal. E.g., when you're wiring up a component, and realize there are required CL changes as well as Atomic_

- `npm run local:cl-dev`

This will set up some under-the-hood linking so that you can actively work on the component library (in Storybook) and those changes will also be immediately available to the Drupal install when you refresh a page (although, you may need to clear cache depending on your Drupal setup).

### Review an Atomic only PR

_Note: This workflow applies if the changes in Atomic work with the latest **release** of the component library. Not work in an active CL PR (including a release PR)._

- `npm run local:review-with-atomic-branch`

You should now have the selected branch of the Atomic theme installed, with the latest release of the component library.

### Review a Component Library only PR

_Note: This workflow applies if there are no changes to Atomic, but you need to verify the component library changes appropriately affect the Drupal install._

- `npm run local:review-with-cl-branch`

You should now have the selected branch of the component library built inside of atomic so that you can review its changes inside your Drupal environment.

### Review a change that has a PR in both Atomic and the Component Library

_Note: This workflow applies if there are changes in **BOTH** Atomic, and the component library that need to be reviewed in Drupal._

- `npm run local:review-with-atomic-and-cl-branch`

You should now have the selected branch of the Atomic theme installed, with the selected branch of the component library built - all wired up to be used by Drupal.
