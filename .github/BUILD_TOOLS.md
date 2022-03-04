# Build tools

**Important**
You may need to authorize your machine with your token if you are not able to pull assets.

```
lando terminus auth:login --machine-token=TOKEN
```

### Local Build Commands

`npm run build` - Runs a build to startup local environment, then runs `rebuild` command.\
`npm run export-db` - Exports local database to db-%Y-%m-%d-%H%M.sql.gz file.\
`npm run get-assets` - Acquires database and file assets from the Pantheon test environment. \
`npm run get-db` - Acquires database from the Pantheon dev environment. \
`npm run get-files` - Acquires file from the Pantheon dev environment. \
`npm run import-local-db` - Imports the local database: reference/backup.sql.gz.\
`npm run rebuild` - Refresh to install new requirements, import Drupal database, configuration, and clear caches.\

#### Drupal specific build commands

`npm run confex` - Runs drush config-export to pull configuration from database and into filesystem.\
`npm run confim` - Runs drush config-import but not before updating the database and clears cache before and after the import.

## Linting

`npm run lint` - Lint php code for syntax errors and checks coding standards.

## Theme

This project uses an [Emulsify](https://github.com/emulsify-ds/emulsify-drupal) theme named `yale_sc` (`web/themes/custom/yale_sc/`). For details on Emulsify usage, see that [project wiki](https://docs.emulsify.info/).

#### Theme Tasks

`npm run theme` - Run the theme compiler and watch task for active development. \
`npm run theme-build` - Compile the theme without running the watch task.
