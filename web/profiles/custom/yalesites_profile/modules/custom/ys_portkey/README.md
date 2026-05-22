# Portkey AI Provider

Provides [Portkey](https://portkey.ai) AI gateway integration for the
[Drupal AI module](https://www.drupal.org/project/ai).

Portkey is an OpenAI-compatible gateway that routes requests to various AI
providers (Anthropic, OpenAI, Google, etc.). This module uses the OpenAI PHP SDK
pointed at Portkey's gateway endpoint.

## Requirements

- [AI module](https://www.drupal.org/project/ai) (ai)
- [Key module](https://www.drupal.org/project/key) (key)
- [openai-php/client](https://github.com/openai-php/client) (installed via Composer)

## Installation

1. Enable the module: `drush en ys_portkey`
2. Create a Key entity at `/admin/config/system/keys` containing your Portkey API key.
3. Add model instances at `/admin/config/ai/providers/portkey`.

## Configuration

This module supports multiple model instances, each with independent settings.
You can configure separate models for chat and embeddings operations, each with
their own API key, gateway URL, and custom headers.

### Adding a model

1. Navigate to `/admin/config/ai/providers/portkey`.
2. Click "Add Chat Model" or "Add Embeddings Model".
3. Fill in the model configuration:
   - **Model ID**: A unique identifier (e.g., `claude-chat`, `text-embedding`).
   - **Label**: A human-readable name.
   - **Portkey API Key**: Select a Key entity containing your Portkey API key.
     This value is sent as the `x-portkey-api-key` HTTP header.
   - **Gateway URL**: Defaults to `https://api.portkey.ai/v1`. Override for
     self-hosted Portkey gateway deployments.
   - **Custom Headers**: Additional HTTP headers sent with every request. One per
     line in `Header-Name: value` format.
   - Operation-specific settings (e.g., max tokens, temperature for chat;
     dimensions for embeddings).
4. Set default providers at `/admin/config/ai/settings`.

### Using Key module placeholders in custom headers

Custom header values support `[key:key_name]` placeholders. These are resolved at
runtime via the Key module, keeping secrets out of plain config.

Example:
```
x-portkey-config: [key:portkey_config_key]
```

## Testing

Run all module tests:

```bash
lando php web/core/scripts/run-tests.sh --module ys_portkey
```

Run a specific test class:

```bash
lando php web/core/scripts/run-tests.sh --class 'Drupal\Tests\ys_portkey\Kernel\PortkeyProviderTest'
lando php web/core/scripts/run-tests.sh --class 'Drupal\Tests\ys_portkey\Kernel\PortkeyMigrationTest'
lando php web/core/scripts/run-tests.sh --class 'Drupal\Tests\ys_portkey\Unit\PortkeyConfigFormTest'
```

## Supported Operations

- **Chat** -- conversational AI with streaming support
- **Embeddings** -- text-to-vector conversion
