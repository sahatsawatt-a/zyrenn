<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use App\Models\Concerns\HasRefId;
use App\Support\Connections\Services;
use Database\Factories\ConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Somewhere outside that Zyrenn reaches for its owner: a model's host, a
 * Telegram bot, a LINE channel, an API's key. Owned by a user, for their own
 * chats and workflows, or by a project, for its members' -- so a shared
 * thing never runs on one person's key. Its secrets are encrypted together
 * and never leave the server.
 *
 * @property int $id
 * @property string $ref_id
 * @property string $name
 * @property string $service
 * @property array<string, string>|null $settings
 * @property array<string, string>|null $secrets
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'service', 'settings'])]
#[Hidden(['secrets'])]
class Connection extends Model
{
    /** @use HasFactory<ConnectionFactory> */
    use BelongsToOwner, HasFactory, HasRefId;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'secrets' => 'encrypted:array',
        ];
    }

    /**
     * Only the connections that answer chats.
     *
     * @param  Builder<self>  $query
     */
    public function scopeAi(Builder $query): void
    {
        $query->whereIn('service', Services::ai());
    }

    public function isAi(): bool
    {
        return in_array($this->service, Services::ai(), true);
    }

    /**
     * What the service is called.
     */
    public function label(): string
    {
        return Services::get($this->service)['label'] ?? $this->service;
    }

    /**
     * One of its settings, or null.
     */
    public function setting(string $name): ?string
    {
        return $this->settings[$name] ?? null;
    }

    /**
     * One of its secrets, or null.
     */
    public function secret(string $name): ?string
    {
        return $this->secrets[$name] ?? null;
    }

    /**
     * The address every call to a model starts from. A local server answers
     * the dialect only under /v1, which people leave off when they type the host.
     */
    public function apiRoot(): string
    {
        $root = rtrim($this->setting('base_url') ?? '', '/');

        if ((Services::get($this->service)['v1'] ?? false) && ! str_ends_with($root, '/v1')) {
            $root .= '/v1';
        }

        return $root;
    }

    /**
     * Whether a request to $url may carry this connection's header: the same
     * scheme, host and port as its address, and a path under its path -- so
     * "https://api.example.com" never lends its key to api.example.com.evil.test.
     */
    public function sendsTo(string $url): bool
    {
        $mine = parse_url($this->setting('base_url') ?? '');
        $theirs = parse_url($url);

        if (! is_array($mine) || ! is_array($theirs) || ! isset($mine['host'], $theirs['host'])) {
            return false;
        }

        $port = fn (array $parts) => $parts['port'] ?? (strtolower($parts['scheme'] ?? '') === 'https' ? 443 : 80);
        $path = rtrim($mine['path'] ?? '', '/').'/';

        return strtolower($mine['scheme'] ?? '') === strtolower($theirs['scheme'] ?? '')
            && strtolower($mine['host']) === strtolower($theirs['host'])
            && $port($mine) === $port($theirs)
            && str_starts_with(rtrim($theirs['path'] ?? '', '/').'/', $path);
    }
}
