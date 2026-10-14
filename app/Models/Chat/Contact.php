<?php

namespace App\Models\Chat;

use App\Models\Concerns\HasRefId;
use App\Models\Project;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A project's customer, whichever services they write on: their chats with
 * the project point to them. Made with their first chat; chats found to be
 * the same person's are joined -- by a workflow that knows something the
 * same for them everywhere (the key: an email, a phone number), or by hand
 * -- and one joined by mistake is taken out again.
 *
 * What is noted about them by hand is kept here, once. What a workflow keeps
 * about one service -- their id there, their language -- stays with that
 * chat, since it is true of that service only.
 *
 * @property int $id
 * @property string $ref_id
 * @property int $project_id
 * @property string $name
 * @property string|null $picture
 * @property string|null $key
 * @property array<string, string>|null $details
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name'])]
class Contact extends Model
{
    use HasRefId;

    /** How long a name and a key may be. */
    public const MAX_NAME = 120;

    public const MAX_KEY = 190;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['details' => 'array'];
    }

    /**
     * Get the project they are a customer of.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get their chats, one for each service and account they write to.
     *
     * @return HasMany<ChatRoom, $this>
     */
    public function rooms(): HasMany
    {
        return $this->hasMany(ChatRoom::class)->orderBy('id');
    }

    /**
     * Someone of their own for a chat with a customer, named as the chat
     * knows them: the name a workflow keeps, or the chat's title.
     */
    public static function startFor(ChatRoom $room): self
    {
        $contact = new self;
        $contact->project_id = (int) $room->project_id;
        $contact->name = self::nameFor($room);
        $contact->picture = $room->picture;
        $contact->save();

        $room->forceFill(['contact_id' => $contact->id])->save();

        return $contact;
    }

    private static function nameFor(ChatRoom $room): string
    {
        $name = trim((string) ($room->details['name'] ?? '')) ?: trim($room->title);

        return mb_substr($name, 0, self::MAX_NAME) ?: 'Someone';
    }

    /**
     * Join a chat to whoever in the project stands for $key -- made the
     * first time it is given -- so their chats on every service are one
     * person's. The chat's own contact, left with no chats, goes.
     */
    public static function join(ChatRoom $room, string $key): self
    {
        $key = mb_substr(mb_strtolower(trim($key)), 0, self::MAX_KEY);
        $own = $room->contact;
        $found = self::query()->where('project_id', $room->project_id)->where('key', $key)->first();

        if ($found === null) {
            $own ??= self::startFor($room);

            // Someone known by another key already stays as they are: two keys can't say which is wrong
            if ($own->key !== null) {
                return $own;
            }

            try {
                $own->forceFill(['key' => $key])->save();

                return $own;
            } catch (UniqueConstraintViolationException) {
                // Given at the same moment by another run: theirs is the one
                $own->forceFill(['key' => null]);
                $found = self::query()->where('project_id', $room->project_id)->where('key', $key)->firstOrFail();
            }
        }

        if ($own !== null && $own->isNot($found)) {
            $found->absorb($own);
        }

        // Said of this chat too, which may be held with the contact it had
        $room->forceFill(['contact_id' => $found->id])->save();
        $room->unsetRelation('contact');

        return $found;
    }

    /**
     * Take another of the project's contacts in: they are found to be the
     * same person. Their chats come here, and their notes too where these
     * have none of the same name; what is kept here wins.
     */
    public function absorb(self $other): void
    {
        if ($other->is($this)) {
            return;
        }

        DB::transaction(function () use ($other) {
            $other->rooms()->update(['contact_id' => $this->id]);
            $key = $this->key ?? $other->key;
            // Theirs is freed before it is taken: a key stands for one person in a project
            $other->delete();

            $this->forceFill([
                'details' => [...$other->details ?? [], ...$this->details ?? []] ?: null,
                'picture' => $this->picture ?? $other->picture,
                'key' => $key,
            ])->save();
        });

        $this->unsetRelation('rooms');
    }

    /**
     * Take one chat out: found not to be the same person after all. It is
     * someone of its own again, with the notes it had.
     */
    public function release(ChatRoom $room): self
    {
        if ($this->rooms()->count() < 2) {
            return $this;
        }

        $room->forceFill(['contact_id' => null])->save();
        $room->unsetRelation('contact');
        $apart = self::startFor($room);
        $apart->forceFill(['details' => $this->details])->save();

        return $apart;
    }

    /**
     * Notes by hand: the whole list as it now stands.
     *
     * @param  array<string, mixed>  $noted
     */
    public function noteByHand(array $noted): void
    {
        $details = [];

        foreach ($noted as $name => $value) {
            $value = is_scalar($value) ? trim((string) $value) : '';

            if ($value !== '') {
                $details[(string) $name] = mb_substr($value, 0, ChatRoom::MAX_DETAIL);
            }
        }

        $this->forceFill(['details' => $details ?: null])->save();
    }

    /**
     * Who they are, as a chat's page and a workflow read it: their name,
     * key and notes, and each of their chats with the service it is on.
     *
     * @return array{ref_id: string, name: string, key: string|null, picture: string|null, details: array<string, string>, rooms: list<array{ref_id: string, title: string, service: string|null, status: string|null}>}
     */
    public function card(): array
    {
        return [
            'ref_id' => $this->ref_id,
            'name' => $this->name,
            'key' => $this->key,
            'picture' => $this->picture,
            'details' => $this->details ?? [],
            'rooms' => array_values($this->rooms->map(fn (ChatRoom $room) => [
                'ref_id' => $room->ref_id,
                'title' => $room->title,
                'service' => $room->link['service'] ?? null,
                'status' => $room->status,
            ])->all()),
        ];
    }
}
