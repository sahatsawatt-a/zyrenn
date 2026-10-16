<?php

namespace App\Support\Connections;

use App\Support\Outside\SmtpTransports;
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
 * @phpstan-type Field array{name: string, label: string, type: string, required: bool, placeholder?: string, help?: string, options?: array<string, string>, default?: string, spent?: bool}
 * A service whose member signs in on its own pages (LinkedIn) is given what
 * that brings -- a token, who it is, until when -- beside what is typed:
 * 'granted' names those, and they are kept through every change of the rest.
 * @phpstan-type Service array{label: string, group: string, hint: string, testable: bool, settings: list<Field>, secrets: list<Field>, url?: string, key_required?: bool, v1?: bool, granted?: array{settings: list<string>, secrets: list<string>}, signs_in?: string, signs_in_by?: array<string, string>}
 */
final class Services
{
    public const AI = 'ai';

    public const MESSAGING = 'messaging';

    public const HTTP = 'http';

    public const SOCIAL = 'social';

    public const SEARCH = 'search';

    /** A model run on a machine of the user's own. */
    public const OLLAMA = 'ollama';

    /** The hosted service that fronts many models behind one key. */
    public const OPENROUTER = 'openrouter';

    /** Any other host that speaks the same dialect: the user says where. */
    public const CUSTOM = 'custom';

    public const TELEGRAM = 'telegram';

    public const LINE = 'line';

    /** A member of LinkedIn, posted as; signed in on LinkedIn's own pages (LinkedInSignIn). */
    public const LINKEDIN = 'linkedin';

    /** A Facebook Page, an Instagram professional account, a Threads profile: each signed in on its own pages (SignIns). */
    public const FACEBOOK = 'facebook';

    public const INSTAGRAM = 'instagram';

    public const THREADS = 'threads';

    /** A WhatsApp Business number, by Meta's Cloud API. */
    public const WHATSAPP = 'whatsapp';

    /** A server speaking the Model Context Protocol, over HTTP: tools an agent may use. */
    public const MCP = 'mcp';

    /** Any messaging platform with an API of its own: its messages sent to a hook, answers posted back. */
    public const CHANNEL = 'channel';

    /** Search engines' results as data, by a key (serpapi.com). */
    public const SERPAPI = 'serpapi';

    /** Search made for a model to read, and pages read whole, by a key (tavily.com). */
    public const TAVILY = 'tavily';

    /** Repositories on GitHub: issues, pull requests, files, Actions, by a token; and what happens in them, by a webhook. */
    public const GITHUB = 'github';

    /** Payments on Stripe: links, checkout, customers, invoices, by a secret key; what happens there, by a webhook. */
    public const STRIPE = 'stripe';

    /** A PromptX account, for workflows to make, change and publish its flows and read their runs (PromptxSignIn). */
    public const PROMPTX = 'promptx';

    /** A header sent with requests to one address: an API's key or bearer token. */
    public const HTTP_HEADER = 'http_header';

    /** The secret another service signs what it sends with. */
    public const WEBHOOK_SECRET = 'webhook_secret';

    /** A Slack channel's incoming webhook: its address is its key. */
    public const SLACK = 'slack';

    /** A Discord channel's webhook: its address is its key. */
    public const DISCORD = 'discord';

    /** A mail server of the user's own, sent through by SMTP. */
    public const SMTP = 'smtp';

    /** What a Slack or Discord webhook's address looks like: nothing else is posted to. */
    public const WEBHOOKS = [
        self::SLACK => '#^https://hooks\.slack\.com/(services|workflows|triggers)/[A-Za-z0-9/_-]+$#',
        self::DISCORD => '#^https://(discord|discordapp|ptb\.discord|canary\.discord)\.com/api/webhooks/\d+/[A-Za-z0-9_-]+$#',
    ];

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

        $services[self::LINKEDIN] = [
            'label' => 'LinkedIn',
            'group' => self::SOCIAL,
            'hint' => 'A LinkedIn member, for workflows to post as. Both values are on the Auth page of an app made at linkedin.com/developers, with the products "Share on LinkedIn" and "Sign In with LinkedIn using OpenID Connect" added. Save it, then sign in with LinkedIn.',
            'testable' => true,
            'settings' => [
                ['name' => 'client_id', 'label' => 'Client ID', 'type' => 'text', 'required' => true],
            ],
            'secrets' => [
                ['name' => 'client_secret', 'label' => 'Client secret', 'type' => 'secret', 'required' => true],
            ],
            'granted' => ['settings' => ['member', 'member_name', 'expires_at'], 'secrets' => ['access_token']],
            'signs_in' => 'Add it to the LinkedIn app\'s Auth page, under "Authorized redirect URLs", then sign in.',
        ];

        $services[self::FACEBOOK] = [
            'label' => 'Facebook',
            'group' => self::SOCIAL,
            'hint' => 'A Facebook Page, for workflows to post as: Facebook lets an app post as a Page, never as a person. Both values are under App settings → Basic of an app made at developers.facebook.com, with Facebook Login added. Save it, then sign in as someone who looks after the Page.',
            'testable' => true,
            'settings' => [
                ['name' => 'client_id', 'label' => 'App ID', 'type' => 'text', 'required' => true],
                ['name' => 'page', 'label' => 'Page', 'type' => 'text', 'required' => false, 'placeholder' => 'Its name: only when you look after several'],
            ],
            'secrets' => [
                ['name' => 'client_secret', 'label' => 'App secret', 'type' => 'secret', 'required' => true],
            ],
            'granted' => ['settings' => ['page_id', 'page_name', 'signer'], 'secrets' => ['page_token']],
            'signs_in' => 'Add it in the Meta app under Facebook Login → Settings → "Valid OAuth Redirect URIs", then sign in.',
        ];

        $services[self::INSTAGRAM] = [
            'label' => 'Instagram',
            'group' => self::SOCIAL,
            'hint' => 'An Instagram professional account (not a personal one), for workflows to post as. With Instagram, the ID and secret are the Instagram app\'s own, under Instagram → API setup with Instagram login. With Facebook, they are the Meta app\'s, under App settings → Basic, and the account has to be linked to a Facebook Page. Save it, then sign in.',
            'testable' => true,
            'settings' => [
                [
                    'name' => 'by',
                    'label' => 'Sign in with',
                    'type' => 'choice',
                    'required' => true,
                    'default' => 'instagram',
                    'options' => [
                        'instagram' => 'Instagram',
                        'facebook' => 'Facebook, as someone who looks after its Page (can delete posts)',
                    ],
                ],
                ['name' => 'client_id', 'label' => 'App ID', 'type' => 'text', 'required' => true],
            ],
            'secrets' => [
                ['name' => 'client_secret', 'label' => 'App secret', 'type' => 'secret', 'required' => true],
            ],
            'granted' => ['settings' => ['member', 'member_name', 'expires_at', 'via', 'scoped_id', 'signer'], 'secrets' => ['access_token']],
            'signs_in' => 'Add it in the Meta app under Instagram → API setup with Instagram login → Business login settings → "OAuth redirect URIs", then sign in.',
            // Set to sign in another way ("by"), it is told of somewhere else
            'signs_in_by' => [
                'facebook' => 'Add it in the Meta app under Facebook Login for Business → Settings → "Valid OAuth Redirect URIs", then sign in. The app needs these permissions: '.str_replace(',', ', ', InstagramSignIn::FACEBOOK_SCOPES).'.',
            ],
        ];

        $services[self::THREADS] = [
            'label' => 'Threads',
            'group' => self::SOCIAL,
            'hint' => 'A Threads profile, for workflows to post as. Both values are in a Meta app with the Threads use case, under Use cases → Access the Threads API → Settings: the Threads app\'s own ID and secret. Save it, then sign in with Threads.',
            'testable' => true,
            'settings' => [
                ['name' => 'client_id', 'label' => 'Threads app ID', 'type' => 'text', 'required' => true],
            ],
            'secrets' => [
                ['name' => 'client_secret', 'label' => 'Threads app secret', 'type' => 'secret', 'required' => true],
            ],
            'granted' => ['settings' => ['member', 'member_name', 'expires_at'], 'secrets' => ['access_token']],
            'signs_in' => 'Add it in the Meta app under the Threads use case → Settings → "Redirect Callback URLs", then sign in.',
        ];

        $services[self::WHATSAPP] = [
            'label' => 'WhatsApp',
            'group' => self::MESSAGING,
            'hint' => 'A WhatsApp Business number, by Meta\'s Cloud API. The number\'s ID and a token are in a Meta app under WhatsApp → API Setup (that token lasts a day; a system user\'s lasts). The app secret, under App settings → Basic, tells Meta\'s messages from anyone else\'s.',
            'testable' => true,
            'settings' => [
                ['name' => 'phone_number_id', 'label' => 'Phone number ID', 'type' => 'text', 'required' => true, 'placeholder' => '106540352242922'],
            ],
            'secrets' => [
                ['name' => 'access_token', 'label' => 'Access token', 'type' => 'secret', 'required' => true],
                ['name' => 'app_secret', 'label' => 'App secret', 'type' => 'secret', 'required' => true],
            ],
        ];

        $services[self::CHANNEL] = [
            'label' => 'Custom channel',
            'group' => self::MESSAGING,
            'hint' => 'Any messaging platform with an API of its own -- a shop\'s chat, a website\'s chat box, a service of your own. It sends each message to the hook address shown once this is saved; answers are posted to "Send to". Both ways are signed with the shared secret: an HMAC-SHA256 of the body, in hex, in the X-Zyrenn-Signature header as sha256=… -- give the same secret to the other side.',
            'testable' => false,
            'settings' => [
                ['name' => 'platform', 'label' => 'Platform', 'type' => 'text', 'required' => true, 'placeholder' => 'Shop chat', 'help' => 'What its chats are called: "Ada on Shop chat".'],
                ['name' => 'send_url', 'label' => 'Send to', 'type' => 'url', 'required' => false, 'placeholder' => 'https://api.example.com/zyrenn/messages', 'help' => 'Where answers are posted, as JSON: chat, text, file and place.'],
            ],
            'secrets' => [
                ['name' => 'secret', 'label' => 'Shared secret', 'type' => 'secret', 'required' => true, 'help' => 'Any long, random string, given to the other side too.'],
            ],
        ];

        $services[self::MCP] = [
            'label' => 'MCP server',
            'group' => self::AI,
            'hint' => 'A server speaking the Model Context Protocol over HTTP -- its address, and a token when it asks for one. Agents can then be given its tools, one by one.',
            'testable' => true,
            'settings' => [
                ['name' => 'url', 'label' => 'Address', 'type' => 'url', 'required' => true, 'placeholder' => 'https://example.com/mcp'],
            ],
            'secrets' => [
                ['name' => 'token', 'label' => 'Token', 'type' => 'secret', 'required' => false, 'help' => 'Sent as "Authorization: Bearer …". Left empty, none.'],
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

        $services[self::GITHUB] = [
            'label' => 'GitHub',
            'group' => self::HTTP,
            'hint' => 'Repositories on GitHub, for workflows to work their issues, pull requests, files, Actions and releases -- and to start on what happens in them. Make a fine-grained token at github.com/settings/personal-access-tokens, for the repositories it may touch, with the permissions the steps need (Issues, Pull requests, Contents, Actions: read and write). For a workflow to start on what happens, the webhook secret is given to GitHub too: the address to send to is shown once this is saved.',
            'testable' => true,
            'settings' => [
                ['name' => 'repo', 'label' => 'Usual repository', 'type' => 'text', 'required' => false, 'placeholder' => 'owner/name', 'help' => 'Where a step works when it names no repository of its own.'],
            ],
            'secrets' => [
                ['name' => 'token', 'label' => 'Token', 'type' => 'secret', 'required' => true, 'placeholder' => 'github_pat_…'],
                ['name' => 'webhook_secret', 'label' => 'Webhook secret', 'type' => 'secret', 'required' => false, 'help' => 'Any long, random string, given to GitHub too as the webhook\'s Secret. Needed only for a workflow that starts on what happens.'],
            ],
        ];

        $services[self::STRIPE] = [
            'label' => 'Stripe',
            'group' => self::HTTP,
            'hint' => 'A Stripe account, for workflows to take payments -- payment links, checkout pages, invoices, refunds -- and to start on what happens there, like a payment made. Use a restricted key (rk_…) made at dashboard.stripe.com/apikeys with only what the steps need, or a test key (sk_test_…) to try it out. For a workflow to start on what happens, add an endpoint under Developers → Webhooks with the address shown once this is saved, and give its signing secret here.',
            'testable' => true,
            'settings' => [],
            'secrets' => [
                ['name' => 'secret_key', 'label' => 'Secret or restricted key', 'type' => 'secret', 'required' => true, 'placeholder' => 'rk_live_… or sk_test_…'],
                ['name' => 'webhook_secret', 'label' => 'Webhook signing secret', 'type' => 'secret', 'required' => false, 'placeholder' => 'whsec_…', 'help' => 'The endpoint\'s signing secret, from Stripe. Needed only for a workflow that starts on what happens.'],
            ],
        ];

        $services[self::PROMPTX] = [
            'label' => 'PromptX',
            'group' => self::HTTP,
            'hint' => 'A PromptX account, for workflows to make, change and publish its flows and read how their runs went. The password is used once, to sign in, and never kept: the sign-in renews itself from then on.',
            'testable' => true,
            'settings' => [
                ['name' => 'login', 'label' => 'Email', 'type' => 'text', 'required' => true, 'placeholder' => 'you@example.com'],
            ],
            'secrets' => [
                ['name' => 'password', 'label' => 'Password', 'type' => 'secret', 'required' => true, 'spent' => true],
            ],
            'granted' => ['settings' => [], 'secrets' => ['access_token', 'refresh_token']],
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

        $services[self::SLACK] = [
            'label' => 'Slack',
            'group' => self::MESSAGING,
            'hint' => 'Posts to one Slack channel, by an incoming webhook: in Slack, make an app, turn on Incoming Webhooks, add one to the channel and copy its address. Anyone with the address can post there, so it is kept secret.',
            'testable' => false,
            'settings' => [],
            'secrets' => [
                ['name' => 'url', 'label' => 'Webhook address', 'type' => 'secret', 'required' => true, 'placeholder' => 'https://hooks.slack.com/services/…'],
            ],
        ];

        $services[self::DISCORD] = [
            'label' => 'Discord',
            'group' => self::MESSAGING,
            'hint' => 'Posts to one Discord channel, by its webhook: in the channel\'s settings, Integrations, Webhooks, New Webhook, Copy Webhook URL. Anyone with the address can post there, so it is kept secret.',
            'testable' => false,
            'settings' => [],
            'secrets' => [
                ['name' => 'url', 'label' => 'Webhook address', 'type' => 'secret', 'required' => true, 'placeholder' => 'https://discord.com/api/webhooks/…'],
            ],
        ];

        $services[self::SMTP] = [
            'label' => 'Email (SMTP)',
            'group' => self::MESSAGING,
            'hint' => 'Sends email through a mail server of your own: your provider\'s SMTP server, or a sending service\'s (Gmail with an app password, Mailgun, SendGrid, Amazon SES). It must be on the public internet.',
            'testable' => false,
            'settings' => [
                ['name' => 'host', 'label' => 'Server', 'type' => 'text', 'required' => true, 'placeholder' => 'smtp.example.com'],
                ['name' => 'port', 'label' => 'Port', 'type' => 'text', 'required' => false, 'default' => '587', 'placeholder' => '587', 'help' => '587, 465, 25 or 2525.'],
                ['name' => 'security', 'label' => 'Security', 'type' => 'choice', 'required' => true, 'default' => 'tls', 'options' => ['tls' => 'STARTTLS (port 587)', 'ssl' => 'TLS from the start (port 465)', 'none' => 'None']],
                ['name' => 'username', 'label' => 'User name', 'type' => 'text', 'required' => false, 'placeholder' => 'you@example.com'],
                ['name' => 'from', 'label' => 'Sent from', 'type' => 'text', 'required' => true, 'placeholder' => 'you@example.com'],
                ['name' => 'from_name', 'label' => 'Name it is sent as', 'type' => 'text', 'required' => false, 'placeholder' => 'Your shop'],
            ],
            'secrets' => [
                ['name' => 'password', 'label' => 'Password', 'type' => 'secret', 'required' => false, 'help' => 'For Gmail, an app password.'],
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
            // One spent on signing in is never saved: what it signed in to stands for it
            $has = ($field['spent'] ?? false) ? self::granted($service['granted']['secrets'] ?? [], $saved) !== [] : filled($saved[$field['name']] ?? null);

            $rules["secrets.{$field['name']}"] = [
                $complete && $field['required'] && ! $has ? 'required' : 'nullable',
                'string',
                'max:2000',
            ];
        }

        if ($name === self::TELEGRAM) {
            $rules['secrets.bot_token'][] = 'regex:/^\d+:[A-Za-z0-9_-]+$/';
        }

        if (isset(self::WEBHOOKS[$name])) {
            $rules['secrets.url'][] = 'regex:'.self::WEBHOOKS[$name];
        }

        if ($name === self::SMTP) {
            $rules['settings.host'][] = 'regex:/^[A-Za-z0-9.-]+$/';
            $rules['settings.from'][] = 'email';
            $rules['settings.port'][] = Rule::in(array_map('strval', SmtpTransports::PORTS));
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
            'secrets.url.regex' => 'That is not a webhook\'s address: copy it whole, as Slack or Discord gives it.',
            'settings.host.regex' => 'Give the server\'s name alone, such as smtp.example.com.',
        ];
    }

    /**
     * The settings as they are kept: only the service's own, each defaulted
     * when it was left out and has a usual value -- and what signing in gave,
     * as it is saved.
     *
     * @param  array<string, mixed>  $given
     * @param  array<string, mixed>  $saved
     * @return array<string, string>
     */
    public static function settings(string $name, array $given, array $saved = []): array
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

        return [...$settings, ...self::granted($service['granted']['settings'] ?? [], $saved)];
    }

    /**
     * The secrets as they are kept: the ones given, the saved ones for any
     * left blank, and what signing in gave.
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

        return [...$secrets, ...self::granted($service['granted']['secrets'] ?? [], $saved)];
    }

    /**
     * What signing in gave, of what is saved: never typed, so never taken from a form.
     *
     * @param  list<string>  $names
     * @param  array<string, mixed>  $saved
     * @return array<string, string>
     */
    private static function granted(array $names, array $saved): array
    {
        return array_filter(
            array_intersect_key($saved, array_flip($names)),
            fn ($value) => is_string($value) && $value !== '',
        );
    }
}
