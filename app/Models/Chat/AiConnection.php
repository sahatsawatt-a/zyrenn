<?php

namespace App\Models\Chat;

use App\Models\Concerns\HasRefId;
use App\Models\User;
use Database\Factories\Chat\AiConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Where a model is reached, kept by one user: the host to call and, for a
 * hosted service, the user's own key. It is never shared -- a room names one
 * to answer with, and whoever else sees the room never sees the key.
 *
 * @property int $id
 * @property string $ref_id
 * @property int $user_id
 * @property string $name
 * @property string $kind
 * @property string $base_url
 * @property string|null $api_key
 * @property string|null $default_model
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'kind', 'base_url', 'api_key', 'default_model'])]
#[Hidden(['api_key'])]
class AiConnection extends Model
{
    /** @use HasFactory<AiConnectionFactory> */
    use HasFactory, HasRefId;

    /** A model run on a machine of the user's own. */
    public const OLLAMA = 'ollama';

    /** The hosted service that fronts many models behind one key. */
    public const OPENROUTER = 'openrouter';

    /** Any other host that speaks the same dialect: the user says where. */
    public const CUSTOM = 'custom';

    /**
     * What can be connected to. They all speak the chat-completions dialect
     * that OpenAI made common, so a kind is only a starting point: where it
     * usually lives, whether it wants a key, and whether the address is
     * typed without its /v1. Anything else is "custom".
     *
     * @var array<string, array{label: string, url: string, key_required: bool, v1: bool, hint: string}>
     */
    public const PRESETS = [
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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['api_key' => 'encrypted'];
    }

    /**
     * Get the user whose connection it is.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * What the kind is called.
     */
    public function label(): string
    {
        return self::PRESETS[$this->kind]['label'] ?? $this->kind;
    }

    /**
     * The address every call starts from. A local server answers the
     * dialect only under /v1, which people leave off when they type the host.
     */
    public function apiRoot(): string
    {
        $root = rtrim($this->base_url, '/');

        if ((self::PRESETS[$this->kind]['v1'] ?? false) && ! str_ends_with($root, '/v1')) {
            $root .= '/v1';
        }

        return $root;
    }
}
