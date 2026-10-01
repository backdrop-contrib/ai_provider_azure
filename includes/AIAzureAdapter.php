<?php

/**
 * @file
 * Azure AI adapter for AI core.
 */

class AIAzureAdapter extends AIAdapterBase {

  use AICompatibleTrait;

  /** @var string */
  protected $resourceName = '';

  /** @var string */
  protected $customEndpoint = '';

  /** @var string */
  protected $apiVersion = '2024-10-21';

  /** @var array */
  protected $deploymentMap = [];

  /** @var array|null */
  protected $models = NULL;

  /**
   * {@inheritdoc}
   */
  public function __construct($api_key, ?AIApi $api = NULL) {
    parent::__construct($api_key, $api);

    $config = config('ai_provider_azure.settings');
    $this->resourceName = trim((string) $config->get('resource_name'));
    $this->customEndpoint = trim((string) $config->get('custom_endpoint'));
    $this->apiVersion = trim((string) $config->get('api_version')) ?: '2024-10-21';

    $deployments_text = trim((string) $config->get('deployments'));
    if ($deployments_text !== '') {
      $lines = preg_split('/[\r\n]+/', $deployments_text);
      foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
          continue;
        }
        if (strpos($line, '=') !== FALSE) {
          [$alias, $deployment] = explode('=', $line, 2);
          $this->deploymentMap[trim($alias)] = trim($deployment);
        }
        else {
          $this->deploymentMap[$line] = $line;
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function getDefaultHeaders(): array {
    return [
      'api-key' => $this->apiKey,
      'Content-Type' => 'application/json',
    ];
  }

  /**
   * Resolve the deployment name for a requested model identifier.
   */
  protected function resolveDeployment(string $model): string {
    if (isset($this->deploymentMap[$model])) {
      return $this->deploymentMap[$model];
    }
    return $model;
  }

  /**
   * Whether requests go to an OpenAI-compatible /v1 endpoint.
   *
   * Those endpoints (Azure AI Studio serverless / Model Catalog) route by the
   * "model" body field rather than by a deployment segment in the URL.
   */
  protected function usesV1Endpoint(): bool {
    return $this->customEndpoint !== ''
      && strpos($this->customEndpoint, '/openai/deployments') === FALSE
      && strpos($this->customEndpoint, '/v1') !== FALSE;
  }

  /**
   * Build the target endpoint URL for an operation.
   */
  protected function buildEndpointUrl(string $operation, string $deployment): string {
    if ($this->customEndpoint !== '') {
      $base = rtrim($this->customEndpoint, '/');
      if (strpos($base, '/openai/deployments') !== FALSE) {
        return $base . '/' . $operation . '?api-version=' . rawurlencode($this->apiVersion);
      }
      if (strpos($base, '/v1') !== FALSE) {
        return $base . '/' . $operation;
      }
      return $base . '/openai/deployments/' . rawurlencode($deployment) . '/' . $operation . '?api-version=' . rawurlencode($this->apiVersion);
    }

    $resource = $this->resourceName ?: 'default';
    return 'https://' . rawurlencode($resource) . '.openai.azure.com/openai/deployments/' . rawurlencode($deployment) . '/' . $operation . '?api-version=' . rawurlencode($this->apiVersion);
  }

  /**
   * {@inheritdoc}
   */
  public function getModels(): array {
    if ($this->models !== NULL) {
      return $this->models;
    }

    $models = [];
    if (!empty($this->deploymentMap)) {
      foreach ($this->deploymentMap as $alias => $deployment) {
        $models[$alias] = ($alias !== $deployment) ? ($alias . ' (' . $deployment . ')') : $deployment;
      }
    }
    else {
      $models = [
        'gpt-4o' => 'Azure GPT-4o',
        'gpt-4o-mini' => 'Azure GPT-4o Mini',
        'gpt-4-turbo' => 'Azure GPT-4 Turbo',
        'text-embedding-3-small' => 'Azure Text Embedding 3 Small',
        'text-embedding-3-large' => 'Azure Text Embedding 3 Large',
        'dall-e-3' => 'Azure DALL-E 3',
        'gpt-image-1' => 'Azure GPT Image 1',
      ];
    }

    asort($models);
    return $this->models = $models;
  }

  /**
   * {@inheritdoc}
   */
  public function getModelsByCapability($capability): array {
    $models = $this->getModels();
    $capability = ai_normalize_capability_name($capability);
    $filtered = [];

    foreach ($models as $id => $label) {
      $ok = FALSE;
      switch ($capability) {
        case 'text':
        case 'chat':
          $ok = !preg_match('/embedding|dall-e|gpt-image|tts|whisper/i', $id);
          break;

        case 'tool_calling':
        case 'vision':
          $ok = (bool) preg_match('/gpt-4|o1|o3/i', $id);
          break;

        case 'thinking':
          $ok = (bool) preg_match('/o1|o3|r1|reason/i', $id);
          break;

        case 'embeddings':
        case 'embedding':
          $ok = (bool) preg_match('/embedding/i', $id);
          break;

        case 'image':
          $ok = (bool) preg_match('/dall-e|gpt-image/i', $id);
          break;

        case 'moderation':
        case 'stt':
          $ok = FALSE;
          break;
      }

      if ($ok) {
        $filtered[$id] = $label;
      }
    }

    backdrop_alter('ai_model_capabilities', $filtered, $capability, $this);
    return $filtered;
  }

  /**
   * Adjust chat parameters for o-series reasoning deployments.
   *
   * o1/o3/o4 deployments reject temperature and max_tokens; they take
   * max_completion_tokens instead. Matched on the alias or deployment name.
   */
  protected function applyReasoningParams(array &$payload, string $model, string $deployment): void {
    if (!preg_match('/(^|[^a-z0-9])o[1-9](-|$|[^a-z0-9])/i', $model . ' ' . $deployment)) {
      return;
    }
    unset($payload['temperature']);
    if (isset($payload['max_tokens'])) {
      $payload['max_completion_tokens'] = $payload['max_tokens'];
      unset($payload['max_tokens']);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function completions(string $model, string $prompt, $temperature, $max_tokens = 512, bool $stream_response = FALSE) {
    $deployment = $this->resolveDeployment($model);
    $url = $this->buildEndpointUrl('completions', $deployment);

    $payload = [
      'prompt' => trim($prompt),
      'temperature' => (float) $temperature,
    ];
    if ($this->usesV1Endpoint()) {
      $payload['model'] = $deployment;
    }
    if ((int) $max_tokens > 0) {
      $payload['max_tokens'] = (int) $max_tokens;
    }

    try {
      if ($stream_response) {
        $payload['stream'] = TRUE;
        return $this->buildStreamingResponse($url, [
          'method' => 'POST',
          'headers' => array_merge(['Accept' => 'text/event-stream'], $this->getDefaultHeaders()),
          'data' => json_encode($payload),
          'timeout' => 300,
        ], function ($data) {
          return $data['choices'][0]['delta']['content'] ?? $data['choices'][0]['text'] ?? '';
        });
      }

      $result = $this->makeRequest($url, $payload, [], 'POST', 300);
      return trim($result['choices'][0]['text'] ?? $result['choices'][0]['message']['content'] ?? '');
    }
    catch (\Exception $e) {
      watchdog('ai_provider_azure', 'Azure completions error: @message', ['@message' => $e->getMessage()], WATCHDOG_ERROR);
      throw $e;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function chat(string $model, array $messages, $temperature, $max_tokens = 1024, bool $stream_response = FALSE, array $context_extra = []) {
    $deployment = $this->resolveDeployment($model);
    $url = $this->buildEndpointUrl('chat/completions', $deployment);

    $payload = [
      'messages' => $messages,
      'temperature' => (float) $temperature,
    ];
    if ($this->usesV1Endpoint()) {
      $payload['model'] = $deployment;
    }
    if ((int) $max_tokens > 0) {
      $payload['max_tokens'] = (int) $max_tokens;
    }
    $this->applyReasoningParams($payload, $model, $deployment);

    if (!empty($context_extra['response_format'])) {
      $payload['response_format'] = $context_extra['response_format'];
    }
    elseif (!empty($context_extra['json_schema'])) {
      $payload['response_format'] = [
        'type' => 'json_schema',
        'json_schema' => [
          'name' => $context_extra['json_schema_name'] ?? 'response',
          'strict' => TRUE,
          'schema' => $context_extra['json_schema'],
        ],
      ];
    }
    elseif (!empty($context_extra['json_mode'])) {
      $payload['response_format'] = ['type' => 'json_object'];
    }

    try {
      if ($stream_response) {
        $payload['stream'] = TRUE;
        return $this->buildStreamingResponse($url, [
          'method' => 'POST',
          'headers' => array_merge(['Accept' => 'text/event-stream'], $this->getDefaultHeaders()),
          'data' => json_encode($payload),
          'timeout' => 300,
        ], function ($data) {
          return $data['choices'][0]['delta']['content'] ?? $data['choices'][0]['message']['content'] ?? '';
        });
      }

      $result = $this->makeRequest($url, $payload, [], 'POST', 300);
      return trim($result['choices'][0]['message']['content'] ?? $result['choices'][0]['text'] ?? '');
    }
    catch (\Exception $e) {
      watchdog('ai_provider_azure', 'Azure chat error: @message', ['@message' => $e->getMessage()], WATCHDOG_ERROR);
      throw $e;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function chatWithTools(string $model, array $messages, array $tools, $temperature, $max_tokens = 1024, string $tool_choice = 'auto', array $context_extra = []): array {
    $deployment = $this->resolveDeployment($model);
    $url = $this->buildEndpointUrl('chat/completions', $deployment);

    $payload = [
      'messages' => $messages,
      'tools' => $tools,
      'tool_choice' => $tool_choice,
      'temperature' => (float) $temperature,
    ];
    if ($this->usesV1Endpoint()) {
      $payload['model'] = $deployment;
    }
    if ((int) $max_tokens > 0) {
      $payload['max_tokens'] = (int) $max_tokens;
    }
    $this->applyReasoningParams($payload, $model, $deployment);

    try {
      $result = $this->makeRequest($url, $payload, [], 'POST', 300);
      return $this->normalizeToolResponse($result);
    }
    catch (\Exception $e) {
      watchdog('ai_provider_azure', 'Azure chatWithTools error: @message', ['@message' => $e->getMessage()], WATCHDOG_ERROR);
      throw $e;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function embedding(string $input, string $model, bool $log = TRUE): array {
    $deployment = $this->resolveDeployment($model);
    $url = $this->buildEndpointUrl('embeddings', $deployment);

    $payload = [
      'input' => $input,
    ];
    if ($this->usesV1Endpoint()) {
      $payload['model'] = $deployment;
    }

    try {
      $result = $this->makeRequest($url, $payload, [], 'POST', 60);
      return $result['data'][0]['embedding'] ?? [];
    }
    catch (\Exception $e) {
      watchdog('ai_provider_azure', 'Azure embedding error: @message', ['@message' => $e->getMessage()], WATCHDOG_ERROR);
      throw $e;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function images(string $model, string $prompt, string $size, string $response_format, string $quality = 'standard', string $style = 'natural', ?string $output_format = NULL) {
    $deployment = $this->resolveDeployment($model);
    $url = $this->buildEndpointUrl('images/generations', $deployment);

    // Capabilities and sizes follow the model the alias is named after.
    $payload = [
      'prompt' => $prompt,
      'size' => AIImageHelper::openAiSize($model, $size),
      'n' => 1,
    ];
    if ($this->usesV1Endpoint()) {
      $payload['model'] = $deployment;
    }
    // As on OpenAI: only DALL-E takes response_format, only DALL-E 3 takes
    // quality/style values in this form; gpt-image models reject them.
    if (stripos($model, 'dall-e') !== FALSE && $response_format) {
      $payload['response_format'] = $response_format;
    }
    if (stripos($model, 'dall-e-3') !== FALSE) {
      $payload['quality'] = $quality;
      $payload['style'] = $style;
    }
    if (!empty($output_format) && stripos($model, 'gpt-image') !== FALSE) {
      $payload['output_format'] = $output_format;
    }

    try {
      // Return the raw envelope (data[0].b64_json / data[0].url) like the
      // other adapters; callers read it in that shape.
      return $this->makeRequest($url, $payload, [], 'POST', 120);
    }
    catch (\Exception $e) {
      watchdog('ai_provider_azure', 'Azure images error: @message', ['@message' => $e->getMessage()], WATCHDOG_ERROR);
      throw $e;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function textToSpeech(string $model, string $input, string $voice, string $response_format) {
    watchdog('ai_provider_azure', 'Text-to-speech is not supported by Azure AI.', [], WATCHDOG_WARNING);
    throw new \RuntimeException('Text-to-speech is not supported by Azure AI.');
  }

  /**
   * {@inheritdoc}
   */
  public function speechToText(string $model, string $file, string $task = 'transcribe', $temperature = 0.4, string $response_format = 'verbose_json') {
    watchdog('ai_provider_azure', 'Speech-to-text is not supported by Azure AI.', [], WATCHDOG_WARNING);
    throw new \RuntimeException('Speech-to-text is not supported by Azure AI.');
  }

  /**
   * {@inheritdoc}
   */
  public function moderation(string $input, string $model = 'omni-moderation-latest'): array {
    watchdog('ai_provider_azure', 'Moderation is not supported by Azure AI.', [], WATCHDOG_WARNING);
    throw new \RuntimeException('Moderation is not supported by Azure AI.');
  }

}
