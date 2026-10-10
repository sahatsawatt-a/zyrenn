<?php

namespace App\Models\Workflow;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Something that came in to start workflows -- a webhook's request, a bot's
 * message, a time come round -- kept by its sender's own id, so the same one
 * sent twice starts nothing twice.
 *
 * @property int $id
 * @property string $source
 * @property string $event_id
 * @property array<string, mixed>|null $envelope
 * @property int $runs
 * @property Carbon|null $received_at
 */
class InboundEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.v';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['envelope' => 'array', 'received_at' => 'datetime'];
    }
}
