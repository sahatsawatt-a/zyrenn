<?php

namespace App\Support\Workflow;

use App\Events\WorkflowRunChanged;
use App\Jobs\RunWorkflowStep;
use App\Models\User;
use App\Models\Workflow\Workflow;
use App\Models\Workflow\WorkflowDelivery;
use App\Models\Workflow\WorkflowNodeRun;
use App\Models\Workflow\WorkflowRun;
use App\Models\Workflow\WorkflowVersion;
use App\Support\Live\Live;
use App\Support\Workflow\Steps\Approval;
use App\Support\Workflow\Steps\AskAgent;
use App\Support\Workflow\Steps\AskAi;
use App\Support\Workflow\Steps\ChannelSend;
use App\Support\Workflow\Steps\ChatFetch;
use App\Support\Workflow\Steps\ChatRoomStep;
use App\Support\Workflow\Steps\ChatSend;
use App\Support\Workflow\Steps\ChatUpdate;
use App\Support\Workflow\Steps\Delay;
use App\Support\Workflow\Steps\DriveFind;
use App\Support\Workflow\Steps\DriveLink;
use App\Support\Workflow\Steps\DriveRead;
use App\Support\Workflow\Steps\DriveSave;
use App\Support\Workflow\Steps\FacebookCall;
use App\Support\Workflow\Steps\FacebookPost;
use App\Support\Workflow\Steps\ForEachStep;
use App\Support\Workflow\Steps\GivenTrigger;
use App\Support\Workflow\Steps\HttpRequest;
use App\Support\Workflow\Steps\IfCondition;
use App\Support\Workflow\Steps\InstagramCall;
use App\Support\Workflow\Steps\InstagramChat;
use App\Support\Workflow\Steps\InstagramPost;
use App\Support\Workflow\Steps\KnowledgeSearch;
use App\Support\Workflow\Steps\LineCall;
use App\Support\Workflow\Steps\LineFile;
use App\Support\Workflow\Steps\LineProfile;
use App\Support\Workflow\Steps\LineReply;
use App\Support\Workflow\Steps\LineRichMenu;
use App\Support\Workflow\Steps\LineSend;
use App\Support\Workflow\Steps\LinkedInCall;
use App\Support\Workflow\Steps\LinkedInPost;
use App\Support\Workflow\Steps\Log;
use App\Support\Workflow\Steps\ManualTrigger;
use App\Support\Workflow\Steps\MergeStep;
use App\Support\Workflow\Steps\PromptxCall;
use App\Support\Workflow\Steps\RunCode;
use App\Support\Workflow\Steps\SerpApiCall;
use App\Support\Workflow\Steps\SetData;
use App\Support\Workflow\Steps\Step;
use App\Support\Workflow\Steps\StepContext;
use App\Support\Workflow\Steps\StepFailed;
use App\Support\Workflow\Steps\StepResult;
use App\Support\Workflow\Steps\SwitchStep;
use App\Support\Workflow\Steps\TableAdd;
use App\Support\Workflow\Steps\TableFind;
use App\Support\Workflow\Steps\TableUpdate;
use App\Support\Workflow\Steps\TavilyCall;
use App\Support\Workflow\Steps\TelegramCall;
use App\Support\Workflow\Steps\TelegramFile;
use App\Support\Workflow\Steps\TelegramSend;
use App\Support\Workflow\Steps\TelegramSendFile;
use App\Support\Workflow\Steps\ThreadsCall;
use App\Support\Workflow\Steps\ThreadsPost;
use App\Support\Workflow\Steps\WhatsAppCall;
use App\Support\Workflow\Steps\WhatsAppFile;
use App\Support\Workflow\Steps\WhatsAppSend;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Runs workflows: the one place a run or a step changes status.
 *
 * The database is what is true. Every change to a run -- a step claimed, a
 * step done, what can run next -- happens in a transaction that first locks
 * the run's row, so two steps finishing at once can neither both start the
 * step after them nor both miss it. A step is handed to a worker by a job
 * written in that same transaction (the queue is a table of the same
 * database), so a step is never marked queued without its job, nor the
 * other way round. A job that comes twice finds its step no longer queued
 * and does nothing.
 *
 * A step runs when every step before it has finished: it runs if at least
 * one of the ways into it was taken, and is skipped if none was -- an If's
 * other way, or steps that were themselves skipped.
 *
 * @phpstan-import-type Shape from Definition
 * @phpstan-import-type Node from Definition
 */
final class Engine
{
    /** The most steps one run may begin. */
    public const MAX_STEPS = 1000;

    /** The most a step may give out, as JSON. */
    public const MAX_OUTPUT_BYTES = 256 * 1024;

    /** How long a worker may hold a step before it is presumed gone. */
    public const LEASE_SECONDS = 180;

    /** How many times a step is tried: handed to another worker, or tried again later. */
    public const MAX_ATTEMPTS = 3;

    /** How many runs deep one run may start another (a workflow answering its own message). */
    public const MAX_DEPTH = 5;

    /** @var array<int, true> runs changed in the transaction under way, to be told of once it commits */
    private static array $changed = [];

    /**
     * What runs each kind of step. A run keeps the version of each step it
     * was published with, so a step changed in a way old runs must not see
     * keeps its old code under "type@version" -- e.g. 'ai.chat@1' -- for as
     * long as runs of it may still be waiting; NodeTypes' "oldest" says which
     * versions are still run at all.
     *
     * @var array<string, class-string<Step>>
     */
    private const STEPS = [
        'trigger.manual' => ManualTrigger::class,
        'trigger.call' => GivenTrigger::class,
        'trigger.chat' => GivenTrigger::class,
        'trigger.schedule' => GivenTrigger::class,
        'trigger.webhook' => GivenTrigger::class,
        'trigger.telegram' => GivenTrigger::class,
        'trigger.line' => GivenTrigger::class,
        'trigger.whatsapp' => GivenTrigger::class,
        'trigger.instagram' => GivenTrigger::class,
        'trigger.instagram_message' => GivenTrigger::class,
        'trigger.threads' => GivenTrigger::class,
        'line.profile' => LineProfile::class,
        'line.file' => LineFile::class,
        'telegram.file' => TelegramFile::class,
        'chat.room' => ChatRoomStep::class,
        'chat.send' => ChatSend::class,
        'chat.fetch' => ChatFetch::class,
        'knowledge.search' => KnowledgeSearch::class,
        'ai.agent' => AskAgent::class,
        'chat.update' => ChatUpdate::class,
        'table.find' => TableFind::class,
        'table.add' => TableAdd::class,
        'table.update' => TableUpdate::class,
        'drive.save' => DriveSave::class,
        'drive.find' => DriveFind::class,
        'drive.read' => DriveRead::class,
        'drive.link' => DriveLink::class,
        'ai.chat' => AskAi::class,
        'data.code' => RunCode::class,
        'http.request' => HttpRequest::class,
        'serpapi.google' => SerpApiCall::class,
        'serpapi.news' => SerpApiCall::class,
        'serpapi.images' => SerpApiCall::class,
        'serpapi.maps' => SerpApiCall::class,
        'serpapi.youtube' => SerpApiCall::class,
        'serpapi.search' => SerpApiCall::class,
        'serpapi.locations' => SerpApiCall::class,
        'serpapi.account' => SerpApiCall::class,
        'tavily.search' => TavilyCall::class,
        'tavily.extract' => TavilyCall::class,
        'tavily.crawl' => TavilyCall::class,
        'tavily.map' => TavilyCall::class,
        'tavily.usage' => TavilyCall::class,
        'promptx.flow_create' => PromptxCall::class,
        'promptx.flow' => PromptxCall::class,
        'promptx.flow_import' => PromptxCall::class,
        'promptx.flow_publish' => PromptxCall::class,
        'promptx.runs' => PromptxCall::class,
        'promptx.run' => PromptxCall::class,
        'telegram.send' => TelegramSend::class,
        'telegram.send_file' => TelegramSendFile::class,
        'line.send' => LineSend::class,
        'line.reply' => LineReply::class,
        'whatsapp.file' => WhatsAppFile::class,
        'whatsapp.send' => WhatsAppSend::class,
        'trigger.channel' => GivenTrigger::class,
        'channel.send' => ChannelSend::class,
        'whatsapp.send_file' => WhatsAppCall::class,
        'whatsapp.template' => WhatsAppCall::class,
        'whatsapp.location' => WhatsAppCall::class,
        'whatsapp.react' => WhatsAppCall::class,
        'whatsapp.read' => WhatsAppCall::class,
        'facebook.post' => FacebookPost::class,
        'facebook.comment' => FacebookCall::class,
        'facebook.like' => FacebookCall::class,
        'facebook.unlike' => FacebookCall::class,
        'facebook.delete' => FacebookCall::class,
        'facebook.posts' => FacebookCall::class,
        'facebook.comments' => FacebookCall::class,
        'facebook.me' => FacebookCall::class,
        'instagram.post' => InstagramPost::class,
        'instagram.comment' => InstagramCall::class,
        'instagram.reply' => InstagramCall::class,
        'instagram.comment_delete' => InstagramCall::class,
        'instagram.delete' => InstagramCall::class,
        'instagram.posts' => InstagramCall::class,
        'instagram.comments' => InstagramCall::class,
        'instagram.me' => InstagramCall::class,
        'instagram.send' => InstagramChat::class,
        'instagram.send_file' => InstagramChat::class,
        'instagram.message_commenter' => InstagramChat::class,
        'instagram.react' => InstagramChat::class,
        'instagram.profile' => InstagramChat::class,
        'threads.post' => ThreadsPost::class,
        'threads.repost' => ThreadsCall::class,
        'threads.delete' => ThreadsCall::class,
        'threads.posts' => ThreadsCall::class,
        'threads.replies' => ThreadsCall::class,
        'threads.me' => ThreadsCall::class,
        'linkedin.post' => LinkedInPost::class,
        'linkedin.repost' => LinkedInCall::class,
        'linkedin.delete' => LinkedInCall::class,
        'linkedin.comment' => LinkedInCall::class,
        'linkedin.comment_delete' => LinkedInCall::class,
        'linkedin.like' => LinkedInCall::class,
        'linkedin.unlike' => LinkedInCall::class,
        'linkedin.me' => LinkedInCall::class,
        // One call to a service each (ServiceKinds)
        'telegram.album' => TelegramCall::class,
        'telegram.sticker' => TelegramCall::class,
        'telegram.location' => TelegramCall::class,
        'telegram.contact' => TelegramCall::class,
        'telegram.poll' => TelegramCall::class,
        'telegram.forward' => TelegramCall::class,
        'telegram.copy' => TelegramCall::class,
        'telegram.invoice' => TelegramCall::class,
        'telegram.topic' => TelegramCall::class,
        'telegram.action' => TelegramCall::class,
        'telegram.edit' => TelegramCall::class,
        'telegram.edit_buttons' => TelegramCall::class,
        'telegram.delete' => TelegramCall::class,
        'telegram.pin' => TelegramCall::class,
        'telegram.unpin' => TelegramCall::class,
        'telegram.react' => TelegramCall::class,
        'telegram.answer_button' => TelegramCall::class,
        'telegram.commands' => TelegramCall::class,
        'telegram.ban' => TelegramCall::class,
        'telegram.unban' => TelegramCall::class,
        'telegram.restrict' => TelegramCall::class,
        'telegram.answer_inline' => TelegramCall::class,
        'telegram.answer_checkout' => TelegramCall::class,
        'telegram.chat' => TelegramCall::class,
        'telegram.member' => TelegramCall::class,
        'telegram.member_count' => TelegramCall::class,
        'line.multicast' => LineCall::class,
        'line.broadcast' => LineCall::class,
        'line.loading' => LineCall::class,
        'line.read' => LineCall::class,
        'line.leave' => LineCall::class,
        'line.richmenu_make' => LineRichMenu::class,
        'line.richmenu_delete' => LineRichMenu::class,
        'line.richmenu_default' => LineRichMenu::class,
        'line.richmenu_default_off' => LineRichMenu::class,
        'line.richmenu' => LineRichMenu::class,
        'line.richmenu_off' => LineRichMenu::class,
        'line.richmenu_many' => LineRichMenu::class,
        'line.richmenu_many_off' => LineRichMenu::class,
        'line.richmenu_of' => LineRichMenu::class,
        'line.richmenu_alias' => LineRichMenu::class,
        'line.richmenu_alias_off' => LineRichMenu::class,
        'line.richmenus' => LineRichMenu::class,
        'line.quota' => LineCall::class,
        'line.consumption' => LineCall::class,
        'line.group' => LineCall::class,
        'line.group_count' => LineCall::class,
        'data.set' => SetData::class,
        'logic.if' => IfCondition::class,
        'logic.switch' => SwitchStep::class,
        'logic.merge' => MergeStep::class,
        'logic.each' => ForEachStep::class,
        'logic.delay' => Delay::class,
        'logic.approval' => Approval::class,
        'output.log' => Log::class,
    ];

    /**
     * Start a run of a frozen version, from one of its triggers.
     *
     * @throws InvalidArgumentException when it has no trigger to start from
     */
    public static function start(
        Workflow $workflow,
        WorkflowVersion $version,
        ?User $by,
        string $trigger = 'manual',
        ?string $triggerNodeId = null,
        mixed $triggerData = null,
        ?WorkflowRun $origin = null,
        // The "For each" step it answers to, and which item it was started for
        ?WorkflowNodeRun $caller = null,
        ?int $callerIndex = null,
    ): WorkflowRun {
        /** @var Shape $definition */
        $definition = $version->definition;
        $start = collect($definition['nodes'])->first(fn (array $node) => $triggerNodeId !== null
            ? $node['id'] === $triggerNodeId
            : $node['type'] === 'trigger.'.$trigger);

        if ($start === null) {
            throw new InvalidArgumentException('This workflow has nothing to start it this way.');
        }

        // Runs for one chat may be kept in order, or only the latest let run
        $perChat = $start['config']['per_chat'] ?? 'each';
        $chat = is_array($triggerData) ? ($triggerData['chat']['id'] ?? $triggerData['room']['ref'] ?? null) : null;
        $key = $perChat !== 'each' && is_scalar($chat) && $chat !== '' ? mb_substr("{$start['id']}:{$chat}", 0, 191) : null;

        return DB::transaction(function () use ($workflow, $version, $by, $trigger, $start, $triggerData, $definition, $origin, $caller, $callerIndex, $perChat, $key) {
            // A newer message for the chat: the runs still going for it stop
            if ($key !== null && $perChat === 'latest') {
                self::keyed($workflow->id, $key)->pluck('id')->each(fn (int $id) => self::end(
                    self::lock($id), RunStatus::Cancelled, 'superseded', 'A newer message came for this chat.',
                ));
            }

            // Another for the chat still going: this one waits its turn, its trigger not yet queued
            $held = $key !== null && $perChat === 'one' && self::keyed($workflow->id, $key)->exists();

            $run = WorkflowRun::create([
                'workflow_id' => $workflow->id,
                'workflow_version_id' => $version->id,
                'status' => RunStatus::Queued,
                'trigger' => $trigger,
                'trigger_node_id' => $start['id'],
                'run_key' => $key,
                'trigger_data' => $triggerData,
                'started_by' => $by?->id,
                'origin_run_id' => $origin?->id,
                'caller_node_run_id' => $caller?->id,
                'caller_index' => $callerIndex,
                'depth' => $origin === null ? 0 : $origin->depth + 1,
                'max_executed_steps' => self::MAX_STEPS,
            ]);

            $now = now();
            WorkflowNodeRun::insert(array_map(fn (array $node) => [
                'workflow_run_id' => $run->id,
                'node_id' => $node['id'],
                'node_key' => $node['key'],
                'node_type' => $node['type'],
                'node_version' => $node['nodeVersion'],
                'status' => NodeStatus::Pending->value,
                'created_at' => $now,
                'updated_at' => $now,
            ], $definition['nodes']));

            if ($held) {
                return $run;
            }

            self::queue($run, $start['id']);
            // Any other trigger, and what only it leads to, is skipped
            self::settle($run);

            return $run;
        });
    }

    /**
     * A chat's runs that have not ended, the held ones too.
     *
     * @return Builder<WorkflowRun>
     */
    private static function keyed(int $workflowId, string $key): Builder
    {
        return WorkflowRun::where('workflow_id', $workflowId)
            ->where('run_key', $key)
            ->whereIn('status', [RunStatus::Queued->value, RunStatus::Running->value, RunStatus::Waiting->value]);
    }

    /**
     * The next run held for a chat, let go once none ahead of it is going.
     */
    public static function release(int $workflowId, string $key): void
    {
        DB::transaction(function () use ($workflowId, $key) {
            $runs = self::keyed($workflowId, $key)->orderBy('id')->get();
            $next = $runs->first(fn (WorkflowRun $run) => self::isHeld($run));

            if ($next === null || $runs->contains(fn (WorkflowRun $run) => $run->id < $next->id && ! self::isHeld($run))) {
                return;
            }

            $next = self::lock($next->id);

            if (self::isHeld($next)) {
                self::queue($next, $next->trigger_node_id);
                self::settle($next);
            }
        });
    }

    /**
     * Held: queued, its trigger not yet queued.
     */
    private static function isHeld(WorkflowRun $run): bool
    {
        return $run->status === RunStatus::Queued
            && $run->steps()->where('node_id', $run->trigger_node_id)->where('status', NodeStatus::Pending->value)->exists();
    }

    /**
     * A "For each" step waiting on the runs it started: one has ended, so it
     * counts them again, starting more or gathering what they gave.
     */
    public static function wakeCaller(int $nodeRunId): void
    {
        DB::transaction(function () use ($nodeRunId) {
            $step = WorkflowNodeRun::find($nodeRunId);

            if ($step === null) {
                return;
            }

            self::lock($step->workflow_run_id);
            $step->refresh();

            if ($step->status === NodeStatus::Waiting && $step->waiting_for === 'children') {
                self::move($step, NodeStatus::Queued);
                RunWorkflowStep::dispatch($step->id);
            }
        });
    }

    /**
     * Stop the runs a run's "For each" steps started, once it has stopped.
     */
    private static function stopChildren(int $runId): void
    {
        WorkflowRun::whereIn('caller_node_run_id', WorkflowNodeRun::where('workflow_run_id', $runId)->select('id'))
            ->whereIn('status', [RunStatus::Queued->value, RunStatus::Running->value, RunStatus::Waiting->value])
            ->pluck('id')
            ->each(fn (int $id) => DB::transaction(fn () => self::end(self::lock($id), RunStatus::Cancelled, 'caller_stopped', 'The run that started it stopped.')));
    }

    /**
     * Run one queued step: claim it, do its work, and record what came of
     * it. Called by its job; a step that is no longer queued is left alone.
     */
    public static function runStep(int $nodeRunId): void
    {
        $claimed = self::claim($nodeRunId);

        if ($claimed === null) {
            return;
        }

        [$run, $nodeRun, $node, $context] = $claimed;

        try {
            $result = self::stepFor($node)->run($context);
            $size = strlen((string) json_encode($result->output));

            if ($size > self::MAX_OUTPUT_BYTES) {
                throw new StepFailed('It gave out '.number_format($size / 1024).' KB; a step can give out at most '.(self::MAX_OUTPUT_BYTES / 1024).' KB.');
            }

            if (! $result->waits() && ! in_array($result->handle, NodeTypes::outputsOf($node), true)) {
                throw new StepFailed("It chose a way out it doesn't have: {$result->handle}.");
            }

            self::succeed($run, $nodeRun, $result, $context);
        } catch (StepFailed $e) {
            self::fail($run, $nodeRun, $node, $e->getMessage(), $e->retryable, $context, $e->retryAfter);
        } catch (Throwable $e) {
            report($e);
            self::fail($run, $nodeRun, $node, 'Something went wrong inside this step.', false, $context);
        }
    }

    /**
     * A step whose job gave up without finishing it. When the job that gave
     * up was the one running it ($mine: it ran out of time), the step failed.
     * When it was a second copy turned away, the step may be running
     * elsewhere: that is for the sweep (recover) to judge, once its hold lapses.
     */
    public static function abandon(int $nodeRunId, string $why, bool $mine = true): void
    {
        $nodeRun = WorkflowNodeRun::find($nodeRunId);

        if ($nodeRun === null || $nodeRun->status->isFinal()) {
            return;
        }

        if (! $mine) {
            // Never taken, and its job is gone: it gets a new one
            if ($nodeRun->status === NodeStatus::Queued) {
                RunWorkflowStep::dispatch($nodeRun->id);
            }

            // Running elsewhere, or waiting: the sweep decides (recover)
            return;
        }

        self::fail($nodeRun->run, $nodeRun, self::nodeOf($nodeRun->run, $nodeRun->node_id), $why, false, null);
    }

    /**
     * Someone's answer to a step asking for approval: it carries on, that way.
     *
     * @throws DomainException when that step is not waiting for an answer
     */
    public static function answer(WorkflowNodeRun $step, bool $approved, User $by, ?string $note = null): void
    {
        DB::transaction(function () use ($step, $approved, $by, $note) {
            self::lock($step->workflow_run_id);
            $step->refresh();

            if ($step->status !== NodeStatus::Waiting || ! in_array($step->waiting_for, ['approval', 'resend'], true)) {
                throw new DomainException('This step is not waiting for an answer.');
            }

            // Not to be sent again: it ends there
            if ($step->waiting_for === 'resend' && ! $approved) {
                $run = $step->run;
                self::fail($run, $step, self::nodeOf($run, $step->node_id), "Not sent again: {$by->name} said so.", false, null);

                return;
            }

            self::move($step, NodeStatus::Queued, [
                'resume_at' => null,
                'wait' => [...($step->wait ?? []), 'answer' => [
                    'approved' => $approved,
                    'by' => $by->name,
                    'note' => $note,
                    'at' => now()->toIso8601String(),
                ]],
            ]);

            RunWorkflowStep::dispatch($step->id);
        });
    }

    /**
     * Stop a run: what has not begun never will. A step already running
     * finishes, and nothing comes after it.
     */
    public static function cancel(WorkflowRun $run, User $by): void
    {
        DB::transaction(function () use ($run, $by) {
            $run = self::lock($run->id);

            self::end($run, RunStatus::Cancelled, 'cancelled', "Cancelled by {$by->name}.");
        });
    }

    /**
     * Put right what a dead worker or a lost job left behind -- run every
     * minute by the scheduler (workflows:recover):
     *
     *  - a step held past its lease: its worker is gone. It is handed to
     *    another, up to MAX_ATTEMPTS tries in all, then failed;
     *  - a wait past its time that nobody woke: woken;
     *  - a run left open with nothing left to do: settled.
     *
     * @return array{requeued: int, asked: int, failed: int, woken: int, settled: int}
     */
    public static function recover(): array
    {
        $done = ['requeued' => 0, 'asked' => 0, 'failed' => 0, 'woken' => 0, 'settled' => 0, 'stuck' => 0];

        WorkflowNodeRun::where('status', NodeStatus::Running->value)
            ->where('lease_until', '<', now())
            ->pluck('id')
            ->each(function (int $id) use (&$done) {
                DB::transaction(function () use ($id, &$done) {
                    $step = WorkflowNodeRun::findOrFail($id);
                    $run = self::lock($step->workflow_run_id);
                    $step->refresh();

                    if ($step->status !== NodeStatus::Running || ! $step->lease_until?->isPast()) {
                        return;
                    }

                    $node = self::nodeOf($run, $step->node_id);
                    $unsure = self::repeatable($node) ? 'retry' : ($node['config']['unsure'] ?? 'ask');

                    // Whatever it was sending when its worker died may have gone out
                    WorkflowDelivery::where('workflow_node_run_id', $step->id)->where('status', 'sending')->update(['status' => 'unknown', 'updated_at' => now()]);

                    if ($run->status->isFinal()) {
                        self::move($step, NodeStatus::Cancelled, ['finished_at' => now(), 'lease_until' => null]);
                    } elseif ($unsure === 'ask') {
                        self::move($step, NodeStatus::Waiting, [
                            'waiting_for' => 'resend',
                            'resume_at' => null,
                            'wait' => ['message' => "“{$node['label']}” may not have gone out: its worker stopped partway through. Send it again?"],
                            'lease_until' => null,
                        ]);
                        Alerts::asks($step);
                        self::settle($run);
                        $done['asked']++;
                    } elseif ($unsure === 'never') {
                        self::fail($run, $step, $node, 'Its worker stopped partway, so it may not have gone out; it is not sent again unasked.', false, null);
                        $done['failed']++;
                    } elseif ($step->attempt < self::MAX_ATTEMPTS) {
                        self::move($step, NodeStatus::Queued, ['lease_until' => null]);
                        RunWorkflowStep::dispatch($step->id);
                        $done['requeued']++;
                    } else {
                        self::fail($run, $step, $node, 'Its worker stopped before it finished, '.self::MAX_ATTEMPTS.' times.', false, null);
                        $done['failed']++;
                    }
                });
            });

        WorkflowNodeRun::whereIn('status', [NodeStatus::Waiting->value, NodeStatus::RetryWait->value])
            ->where('resume_at', '<', now()->subMinutes(2))
            ->pluck('id')
            ->each(function (int $id) use (&$done) {
                RunWorkflowStep::dispatch($id);
                $done['woken']++;
            });

        WorkflowRun::whereIn('status', [RunStatus::Queued->value, RunStatus::Running->value, RunStatus::Waiting->value])
            ->where('updated_at', '<', now()->subMinutes(5))
            ->whereDoesntHave('steps', fn ($steps) => $steps->whereNotIn('status', [
                NodeStatus::Succeeded->value, NodeStatus::Failed->value, NodeStatus::Skipped->value, NodeStatus::Cancelled->value,
            ]))
            ->pluck('id')
            ->each(function (int $id) use (&$done) {
                DB::transaction(fn () => self::settle(self::lock($id)));
                $done['settled']++;
            });

        // A chat's held runs whose turn never came -- the run ahead deleted, say
        WorkflowRun::where('status', RunStatus::Queued->value)
            ->whereNotNull('run_key')
            ->where('created_at', '<', now()->subMinute())
            ->select(['workflow_id', 'run_key'])->distinct()->get()
            ->each(fn (WorkflowRun $held) => self::release($held->workflow_id, (string) $held->run_key));

        // Steps no worker has taken for a while: someone is told
        $done['stuck'] = Alerts::stuck();

        return $done;
    }

    /**
     * Take a queued step for this worker, counting it against the run's
     * budget, and gather what it may read. Null when it isn't ours to run.
     *
     * @return array{WorkflowRun, WorkflowNodeRun, Node, StepContext}|null
     */
    private static function claim(int $nodeRunId): ?array
    {
        return DB::transaction(function () use ($nodeRunId) {
            $nodeRun = WorkflowNodeRun::find($nodeRunId);

            if ($nodeRun === null) {
                return null;
            }

            $run = self::lock($nodeRun->workflow_run_id);
            $nodeRun->refresh();

            // Its wait is over: it carries on
            // Due since its wait ended: a late pickup is time spent waiting for a worker
            if ($nodeRun->status === NodeStatus::Waiting && $nodeRun->resume_at?->lte(now()->addSecond())) {
                self::move($nodeRun, NodeStatus::Queued, ['queued_at' => $nodeRun->resume_at]);
            }

            // Its time to try again has come: a new try, counted as one
            if ($nodeRun->status === NodeStatus::RetryWait && $nodeRun->resume_at?->lte(now()->addSecond())) {
                self::move($nodeRun, NodeStatus::Queued, ['resume_at' => null, 'waiting_for' => null, 'queued_at' => $nodeRun->resume_at]);
            }

            if ($nodeRun->status !== NodeStatus::Queued) {
                return null;
            }

            // Carrying on after a wait is not a new step, nor a new try
            $resuming = $nodeRun->waiting_for !== null;

            if ($run->status->isFinal()) {
                self::move($nodeRun, NodeStatus::Cancelled, ['finished_at' => now()]);

                return null;
            }

            if (! $resuming && $run->executed_steps >= $run->max_executed_steps) {
                self::end($run, RunStatus::Failed, 'budget_exceeded', "The run began {$run->max_executed_steps} steps, the most one may.");

                return null;
            }

            if (! $resuming) {
                $run->executed_steps++;
            }

            if ($run->status !== RunStatus::Running) {
                self::moveRun($run, RunStatus::Running, ['started_at' => $run->started_at ?? now()]);
            }

            $run->save();

            self::move($nodeRun, NodeStatus::Running, [
                'attempt' => $resuming ? $nodeRun->attempt : $nodeRun->attempt + 1,
                'started_at' => $resuming ? $nodeRun->started_at : now(),
                'lease_until' => now()->addSeconds(self::LEASE_SECONDS),
            ]);

            /** @var Shape $definition */
            $definition = $run->version->definition;
            /** @var Node $node */
            $node = collect($definition['nodes'])->firstWhere('id', $nodeRun->node_id);

            return [$run, $nodeRun, $node, self::context($run, $nodeRun, $node, $definition, $resuming ? ($nodeRun->wait ?? []) : null)];
        });
    }

    /**
     * What a step may read: the trigger's output, what came in from the step
     * (or steps) just before it, and every step before it by key.
     *
     * @param  Node  $node
     * @param  Shape  $definition
     * @param  array<string, mixed>|null  $waited
     */
    private static function context(WorkflowRun $run, WorkflowNodeRun $nodeRun, array $node, array $definition, ?array $waited = null): StepContext
    {
        $steps = $run->steps()->get()->keyBy('node_id');
        $byId = array_column($definition['nodes'], null, 'id');
        $parents = collect($definition['edges'])->where('target', $node['id']);
        $before = self::ancestors($node['id'], $definition['edges']);

        $outputs = [];
        $skipped = [];

        foreach ($before as $id) {
            $step = $steps->get($id);
            $key = $byId[$id]['key'];

            if ($step?->status === NodeStatus::Succeeded) {
                $outputs[$key] = $step->output;
            } elseif ($step?->status === NodeStatus::Skipped) {
                $skipped[$key] = true;
            }
        }

        // Only the ways in that were taken
        $came = $parents
            ->filter(function (array $edge) use ($steps) {
                $parent = $steps->get($edge['source']);

                return $parent?->status === NodeStatus::Succeeded && $parent->handle === $edge['sourceHandle'];
            })
            ->pluck('source')
            ->unique()
            ->values();

        $input = match ($came->count()) {
            0 => null,
            1 => $steps->get($came[0])?->output,
            // From several at once: each by its key
            default => $came->mapWithKeys(fn (string $id) => [$byId[$id]['key'] => $steps->get($id)?->output])->all(),
        };

        $trigger = $steps->get($run->trigger_node_id);

        return new StepContext(
            $run,
            $nodeRun,
            $node,
            new RunScope($trigger?->status === NodeStatus::Succeeded ? $trigger->output : null, $input, $outputs, $skipped),
            $input,
            $waited,
        );
    }

    private static function succeed(WorkflowRun $run, WorkflowNodeRun $nodeRun, StepResult $result, StepContext $context): void
    {
        DB::transaction(function () use ($run, $nodeRun, $result, $context) {
            $run = self::lock($run->id);
            $nodeRun->refresh();

            if ($nodeRun->status !== NodeStatus::Running) {
                return;
            }

            // It waits: woken by a job put off until its time, or by an answer
            if ($result->waits()) {
                self::move($nodeRun, NodeStatus::Waiting, [
                    'waiting_for' => $result->waitingFor,
                    'resume_at' => $result->resumeAt,
                    'wait' => $result->wait,
                    'input' => ['input' => $context->input, 'settings' => $context->settingsWorkedOut()],
                    'lease_until' => null,
                ]);

                if ($result->resumeAt !== null) {
                    RunWorkflowStep::dispatch($nodeRun->id)->delay($result->resumeAt);
                }

                self::settle($run);

                return;
            }

            self::move($nodeRun, NodeStatus::Succeeded, [
                'handle' => $result->handle,
                'input' => ['input' => $context->input, 'settings' => $context->settingsWorkedOut()],
                'output' => $result->output,
                'finished_at' => now(),
                'lease_until' => null,
            ]);

            self::settle($run);
        });
    }

    /**
     * A step that failed. One set to carry on gives out what went wrong and
     * the run goes on down its first way out; otherwise the run stops.
     *
     * @param  Node  $node
     */
    private static function fail(WorkflowRun $run, WorkflowNodeRun $nodeRun, array $node, string $message, bool $retryable, ?StepContext $context, ?int $retryAfter = null): void
    {
        DB::transaction(function () use ($run, $nodeRun, $node, $message, $retryable, $context, $retryAfter) {
            $run = self::lock($run->id);
            $nodeRun->refresh();

            if ($nodeRun->status->isFinal()) {
                return;
            }

            // Given up on before it was taken: it was begun, and failed
            if ($nodeRun->status === NodeStatus::Queued) {
                self::move($nodeRun, NodeStatus::Running, ['attempt' => $nodeRun->attempt + 1, 'started_at' => now()]);
            }

            $error = ['message' => $message, 'retryable' => $retryable];
            $input = $context === null ? null : ['input' => $context->input, 'settings' => $context->settingsWorkedOut()];

            // A moment's trouble: tried again later, sooner if the service said when
            if ($retryable && $nodeRun->status === NodeStatus::Running && $nodeRun->attempt < self::MAX_ATTEMPTS) {
                $wait = $retryAfter ?? 5 * 3 ** ($nodeRun->attempt - 1) + random_int(0, 2);
                $at = now()->addSeconds(min(max($wait, 1), 3600));

                self::move($nodeRun, NodeStatus::RetryWait, [
                    'input' => $input,
                    'error' => $error,
                    'resume_at' => $at,
                    'lease_until' => null,
                ]);
                RunWorkflowStep::dispatch($nodeRun->id)->delay($at);
                self::settle($run);

                return;
            }

            $carryOn = $node['onError'] === 'continue';

            if ($carryOn) {
                self::move($nodeRun, NodeStatus::Succeeded, [
                    'handle' => NodeTypes::outputsOf($node)[0] ?? NodeTypes::OUT,
                    'input' => $input,
                    // What it gives out when empty, if its kind says, so later steps read nothing
                    'output' => [...(NodeTypes::get($node['type'])['empty'] ?? []), 'error' => $message],
                    'error' => $error,
                    'finished_at' => now(),
                    'lease_until' => null,
                ]);

                self::settle($run);

                return;
            }

            self::move($nodeRun, NodeStatus::Failed, [
                'input' => $input,
                'error' => $error,
                'finished_at' => now(),
                'lease_until' => null,
            ]);

            self::end($run, RunStatus::Failed, 'node_failed', "{$node['label']}: {$message}");
        });
    }

    /**
     * Work out, under the run's lock, what follows from where its steps
     * stand: steps whose every way in has been decided are queued or
     * skipped, until nothing more changes; then, when nothing is left to
     * run, the run has succeeded.
     */
    private static function settle(WorkflowRun $run): void
    {
        if ($run->status->isFinal()) {
            return;
        }

        /** @var Shape $definition */
        $definition = $run->version->definition;
        /** @var Collection<string, WorkflowNodeRun> $steps */
        $steps = $run->steps()->get()->keyBy('node_id');
        $into = collect($definition['edges'])->groupBy('target');
        // A Merge that carries on with the first to arrive need not wait for the rest
        $first = collect($definition['nodes'])
            ->filter(fn (array $node) => $node['type'] === 'logic.merge' && ($node['config']['wait'] ?? 'all') === 'first')
            ->pluck('id')
            ->flip();

        do {
            $changed = false;

            foreach ($steps as $id => $step) {
                if ($step->status !== NodeStatus::Pending) {
                    continue;
                }

                $edges = $into->get($id, collect());

                // A trigger the run didn't start from, or a step nothing leads to
                if ($edges->isEmpty()) {
                    self::move($step, NodeStatus::Skipped, ['finished_at' => now()]);
                    $changed = true;

                    continue;
                }

                $taken = false;
                $undecided = false;

                foreach ($edges as $edge) {
                    $parent = $steps->get($edge['source']);

                    if ($parent === null || in_array($parent->status, [NodeStatus::Failed, NodeStatus::Cancelled], true)) {
                        // The run is stopping; this step will be cancelled with it
                        $undecided = true;
                    } elseif ($parent->status === NodeStatus::Succeeded) {
                        $taken = $taken || $parent->handle === $edge['sourceHandle'];
                    } elseif ($parent->status !== NodeStatus::Skipped) {
                        $undecided = true;
                    }
                }

                if ($undecided && ! ($taken && $first->has($id))) {
                    continue;
                }

                if ($taken) {
                    self::queue($run, $id, $step);
                } else {
                    self::move($step, NodeStatus::Skipped, ['finished_at' => now()]);
                }

                $changed = true;
            }
        } while ($changed);

        $open = $steps->reject(fn (WorkflowNodeRun $step) => $step->status->isFinal());

        if ($open->isEmpty()) {
            self::end($run, RunStatus::Succeeded);

            return;
        }

        // Nothing queued or running, so nothing to do but wait (the steps
        // still pending are behind a waiting one): the run waits too
        $onlyWaiting = ! $open->contains(fn (WorkflowNodeRun $step) => in_array($step->status, [NodeStatus::Queued, NodeStatus::Running], true));

        if ($onlyWaiting && $run->status === RunStatus::Running) {
            self::moveRun($run, RunStatus::Waiting);
            $run->save();
        } elseif (! $onlyWaiting && $run->status === RunStatus::Waiting) {
            self::moveRun($run, RunStatus::Running);
            $run->save();
        }
    }

    /**
     * Hand a pending step to a worker: marked queued, and its job written, together.
     */
    private static function queue(WorkflowRun $run, string $nodeId, ?WorkflowNodeRun $step = null): void
    {
        $step ??= $run->steps()->where('node_id', $nodeId)->firstOrFail();

        $moved = WorkflowNodeRun::whereKey($step->id)
            ->where('status', NodeStatus::Pending->value)
            ->update(['status' => NodeStatus::Queued->value, 'queued_at' => now(), 'updated_at' => now()]);

        if ($moved === 1) {
            self::changed($run->id);
            $step->status = NodeStatus::Queued;
            $step->syncOriginalAttribute('status');
            RunWorkflowStep::dispatch($step->id);
        }
    }

    /**
     * End a run, cancelling whatever of it has not started.
     */
    private static function end(WorkflowRun $run, RunStatus $status, ?string $reason = null, ?string $error = null): void
    {
        if ($run->status->isFinal()) {
            return;
        }

        self::moveRun($run, $status, [
            'reason' => $reason,
            'error' => $error === null ? null : mb_substr($error, 0, 2000),
            'finished_at' => now(),
            'started_at' => $run->started_at ?? now(),
        ]);
        $run->save();

        if ($status === RunStatus::Failed) {
            Alerts::failed($run);
        }

        // What waits on this one: the chat's next run, the step that started it,
        // and -- when it didn't succeed -- the runs it started
        $runId = $run->id;
        $workflowId = $run->workflow_id;
        $key = $run->run_key;
        $caller = $run->caller_node_run_id;
        DB::afterCommit(function () use ($runId, $workflowId, $key, $caller, $status) {
            if ($key !== null) {
                self::release($workflowId, $key);
            }

            if ($caller !== null) {
                self::wakeCaller($caller);
            }

            if ($status !== RunStatus::Succeeded) {
                self::stopChildren($runId);
            }
        });

        if ($status !== RunStatus::Succeeded) {
            $run->steps()
                ->whereIn('status', [NodeStatus::Pending->value, NodeStatus::Queued->value, NodeStatus::Waiting->value, NodeStatus::RetryWait->value])
                ->update(['status' => NodeStatus::Cancelled->value, 'finished_at' => now(), 'updated_at' => now()]);
        }
    }

    /**
     * Whether a step may simply be run again when its worker died partway:
     * not one that sends something outside, which may have gone out already.
     *
     * @param  Node  $node
     */
    private static function repeatable(array $node): bool
    {
        if ($node['type'] === 'http.request') {
            return in_array(strtoupper((string) ($node['config']['method'] ?? 'GET')), ['GET', 'HEAD'], true);
        }

        return NodeTypes::get($node['type'])['repeatable'] ?? true;
    }

    /**
     * A step of the run's version, as defined.
     *
     * @return Node
     */
    private static function nodeOf(WorkflowRun $run, string $nodeId): array
    {
        /** @var Shape $definition */
        $definition = $run->version->definition;

        return collect($definition['nodes'])->firstWhere('id', $nodeId)
            ?? throw new \LogicException("The run's version has no step {$nodeId}.");
    }

    /**
     * Tell whoever has the workflow open that a run moved on -- once what
     * moved it is saved, and once however many steps moved with it.
     */
    private static function changed(int $runId): void
    {
        if (isset(self::$changed[$runId])) {
            return;
        }

        self::$changed[$runId] = true;

        DB::afterCommit(function () use ($runId) {
            unset(self::$changed[$runId]);
            $run = WorkflowRun::with('workflow:id,ref_id')->find($runId);

            if ($run !== null) {
                Live::tellEveryone(new WorkflowRunChanged($run));
            }
        });

        // Nothing was saved, so there is nothing to tell -- and the next change is told afresh
        DB::afterRollBack(function () use ($runId) {
            unset(self::$changed[$runId]);
        });
    }

    private static function lock(int $runId): WorkflowRun
    {
        return WorkflowRun::whereKey($runId)->lockForUpdate()->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $with
     */
    private static function move(WorkflowNodeRun $step, NodeStatus $to, array $with = []): void
    {
        if (! $step->status->canBecome($to)) {
            throw new \LogicException("A step can't go from {$step->status->value} to {$to->value}.");
        }

        // When it began waiting for a worker, so how long it waited can be told
        if ($to === NodeStatus::Queued && ! array_key_exists('queued_at', $with)) {
            $with['queued_at'] = now();
        }

        $step->forceFill(['status' => $to, ...$with])->save();
        self::changed($step->workflow_run_id);
    }

    /**
     * @param  array<string, mixed>  $with
     */
    private static function moveRun(WorkflowRun $run, RunStatus $to, array $with = []): void
    {
        if (! $run->status->canBecome($to)) {
            throw new \LogicException("A run can't go from {$run->status->value} to {$to->value}.");
        }

        $run->forceFill(['status' => $to, ...$with]);
        self::changed($run->id);
    }

    /**
     * @param  Node  $node
     */
    private static function stepFor(array $node): Step
    {
        $type = NodeTypes::get($node['type']);
        $version = $node['nodeVersion'];
        $kept = self::STEPS["{$node['type']}@{$version}"] ?? null;
        $class = $kept ?? self::STEPS[$node['type']] ?? null;

        if ($class === null || $type === null) {
            throw new StepFailed("This app can't run a step of the kind {$node['type']}.");
        }

        // Published by a newer app than this one -- one rolled back since
        if ($version > $type['version']) {
            throw new StepFailed("This step is of version {$version}, newer than this app runs ({$type['version']}). It runs once the app is updated again.");
        }

        if ($kept === null && $version < ($type['oldest'] ?? 1)) {
            throw new StepFailed("This step is of version {$version}, which this app no longer runs. Publish the workflow again to bring it up to date.");
        }

        return app($class);
    }

    /**
     * Every step before one, directly or not.
     *
     * @param  list<array{source: string, target: string}>  $edges
     * @return list<string>
     */
    private static function ancestors(string $id, array $edges): array
    {
        $found = [];
        $queue = [$id];

        while ($queue !== []) {
            $next = array_shift($queue);

            foreach ($edges as $edge) {
                if ($edge['target'] === $next && ! isset($found[$edge['source']])) {
                    $found[$edge['source']] = true;
                    $queue[] = $edge['source'];
                }
            }
        }

        return array_keys($found);
    }
}
