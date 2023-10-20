# YaleSites Core

## Description
The YaleSites Core Functionality module serves as the central repository for organizing custom functionality that is fundamental to the YaleSites platform. Within this module, you'll find templates, assets, configuration overrides, and custom code that are vital for the seamless operation of all sites hosted on the platform.

However, before adding new features or functionality to this module, platform developers are encouraged to contemplate the creation of separate custom modules. These custom modules can encapsulate specific sets of features, enhancing modularity and ensuring that the codebase remains clean and organized. This approach promotes a more efficient and maintainable development process for the YaleSites platform.

## Features
- **Sitewide Elements**: This category covers a wide array of elements including plugins, forms, templates, and various assets used for managing sitewide components such as the site header, footer, and breadcrumbs. These elements may also extend into the styling realm within the Atomic theme and the component library.
- **Install Configuration**: The module houses default values for YaleSites-specific configuration files used during the creation of new sites on the platform. While technically not mandatory, maintaining these install files is considered a best practice, as they ensure consistency and serve as a reference point for values that should ideally reside in the profile's config/sync directory.
- **Hooks and Custom Functionality**: It provides a growing list of hooks for adding and altering form elements, tokens, caching rules, and website behavior. These hooks empower developers to customize and fine-tune the platform's behavior to meet specific requirements.
