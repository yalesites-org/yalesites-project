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
3. Configure the module at `/admin/config/ai/providers/portkey`.

## Configuration

- **Portkey API Key**: Select a Key entity containing your Portkey API key. This
  value is sent as the `x-portkey-api-key` HTTP header on every request.
- **Gateway URL**: Defaults to `https://api.portkey.ai/v1`. Override for self-hosted
  Portkey gateway deployments.
- **Model Name**: The model ID passed in API requests (e.g., `claude-sonnet-4-20250514`).
  Portkey handles actual model routing based on your Portkey configuration.
- **Custom Headers**: Additional HTTP headers sent with every request. One per line
  in `Header-Name: value` format.

### Using Key module placeholders in custom headers

Custom header values support `[key:key_name]` placeholders. These are resolved at
runtime via the Key module, keeping secrets out of plain config.

Example:
```
x-portkey-config: [key:portkey_config_key]
```

## Supported Operations

- **Chat** -- conversational AI with streaming support
- **Embeddings** -- text-to-vector conversion
