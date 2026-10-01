# AI Provider Azure

Microsoft Azure AI provider for the Backdrop CMS AI module.

Adds Azure OpenAI Service deployments, and OpenAI-compatible Azure AI Studio
(Model Catalog / serverless) endpoints, to the providers the `ai` module can
route to.

## Supported operations

| Operation | Supported | Notes |
|---|---|---|
| Chat | Yes | Streaming supported. JSON mode and JSON schema responses supported. |
| Completions | Yes | Uses the deployment's `completions` endpoint. |
| Tool calling | Yes | GPT-4 / o-series deployments. |
| Vision | Yes | GPT-4 / o-series deployments. |
| Embeddings | Yes | `text-embedding-*` deployments. |
| Image generation | Yes | DALL-E deployments. |
| Moderation | No | |
| Speech-to-text | No | |

## Endpoints and deployments

Azure routes requests by deployment name rather than model name. The provider
settings at `admin/config/ai/settings` add:

- **Azure Resource Name** — builds
  `https://{resource}.openai.azure.com/openai/deployments/{deployment}/...`.
- **Custom Endpoint** — overrides the resource URL for Azure AI Studio
  serverless endpoints or private gateways. A URL containing `/v1` is used as an
  OpenAI-compatible base; one containing `/openai/deployments` is used as-is.
- **API Version** — the `api-version` query parameter (default `2024-10-21`).
- **Deployment Names / Model Mapping** — one per line, either
  `deployment_name` or `alias=deployment_name`. These become the model list.
  When empty, a fixed list of standard model names is offered and each is used
  directly as the deployment name.

Capabilities are inferred from the model or alias name, so name aliases after
the underlying model (for example `gpt-4o=my-gpt4o-deployment`).

## Installation

- Install this module using the official [Backdrop CMS instructions](https://backdropcms.org/user-guide/modules).
- Create an authentication key with the Key module holding your Azure API key
  (sent as the `api-key` header).
- Enable and configure the provider at `admin/config/ai/settings`.

## Issues

Bugs and feature requests should be reported in the [Issue Queue](https://github.com/backdrop-contrib/ai_provider_azure/issues).

## Current Maintainer

[Justin Keiser](https://github.com/keiserjb)

## Credits

- Created for Backdrop CMS by [Justin Keiser](https://github.com/keiserjb).

- Developed with AI assistance.

## License

This project is GPL v2 software. See the LICENSE.txt file in this directory for complete text.
