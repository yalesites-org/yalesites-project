/**
 * Linting for custom JS.
 */
const shell = require("shelljs");

const lintIncludes =
    "web/modules/custom," +
    "web/themes/custom," +
    "web/profiles/custom";

// If fix is passed in as an argument, fix the issues that are found too.
// Example usage: `npm run lint:styles fix`.
const tryFix = process.argv[2] === "fix" ? '--fix' : '';

// Linting note.
shell.echo("Linting custom project code for Javascript...");

// Lint for JS:
shell.exec(`eslint --color --format stylish ${tryFix} {${lintIncludes}}/**/*.js`);

shell.exit();


