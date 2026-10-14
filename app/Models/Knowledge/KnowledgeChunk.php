<?php

namespace App\Models\Knowledge;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One passage of a knowledge base: where it was read, what it says, and
 * the numbers its meaning is found by.
 *
 * @property int $id
 * @property int $knowledge_base_id
 * @property string $source_kind
 * @property string $source_ref
 * @property string $title
 * @property string|null $url
 * @property int $position
 * @property string $text
 * @property string $hash
 * @property list<float>|null $embedding
 */
class KnowledgeChunk extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['embedding' => 'array'];
    }

    /**
     * @return BelongsTo<KnowledgeBase, $this>
     */
    public function base(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class, 'knowledge_base_id');
    }
}
