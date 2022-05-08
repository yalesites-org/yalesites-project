/**
 * Linting for :all_the_things: + prettier!.
 */

const shell = require("shelljs");

const tryFix = process.argv[2] === "fix" ? 'fix' : '';

shell.exec(`npm run lint ${tryFix}`);
shell.exec(`npm run prettier ${tryFix}`)
