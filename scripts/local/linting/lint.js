/**
 * Linting for :all_the_things:!.
 */

const shell = require("shelljs");

const tryFix = process.argv[2] === "fix" ? 'fix' : '';

shell.exec(`npm run lint:styles ${tryFix}`);
shell.exec(`npm run lint:js ${tryFix}`);
shell.exec(`npm run lint:php ${tryFix}`);




