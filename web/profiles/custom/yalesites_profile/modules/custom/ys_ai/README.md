YS AI
=====

YaleSites specific use of the ai_engine.

Instructions
------------

These are custom settings for the ai engine to do things outside of the scope of ai_engine and more in scope of YaleSites.

## Chat Button Icons

This module overrides the ai_engine_chat button template to use YaleSites component-library-twig SVG sprites instead of Font Awesome icon fonts.

### Required Icons in component-library-twig

The following SVG icons must be present in `@yalesites-org/component-library-twig`:

- `fa-comments-light.svg` - Chat bubble icon (light weight)
- `fa-sparkles-light.svg` - Sparkles/AI assistant icon (light weight)

These icons should be:
1. Downloaded from Font Awesome Pro (light weight)
2. Added to `images/icons/` directory in component-library-twig
3. Included in the sprite build process
4. Published in a new component-library-twig version

### Icon Sprite Path

The template expects icons at:
```
/themes/contrib/atomic/node_modules/@yalesites-org/component-library-twig/dist/icons.svg
```

With sprite IDs:
- `fa-comments-light`
- `fa-sparkles-light`

### Template Override

The template override is located at `templates/ai-engine-chat-button.html.twig` and is registered via `hook_theme()` in `ys_ai.module`.

### Admin Configuration

Icons are configured at `/admin/config/ai-engine/chat-settings`. The admin form provides these options:
- **Chat (default)**: Uses `fa-comments` → becomes `fa-comments-light` in sprite
- **Sparkles**: Uses `fa-sparkles` → becomes `fa-sparkles-light` in sprite

The template automatically appends `-light` to the icon class to match the sprite naming convention.
