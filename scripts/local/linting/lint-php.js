/**
 * Linting for custom PHP/JS.
 */
const shell = require("shelljs")

const phpCSArgs =
    "--standard=Drupal " +
    "--extensions=php,inc,module,install,theme --colors " +
    "--ignore=";

const phpCSIncludes =
    "./web/modules/custom " +
    "./web/themes/custom " +
    "./web/profiles/custom ";

// If fix is passed in as an argument, fix the issues that are found too.
// Example usage: `npm run lint:php fix`.
const tryFix = process.argv[2] === "fix";

// Linting note.
shell.echo("Linting custom project code for PHP...");

// Run code beautifier.
if (tryFix) {
    shell.echo("Fixing...");
    shell.exec(`./vendor/bin/phpcbf ${phpCSArgs} ${phpCSIncludes}`);
} else {
    shell.exec(`./vendor/bin/phpcs ${phpCSArgs} ${phpCSIncludes}`);
}

shell.exit();
