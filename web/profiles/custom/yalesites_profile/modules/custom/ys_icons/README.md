# YaleSites Icons

A Drupal 10 module that provides FontAwesome icon management through the media library with YDS component integration.

## Features

- **Media Library Integration**: Visual icon selection through familiar media library interface
- **Accessibility**: Built-in ARIA labels and descriptions
- **YDS Component Integration**: Seamless integration with YDS icon components
- **Two Field Formatters**: Raw data output for templates or full YDS component rendering

## Installation

1. Enable the module:
   ```bash
   drush en ys_icons -y
   ```

2. Clear cache:
   ```bash
   drush cr
   ```

## Creating Icons

1. Go to `/admin/content/media`
2. Click "Add media" → "Icon"
3. Fill in:
   - **Name**: User-friendly name (e.g., "Home Icon")
   - **FontAwesome Name**: Technical name (e.g., "home")
   - **Icon Title**: Accessibility title (e.g., "Home")
   - **Icon Description**: Detailed description (e.g., "Navigate to homepage")

## Using in Content Types

1. Add an "Entity reference" field to your content type
2. Configure to reference "Media" entities
3. Set allowed media types to "Icon" only
4. Set widget to "Media library"
5. Choose formatter:
   - **Icon (Raw Data)**: For template integration (recommended)
   - **Icon (YDS Component)**: For standalone display

## Template Usage

For facts-and-figures integration:

```twig
{# Get icon data #}
{% set icon_data = content.field_icon.0 %}
{% set has_icon = icon_data['#fontawesome_name'] ? true : false %}

{% embed "@molecules/facts-and-figures/yds-facts-and-figures.twig" with {
  facts_and_figures__icon_name: icon_data['#fontawesome_name']|default(''),
  facts_and_figures__has_icon: has_icon ? 'true' : 'false',
  # ... other variables
} %}
{% endembed %}
```

## Available Raw Data Variables

When using the "Icon (Raw Data)" formatter:
- `#fontawesome_name`: The FontAwesome icon name
- `#title`: Accessibility title
- `#description`: Accessibility description  
- `#name`: User-friendly display name

## Permissions

Set appropriate permissions at `/admin/people/permissions`:
- **Create icon media**: Content creators
- **Edit icon media**: Content editors
- **Delete icon media**: Content administrators
