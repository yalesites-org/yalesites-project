## Developing this theme

Do not edit the files in the `css` directory. Any changes will be overwritten next time a build is run.

If node_modules doesn't already exist, make sure to `npm i -D` to install devDependencies so you can generate new css files.

Instead, run the develop script (`npm run develop`) and edit files in the `scss` directory. The css will be compiled automatically.

If you add a new scss file, you will also need to add it to a library, and have it attached somewhere before its styles will be used by the theme.

## Tokens

This theme utilizes the tokens CSS to help style the admin pages.  As part of the develop script above, it will attempt to copy the file for check-in to the repo.

You can also do this only by executing the script `npm run copy-tokens`.
