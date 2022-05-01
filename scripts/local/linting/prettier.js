/**
 * Linting for formatting.
 */
const shell = require("shelljs");

const lintIncludes =
    ".github," +
    "docs," +
    "web/profiles/custom," +
    "web/modules/custom," +
    "web/themes/custom";

const lintExt =
    "json," +
    "md," +
    "js," +
    "html," +
    "scss," +
    "html";

// If fix is passed in as an argument, fix the issues that are found too.
// Example usage: `npm run prettier fix`.
const tryFix = process.argv[2] === "fix";

// Linting note.
shell.echo("Linting custom project code for formatting...");

// Lint for JS:
shell.exec(`prettier *.{json,README.md,js} '{${lintIncludes}}/**/*.{${lintExt}}'`);

if (tryFix) {
    shell.echo("Fixing...");
    shell.exec(`prettier --write *.{json,README.md,js} '{${lintIncludes}}/**/*.{${lintExt}}'`);
}

shell.exit();