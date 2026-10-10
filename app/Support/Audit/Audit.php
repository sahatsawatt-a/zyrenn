<?php

namespace App\Support\Audit;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Keeps a record of what was done to something its owner may look back on:
 * kept under the same owner, a person or a project, so whoever may see the
 * thing may see what happened to it. Never a secret in it -- only which
 * fields changed.
 */
final class Audit
{
    /**
     * @param  Model&object{user_id: int|null, project_id: int|null, ref_id: string}  $subject
     * @param  array<string, mixed>  $details
     */
    public static function record(Model $subject, string $action, ?User $actor, array $details = []): AuditEvent
    {
        return AuditEvent::create([
            'user_id' => $subject->getAttribute('user_id'),
            'project_id' => $subject->getAttribute('project_id'),
            'actor_id' => $actor?->id,
            'action' => $action,
            'subject_type' => self::typeOf($subject),
            'subject_ref' => (string) $subject->getAttribute('ref_id'),
            'subject_name' => mb_substr((string) ($subject->getAttribute('title') ?? $subject->getAttribute('name') ?? ''), 0, 255) ?: null,
            'details' => $details === [] ? null : $details,
        ]);
    }

    /**
     * What happened to one thing, the latest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function of(Model $subject, int $limit = 50): array
    {
        return AuditEvent::with('actor:id,name')
            ->where('subject_type', self::typeOf($subject))
            ->where('subject_ref', (string) $subject->getAttribute('ref_id'))
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (AuditEvent $event) => $event->shown())
            ->values()
            ->all();
    }

    /**
     * What happened to an owner's things of one kind, the latest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function forOwner(?int $userId, ?int $projectId, string $type, int $limit = 50): array
    {
        return AuditEvent::with('actor:id,name')
            ->where('subject_type', $type)
            ->when($projectId !== null, fn ($q) => $q->where('project_id', $projectId), fn ($q) => $q->where('user_id', $userId)->whereNull('project_id'))
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (AuditEvent $event) => $event->shown())
            ->values()
            ->all();
    }

    private static function typeOf(Model $subject): string
    {
        return strtolower(class_basename($subject));
    }
}
