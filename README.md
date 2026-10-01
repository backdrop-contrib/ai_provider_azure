# AI Provider Azure

Microsoft Azure AI provider for the Backdrop CMS AI module.

Adds Azure OpenAI Service deployments, and OpenAI-compatible Azure AI Studio
(Model Catalog / serverless) endpoints, to the providers the `ai` module can
route to.

## Supported operations

| Operation | Supported | Notes |
|---|---|---|
| Chat | Yes | Streaming supported. JSON mode and JSON schema responses supported. If a reasoning deployment rejects `max_tokens` or `temperature`, the request is retried once with `max_completion_tokens` and no temperature. |
| Completions | Yes | Uses the deployment's `completions` endpoint. |
| Tool calling | Yes | Every deployment is offered. |
| Vision | Yes | Assign deployments on the Model capabilities page. |
| Embeddings | Yes | Assign deployments on the Model capabilities page. |
| Image generation | Yes | Assign deployments on the Model capabilities page. Sent with prompt, size and `n` only; gpt-image models need API version `2025-04-01-preview` or later. |
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
  `deployment_name` or `alias=deployment_name`. These become the model list;
  with none configured the provider offers no models.

Deployment names say nothing reliable about the model behind them, so every
deployment is offered for chat and tool calling. Use the Model capabilities
page (`admin/config/ai/settings/capabilities/azure`) to mark embedding, image
and vision deployments.

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
