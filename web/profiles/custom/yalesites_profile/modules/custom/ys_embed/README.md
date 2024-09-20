# YaleSites Embed

The ys_embed module allows site owners to store embedded content in the Drupal media library. Embedded content can be pulled from multiple, approved media sources and elements are rendered using an approved set of templates.

## Background

Media in Drupal core already follows an abstract model where any collection of content may be considered a media element. Media is more than an image, a video, or a file. It may contain multiple assets and supporting metadata. For example, a video element may include the video itself (via streaming service), a transcript (text), an alternative description (audio), and a thumbnail (image).

In the same way, we are expanding our definition to include different types of embedded content such as social media, streaming media, and forms. This currently includes content rendered in an iframe or content rendered via JavaScript requests. In the future this may include content fetch using an oEmbed source.

## Features and organization

### @MediaSource Plugin

This module defines a @MediaSource plugin for adding embedded content to the media library. Media sources provide the critical link between media items in Drupal and the actual media itself, which typically exists independently of Drupal.

- [Code: Embed MediaSource](./src/Plugin/media/Source/Embed.php)
- [Community documentation](https://www.drupal.org/docs/8/core/modules/media/creating-a-custom-mediasource-plugin-for-external-assets)

### @EmbedSource Plugin and Manager Service

Plugins provide an object-oriented mechanism for organizing code in Drupal. The EmbedSource plugin created in this module helps to organize the storage, validatation, and rendering of embedded content. Plugins are created for each embed source, including X, Instagram, Microsoft PowerBI, and Qualtrics. A 'Broken' source is added as a useful tool for ensuring code fails gracefully when encountering legacy/broken items in the future. A plugin manager allows developers to find and instantiate EmbedSource plugins.

- [Code: Plugin Interface](./src/Plugin/EmbedSourceInterface.php)
- [Code: Plugin Annotation](./src/Annotation/EmbedSource.php)
- [Code: Plugin Manager](./src/Plugin/EmbedSourceManager.php)
- [Community documentation](https://www.drupal.org/docs/drupal-apis/plugin-api)

### Embed Field

A custom 'Embed' field type stores embed code and related metadata. The field defines an overloaded database table so that future embed types have a space for storing a variety of values. Some embed sources only require a URL while others require a series of parameters. This user-provided code is stored in the 'input' field. Additional metadata is stored in the the serialized/blob 'param' field.

- [Code: Embed Field Type](./src/Plugin/Field/FieldType/Embed.php)
- [Community documentation](https://www.drupal.org/docs/creating-custom-modules/creating-custom-field-types-widgets-and-formatters/create-a-custom-field-type)

A custom widget defines the interface for storing embed-data. This class includes logic for massaging data into the correct storage format.

- [Code: Embed Field Widget](./src/Plugin/Field/FieldWidget/EmbedDefaultWidget.php)
- [Community documentation](https://www.drupal.org/docs/creating-custom-modules/creating-custom-field-types-widgets-and-formatters/create-a-custom-field-widget)

A custom formatter manages the field rendering logic. Instead of rendering the user-provided embed code directly, the formatter combines stored parameters into an inline template.

- [Code: Embed Field Type](./src/Plugin/Field/FieldFormatter/EmbedDefaultFormatter.php)
- [Community documentation](https://www.drupal.org/docs/creating-custom-modules/creating-custom-field-types-widgets-and-formatters/create-a-custom-field-formatter)
