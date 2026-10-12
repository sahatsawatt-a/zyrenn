<?php

namespace App\Support\Connections;

use Illuminate\Validation\Rule;

/**
 * What can be connected to, and what each kind needs: what is said about it
 * (settings) and what must stay secret (secrets). The page draws its form
 * from this, and the same lists say what is accepted.
 *
 * The AI services all speak the chat-completions dialect that OpenAI made
 * common, so an AI kind is only a starting point: where it usually lives,
 * whether it wants a key, and whether the address is typed without its /v1.
 * Anything else is "custom".
 *
 * @phpstan-type Field array{name: string, label: string, type: string, required: bool, placeholder?: string, help?: string, options?: array<string, string>, default?: string}
 * @phpstan-type Service array{label: string, group: string, hint: string, testable: bool, settings: list<Field>, secrets: list<Field>, url?: string, key_required?: bool, v1?: bool}
 */
final class Services
{
    public const AI = 'ai';

    public const MESSAGING = 'messaging';

    public const HTTP = 'http';

    public const SEARCH = 'search';

    /** A model run on a machine of the user's own. */
    public const OLLAMA = 'ollama';

    /** The hosted service that fronts many models behind one key. */
    public const OPENROUTER = 'openrouter';

    /** Any other host that speaks the same dialect: the user says where. */
    public const CUSTOM = 'custom';

    public const TELEGRAM = 'telegram';

    public const LINE = 'line';

    /** Search engines' results as data, by a key (serpapi.com). */
    public const SERPAPI = 'serpapi';

    /** Search made for a model to read, and pages read whole, by a key (tavily.com). */
    public const TAVILY = 'tavily';

    /** A header sent with requests to one address: an API's key or bearer token. */
    public const HTTP_HEADER = 'http_header';

    /** The secret another service signs what it sends with. */
    public const WEBHOOK_SECRET = 'webhook_secret';

    /**
     * @var array<string, array{label: string, url: string, key_required: bool, v1: bool, hint: string}>
     */
    private const AI_PRESETS = [
        self::OLLAMA => [
            'label' => 'Ollama',
            'url' => 'http://localhost:11434',
            'key_required' => false,
            'v1' => true,
            'hint' => 'A model on your own machine. No key needed.',
        ],
        'lmstudio' => [
            'label' => 'LM Studio',
            'url' => 'http://localhost:1234',
            'key_required' => false,
            'v1' => true,
            'hint' => 'The local server of LM Studio. No key needed.',
        ],
        self::OPENROUTER => [
            'label' => 'OpenRouter',
            'url' => 'https://openrouter.ai/api/v1',
            'key_required' => true,
            'v1' => false,
            'hint' => 'Many hosted models behind one key of your own.',
        ],
        'openai' => [
            'label' => 'OpenAI',
            'url' => 'https://api.openai.com/v1',
            'key_required' => true,
            'v1' => false,
            'hint' => 'OpenAI\'s own models, with your key.',
        ],
        'groq' => [
            'label' => 'Groq',
            'url' => 'https://api.groq.com/openai/v1',
            'key_required' => true,
            'v1' => false,
            'hint' => 'Open models on Groq, with your key.',
        ],
        'gemini' => [
            'label' => 'Google Gemini',
            'url' => 'https://generativelanguage.googleapis.com/v1beta/openai',
            'key_required' => true,
            'v1' => false,
            'hint' => 'Gemini through its OpenAI-compatible address, with your key.',
        ],
        self::CUSTOM => [
            'label' => 'Other',
            'url' => '',
            'key_required' => false,
            'v1' => false,
            'hint' => 'Any host that speaks the OpenAI chat API: vLLM, llama.cpp, a company gateway, another provider. Give its address, up to and including /v1 if it has one.',
        ],
    ];

    /**
     * Every service, by its name.
     *
     * @return array<string, Service>
     */
    public static function all(): array
    {
        $services = [];

        foreach (self::AI_PRESETS as $name => $preset) {
            $services[$name] = [
                ...$preset,
                'group' => self::AI,
                'testable' => true,
                'settings' => [
                    [
                        'name' => 'base_url',
                        'label' => 'Host',
                        'type' => 'url',
                        // Only "other" has no usual address to fall back on
                        'required' => $preset['url'] === '',
                        'placeholder' => $preset['url'] ?: 'https://llm.example.com/v1',
                    ],
                    ['name' => 'default_model', 'label' => 'Usual model', 'type' => 'model', 'required' => false],
                ],
                'secrets' => [
                    ['name' => 'api_key', 'label' => 'Key', 'type' => 'secret', 'required' => $preset['key_required']],
                ],
            ];
        }

        $services[self::TELEGRAM] = [
            'label' => 'Telegram',
            'group' => self::MESSAGING,
            'hint' => 'A Telegram bot. Make one with @BotFather and paste the token it gives you.',
            'testable' => true,
            'settings' => [
                [
                    'name' => 'receive',
                    'label' => 'Receive messages by',
                    'type' => 'choice',
                    'required' => true,
                    'default' => 'poll',
                    'options' => [
                        'poll' => 'Asking Telegram for them (works without a public address)',
                        'webhook' => 'Telegram sending them here (needs a public HTTPS address)',
                    ],
                ],
            ],
            'secrets' => [
                ['name' => 'bot_token', 'label' => 'Bot token', 'type' => 'secret', 'required' => true, 'placeholder' => '123456789:AA…'],
            ],
        ];

        $services[self::LINE] = [
            'label' => 'LINE',
            'group' => self::MESSAGING,
            'hint' => 'A LINE Messaging API channel. Both values are on the channel\'s page in the LINE Developers console.',
            'testable' => true,
            'settings' => [],
            'secrets' => [
                ['name' => 'channel_access_token', 'label' => 'Channel access token', 'type' => 'secret', 'required' => true],
                ['name' => 'channel_secret', 'label' => 'Channel secret', 'type' => 'secret', 'required' => true],
            ],
        ];

        $services[self::SERPAPI] = [
            'label' => 'SerpApi',
            'group' => self::SEARCH,
            'hint' => 'What Google, Google News, Google Maps, YouTube and other search engines find, as data. The key is at serpapi.com/manage-api-key. Each search counts against the plan\'s month; one made again within the hour is free.',
            'testable' => true,
            'settings' => [],
            'secrets' => [
                ['name' => 'api_key', 'label' => 'API key', 'type' => 'secret', 'required' => true],
            ],
        ];

        $services[self::TAVILY] = [
            'label' => 'Tavily',
            'group' => self::SEARCH,
            'hint' => 'A search of the web made for a model to read, and pages read whole. The key is at app.tavily.com. Each call spends the plan\'s credits.',
            'testable' => true,
            'settings' => [],
            'secrets' => [
                ['name' => 'api_key', 'label' => 'API key', 'type' => 'secret', 'required' => true, 'placeholder' => 'tvly-…'],
            ],
        ];

        $services[self::HTTP_HEADER] = [
            'label' => 'API key',
            'group' => self::HTTP,
            'hint' => 'A header sent with requests to one address, such as an API key or a bearer token. It is never sent anywhere else.',
            'testable' => false,
            'settings' => [
                [
                    'name' => 'base_url',
                    'label' => 'Only for addresses starting with',
                    'type' => 'url',
                    'required' => true,
                    'placeholder' => 'https://api.example.com/',
                ],
                [
                    'name' => 'header_name',
                    'label' => 'Header',
                    'type' => 'text',
                    'required' => true,
                    'default' => 'Authorization',
                    'placeholder' => 'Authorization',
                ],
            ],
            'secrets' => [
                ['name' => 'header_value', 'label' => 'Value', 'type' => 'secret', 'required' => true, 'placeholder' => 'Bearer …'],
            ],
        ];

        $services[self::WEBHOOK_SECRET] = [
            'label' => 'Webhook secret',
            'group' => self::HTTP,
            'hint' => 'The secret another service signs what it sends with, so a webhook can tell its messages from anyone else\'s.',
            'testable' => false,
            'settings' => [],
            'secrets' => [
                ['name' => 'secret', 'label' => 'Secret', 'type' => 'secret', 'required' => true],
            ],
        ];

        return $services;
    }

    /**
     * One service, or null when there is no such one.
     *
     * @return Service|null
     */
    public static function get(string $name): ?array
    {
        return self::all()[$name] ?? null;
    }

    /**
     * The names of the services that answer chats.
     *
     * @return list<string>
     */
    public static function ai(): array
    {
        return array_keys(self::AI_PRESETS);
    }

    /**
     * What is accepted for a service's settings and secrets. A secret left
     * blank is kept when the connection already has one, so a saved one never
     * has to come back to the page.
     *
     * @param  array<string, mixed>  $saved  the secrets it has now
     * @param  bool  $complete  false for a form being tried, where nothing is required yet
     * @return array<string, mixed>
     */
    public static function rules(string $name, array $saved = [], bool $complete = true): array
    {
        $service = self::get($name);

        if ($service === null) {
            return [];
        }

        $rules = ['settings' => ['nullable', 'array'], 'secrets' => ['nullable', 'array']];

        foreach ($service['settings'] as $field) {
            // A usual value -- an address, a header's name -- stands in for one left out
            $fallback = ($field['default'] ?? '') !== '' || ($field['type'] === 'url' && ($service['url'] ?? '') !== '');

            $rules["settings.{$field['name']}"] = [
                $complete && $field['required'] && ! $fallback ? 'required' : 'nullable',
                ...match ($field['type']) {
                    'url' => ['url:http,https', 'max:255'],
                    'choice' => [Rule::in(array_keys($field['options'] ?? []))],
                    default => ['string', 'max:255'],
                },
            ];
        }

        if ($name === self::HTTP_HEADER) {
            $rules['settings.header_name'][] = 'regex:/^[A-Za-z0-9-]+$/';
        }

        foreach ($service['secrets'] as $field) {
            $rules["secrets.{$field['name']}"] = [
                $complete && $field['required'] && blank($saved[$field['name']] ?? null) ? 'required' : 'nullable',
                'string',
                'max:2000',
            ];
        }

        if ($name === self::TELEGRAM) {
            $rules['secrets.bot_token'][] = 'regex:/^\d+:[A-Za-z0-9_-]+$/';
        }

        return $rules;
    }

    /**
     * What the fields are called in a message about them, e.g. "bot token"
     * rather than "secrets.bot_token".
     *
     * @return array<string, string>
     */
    public static function attributes(string $name): array
    {
        $service = self::get($name) ?? ['settings' => [], 'secrets' => []];
        $names = [];

        foreach (['settings', 'secrets'] as $where) {
            foreach ($service[$where] as $field) {
                $names["{$where}.{$field['name']}"] = mb_strtolower($field['label']);
            }
        }

        return $names;
    }

    /**
     * What is said when a value is not of the shape a service takes.
     *
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'secrets.bot_token.regex' => 'That is not a bot token: it looks like 123456789:AA…, as @BotFather gives it.',
            'settings.header_name.regex' => 'A header name has only letters, digits and dashes, such as X-Api-Key.',
        ];
    }

    /**
     * The settings as they are kept: only the service's own, each defaulted
     * when it was left out and has a usual value.
     *
     * @param  array<string, mixed>  $given
     * @return array<string, string>
     */
    public static function settings(string $name, array $given): array
    {
        $service = self::get($name) ?? ['settings' => []];
        $settings = [];

        foreach ($service['settings'] as $field) {
            $value = is_string($given[$field['name']] ?? null) ? trim($given[$field['name']]) : '';

            if ($field['type'] === 'url') {
                $value = rtrim($value, '/') ?: rtrim($service['url'] ?? '', '/');
            }

            $value = $value ?: ($field['default'] ?? '');

            if ($value !== '') {
                $settings[$field['name']] = $value;
            }
        }

        return $settings;
    }

    /**
     * The secrets as they are kept: the ones given, and the saved ones for any left blank.
     *
     * @param  array<string, mixed>  $given
     * @param  array<string, mixed>  $saved
     * @return array<string, string>
     */
    public static function secrets(string $name, array $given, array $saved = []): array
    {
        $service = self::get($name) ?? ['secrets' => []];
        $secrets = [];

        foreach ($service['secrets'] as $field) {
            $value = $given[$field['name']] ?? null;
            $value = is_string($value) && $value !== '' ? $value : ($saved[$field['name']] ?? null);

            if (is_string($value) && $value !== '') {
                $secrets[$field['name']] = $value;
            }
        }

        return $secrets;
    }
}
