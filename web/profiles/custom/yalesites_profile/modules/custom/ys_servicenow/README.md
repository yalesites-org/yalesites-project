# YaleSites ServiceNow

This is a YaleSites integration to allow ServiceNow knowledge base articles to import into the platform.  The ultimate goal of this is to allow it to then go through our AI pipeline so that you can ask questions that will consider the articles synced.

## Features

Features include:

- Key-based authentication
- Block-based syncing

## Usage

1. Install the module
2. Create a new key in the keys module with the ServiceNow endpoint username and password credentials
3. Visit the ServiceNow Settings under Configuration
4. Enable the module and select the key you created
5. Save
6. Upon reload, you'll notice a Sync button; click this button to do a manual sync
