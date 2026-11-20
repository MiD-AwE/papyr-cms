<?php
declare(strict_types=1);

namespace Papyr\Core;

/* ================================================================
   VALUE OBJECT
================================================================ */
class AIResult
{
    public function __construct(
        public readonly bool   $success,
        public readonly string $text,
        public readonly string $provider,
        public readonly string $channel,  // 'local' | 'cloud' | 'none'
        public readonly string $error = ''
    ) {}

    public static function ok(string $text, string $provider, string $channel): self
    {
        return new self(true, $text, $provider, $channel);
    }

    public static function fail(string $error, string $provider = 'none', string $channel = 'none'): self
    {
        return new self(false, '', $provider, $channel, $error);
    }
}

/* ================================================================
   PROVIDER INTERFACE
================================================================ */
interface AIProvider
{
    public function complete(string $prompt, array $options = []): AIResult;
    public function label(): string;
    public function ping(): bool;
}

/* ================================================================
   NULL PROVIDER — returned when no AI is configured
================================================================ */
class NullProvider implements AIProvider
{
    public function complete(string $prompt, array $options = []): AIResult
    {
        return AIResult::fail('No AI provider configured.', 'none', 'none');
    }

    public function label(): string { return 'none'; }
    public function ping(): bool    { return false; }
}

/* ================================================================
   OPENAI-COMPATIBLE PROVIDER — base for Ollama (/v1) and Cerebras
================================================================ */
class OpenAICompatProvider implements AIProvider
{
    public function __construct(
        protected string  $baseUrl,
        protected string  $model,
        protected string  $channel,
        protected ?string $apiKey = null,
        protected int     $timeoutSeconds = 30
    ) {}

    public function complete(string $prompt, array $options = []): AIResult
    {
        $url  = rtrim($this->baseUrl, '/') . '/chat/completions';
        $body = json_encode([
            'model'       => $options['model'] ?? $this->model,
            'messages'    => [['role' => 'user', 'content' => $prompt]],
            'temperature' => $options['temperature'] ?? 0.7,
            'max_tokens'  => $options['max_tokens']  ?? 1024,
            'stream'      => false,
        ]);

        $headers = ['Content-Type: application/json'];
        if ($this->apiKey !== null) {
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        }

        $response = $this->post($url, $body, $headers);
        if ($response === null) {
            return AIResult::fail("HTTP request failed to {$url}", $this->label(), $this->channel);
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return AIResult::fail('JSON parse error in AI response', $this->label(), $this->channel);
        }

        $text = $data['choices'][0]['message']['content'] ?? '';
        if ($text === '') {
            $err = $data['error']['message'] ?? 'Empty response from AI provider';
            return AIResult::fail($err, $this->label(), $this->channel);
        }

        return AIResult::ok(trim($text), $this->label(), $this->channel);
    }

    public function label(): string { return $this->baseUrl; }

    public function ping(): bool
    {
        $url     = rtrim($this->baseUrl, '/') . '/models';
        $headers = $this->apiKey ? ['Authorization: Bearer ' . $this->apiKey] : [];
        $result  = $this->post($url, null, $headers, isGet: true);
        return $result !== null;
    }

    protected function post(string $url, ?string $body, array $headers, bool $isGet = false): ?string
    {
        $ch = curl_init($url);
        if ($ch === false) return null;

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_FAILONERROR    => false,
        ]);

        if ($isGet) {
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        } else {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($response === false || $curlErr !== '') {
            error_log("Papyr\\Core\\AIBridge: curl error — {$curlErr}");
            return null;
        }
        if ($httpCode >= 400) {
            error_log("Papyr\\Core\\AIBridge: HTTP {$httpCode} from {$url}");
            return null;
        }

        return (string) $response;
    }
}

/* ================================================================
   OLLAMA PROVIDER — uses Ollama's native /api/generate (simpler,
   no messages array needed; stream:false returns full text at once)
================================================================ */
class OllamaProvider implements AIProvider
{
    public function __construct(
        private string $baseUrl,
        private string $model,
        private int    $timeoutSeconds = 60
    ) {}

    public function complete(string $prompt, array $options = []): AIResult
    {
        $url  = rtrim($this->baseUrl, '/') . '/api/generate';
        $body = json_encode([
            'model'  => $options['model'] ?? $this->model,
            'prompt' => $prompt,
            'stream' => false,
            'options' => [
                'temperature' => $options['temperature'] ?? 0.7,
                'num_predict' => $options['max_tokens']  ?? 1024,
            ],
        ]);

        $ch = curl_init($url);
        if ($ch === false) return AIResult::fail('curl init failed', $this->label(), 'local');

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
        ]);

        $response = curl_exec($ch);
        $curlErr  = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $curlErr !== '') {
            return AIResult::fail("Ollama unreachable: {$curlErr}", $this->label(), 'local');
        }
        if ($httpCode >= 400) {
            return AIResult::fail("Ollama HTTP {$httpCode}", $this->label(), 'local');
        }

        $data = json_decode((string) $response, true);
        if (json_last_error() !== JSON_ERROR_NONE || !isset($data['response'])) {
            return AIResult::fail('Unexpected Ollama response format', $this->label(), 'local');
        }

        return AIResult::ok(trim($data['response']), $this->label(), 'local');
    }

    public function label(): string { return 'ollama/' . $this->model; }

    public function ping(): bool
    {
        $url = rtrim($this->baseUrl, '/') . '/api/tags';
        $ch  = curl_init($url);
        if ($ch === false) return false;
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $resp !== false && $code === 200;
    }
}

/* ================================================================
   CEREBRAS PROVIDER — OpenAI-compatible, cloud channel
================================================================ */
class CerebrasProvider extends OpenAICompatProvider
{
    public function __construct(string $baseUrl, string $model, string $apiKey)
    {
        parent::__construct($baseUrl, $model, 'cloud', $apiKey, timeoutSeconds: 20);
    }

    public function label(): string { return 'cerebras/' . $this->model; }
}

/* ================================================================
   AI BRIDGE — routes tasks to the right channel, exposes helpers
================================================================ */
class AIBridge
{
    private AIProvider $local;
    private AIProvider $cloud;

    private const LOCAL_TASKS = ['file_diff', 'content_review', 'template_suggestion', 'code_explain'];
    private const CLOUD_TASKS = ['social_media_draft', 'meta_description', 'page_summary', 'outreach_email'];

    public function __construct(array $aiConfig)
    {
        $this->local = $this->buildProvider($aiConfig['local'] ?? [], 'local');
        $this->cloud = $this->buildProvider($aiConfig['cloud'] ?? [], 'cloud');
    }

    public function run(string $task, string $prompt, array $options = []): AIResult
    {
        if (in_array($task, self::LOCAL_TASKS, true)) {
            return $this->local->complete($prompt, $options);
        }
        if (in_array($task, self::CLOUD_TASKS, true)) {
            return $this->cloud->complete($prompt, $options);
        }
        return AIResult::fail("Unknown task: {$task}");
    }

    public function draftSocialPost(string $pageTitle, string $excerpt, string $platform = 'general'): AIResult
    {
        $prompt = <<<PROMPT
            Write a short {$platform} post for this release. Return only the post text — no hashtags unless natural, no preamble, no explanation.

            Title: {$pageTitle}
            About: {$excerpt}
            PROMPT;

        return $this->cloud->complete($prompt, ['max_tokens' => 200, 'temperature' => 0.9]);
    }

    public function explainDiff(string $diff, string $filename): AIResult
    {
        $prompt = <<<PROMPT
            Briefly explain what changed in this file diff in plain English. One or two sentences. No code, no markdown.

            File: {$filename}
            Diff:
            {$diff}
            PROMPT;

        return $this->local->complete($prompt, ['max_tokens' => 150, 'temperature' => 0.3]);
    }

    public function suggestMeta(string $pageTitle, string $content): AIResult
    {
        $prompt = <<<PROMPT
            Write an SEO meta description for this page. Maximum 155 characters. Return only the description — no quotes, no label, no explanation.

            Page title: {$pageTitle}
            Content excerpt: {$content}
            PROMPT;

        return $this->cloud->complete($prompt, ['max_tokens' => 80, 'temperature' => 0.5]);
    }

    /**
     * Which channels have a provider configured — no network calls, so it is
     * cheap enough for every page render (papyr()->ai). status() pings.
     * @return array{local: bool, cloud: bool}
     */
    public function configured(): array
    {
        return [
            'local' => !($this->local instanceof NullProvider),
            'cloud' => !($this->cloud instanceof NullProvider),
        ];
    }

    public function status(): array
    {
        return [
            'local' => [
                'provider' => $this->local->label(),
                'online'   => $this->local->ping(),
            ],
            'cloud' => [
                'provider' => $this->cloud->label(),
                'online'   => $this->cloud->ping(),
            ],
        ];
    }

    private function buildProvider(array $cfg, string $channel): AIProvider
    {
        $provider = $cfg['provider'] ?? 'none';

        if ($provider === 'ollama') {
            return new OllamaProvider(
                baseUrl: $cfg['base_url'] ?? 'http://localhost:11434',
                model:   $cfg['model']    ?? 'gemma3:latest'
            );
        }

        if ($provider === 'cerebras') {
            $apiKey = isset($cfg['api_key_env']) ? (getenv($cfg['api_key_env']) ?: '') : '';
            if ($apiKey === '') {
                error_log("Papyr\\Core\\AIBridge: Cerebras API key not found in env var '{$cfg['api_key_env']}'");
                return new NullProvider();
            }
            return new CerebrasProvider(
                baseUrl: $cfg['base_url'] ?? 'https://api.cerebras.ai/v1',
                model:   $cfg['model']    ?? 'llama3.1-8b',
                apiKey:  $apiKey
            );
        }

        if ($provider === 'openai_compat') {
            $apiKey = isset($cfg['api_key_env']) ? (getenv($cfg['api_key_env']) ?: null) : null;
            return new OpenAICompatProvider(
                baseUrl:  $cfg['base_url'] ?? '',
                model:    $cfg['model']    ?? '',
                channel:  $channel,
                apiKey:   $apiKey
            );
        }

        return new NullProvider();
    }
}
