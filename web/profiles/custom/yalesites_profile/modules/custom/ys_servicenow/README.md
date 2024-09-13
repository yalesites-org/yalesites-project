# YaleSites ServiceNow

This is a YaleSites integration to allow ServiceNow knowledge base articles to import into the platform.  The ultimate goal of this is to allow it to then go through our AI pipeline so that you can ask questions that will consider the articles synced.

## Features

Features include:

- Key-based authentication
- Block-based syncing

## Usage

1. Install the module
2. Create a new key in the keys module with the ServiceNow endpoint username and password credentials (see pantheon secrets below)
3. Visit the ServiceNow Settings under Configuration
4. Enable the module
5. Select the key you created from the drop down
6. Enter the endpoint URL you were given by the ServiceNow Team
   1. Ensure that the following fields are present in the JSON output:
      1. number: The KB article number
      2. short_description: The title of the article
      3. text: The body of the article
      4. workflow_state: The state of the article (Published, etc)
7. Save
8. Upon reload, you'll notice a Sync button; click this button to do a manual sync

The service once turned on will attempt to sync hourly.

## Pantheon Secrets

Pantheon secrets can be used with their Drupal module to interact with the keys module.  To do this you'd want to add a pantheon secret at the site level first with empty data:

`terminus secrets:site:set --scope web,user <siteName> servicenow_auth ""`

From there, you can then specify the multidev specific information.  The key must ultimately be a JSON payload of the following:

`terminus secrets:site:set <siteName>.<env> servicenow_auth '{"username":"username","password":"password"}'`

Then simply sync pantheon secrets in the keys configuration to bring in the key; remember that there is a time delay on when that becomes available for sync.
