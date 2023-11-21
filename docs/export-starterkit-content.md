# Exporting starterkit content

## Purpose

There are certain YaleSite sites whose purpose is to provide a starting set of
pages for new YaleSites to use. This is a way to export the content of those
sites for import into a new site.  This document will focus on the yalesites
starterkit site. (ys-starterkit)

## What does it do?

This will attempt to execute an export of the pantheon site using the terminus
command, then SFTP to it to retrieve the zip file, and attempt to unzip it into
a git repository to make a new commit.

New commits will look like the following:

`Content update: <date>`

## Prerequisites

1. The following installed:
    1. Terminus
    2. Sftp
    3. Git
    4. tr (most linux/bsd/unix based systems have this)
1. [Terminus with auth tokens already provided](https://github.com/yalesites-org/yalesites-project/blob/develop/docs/setup.md#terminus)

## Usage

1. Clone this repository.
1. Clone the [yalesites-starterkit](https://github.com/yalesites-org/yalesites-starterkit) repository at a separate location.
1. In this local repository, you'll run the following:
  
  ```sh
  SITE_MACHINE_NAME=<pantheon site name> \
  ./scripts/local/starterkit/create-export \
  <location of yalesites-starterkit>/starterkit
  ```
  
4. SITE_MACHINE_NAME is the name of the Pantheon site you want to export from.
   (i.e. ys-starterkit.dev)

## Where can I get this information?

* PANTHEON_NAME: This is the name of the site as it appears in the Pantheon
  dashboard.
