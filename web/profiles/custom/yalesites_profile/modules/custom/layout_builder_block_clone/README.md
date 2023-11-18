Layout Builder Block Clone (Block Duplicate)
===============

CONTENTS OF THIS FILE
---------------------

* Introduction
* Requirements
* Installation
* Maintainers

INTRODUCTION
------------

Layout Builder Block Clone (Block Duplicate) allows site builders to clone Content Blocks from Layout edit page. This module is
helper module for core Layout Builder module.

Module has integration with [Entity Clone](https://www.drupal.org/project/entity_clone) module. If Entity Clone module
is enabled Layout Block Clone will use entity_clone cloneEntity() functionality, if not single core entity->createDuplicate()
will be used to clone Block Content eck.

Cloned Block Content can be set to reusable (if so new Custom Block Content is added to /admin/structure/block/block-content
and can be re-used in layouts under 'Custom' section on 'Add Block'). There is an option to clone any additional config set-ups
from other helper modules or custom code.

REQUIREMENTS
------------

This module requires:
1. Layout Builder (core module)
2. (Optional) it is recommended to use [Add reusable option to inline block creation](https://www.drupal.org/project/drupal/issues/2999491) patch
3. (Optional) [Entity Clone](https://www.drupal.org/project/entity_clone)

INSTALLATION
------------

Install the Layout Builder Block Clone (Block Duplicate) module as you would normally install
any Drupal contrib module.

Visit [Installing Drupal Modules](https://www.drupal.org/node/1897420) for further information.

MAINTAINERS
-----------

The 8.x-1.x branch was created by:

 * Antonija Arbanas (agolubic) - https://www.drupal.org/u/agolubic

This module was created and sponsored by Foreo,
Swedish multi-national beauty brand.

 * Foreo - https://www.foreo.com/
