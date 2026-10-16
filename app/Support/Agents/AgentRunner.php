<?php

namespace App\Support\Agents;

use App\Models\Agent\Agent;
use App\Models\Agent\AgentVersion;
use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatRoom;
use App\Models\Connection;
use App\Models\User;
use App\Support\Ai\AiClient;
use App\Support\Ai\AiFailed;
use App\Support\Knowledge\Knowledge;
use Closure;

/**
 * An agent asked something: by its instructions, through its model, given
 * what its knowledge base finds for the question and -- in a chat -- the
 * conversation so far. Any version of it can be asked; the latest unless
 * one is named.
 *
 * @phpstan-import-type Source from Sourced
 * @phpstan-import-type Done from Toolbox
 * @phpstan-import-type Offered from Toolbox
 *
 * @phpstan-type Answer array{text: string, json: mixed, version: int, model: string, found: int, knows: bool, sources: list<Source>, actions: list<Done>}
 */
final class AgentRunner
{
    /** The most of a conversation given, in messages. */
    private const RECENT = 12;

    /** How many times a model may go to its tools before it must answer, and how many tools at once. */
    private const ROUNDS = 5;

    private const CALLS_AT_ONCE = 4;

    public function __construct(private AiClient $ai, private Knowledge $knowledge) {}

    /**
     * Given tools and someone it works for, it may use them -- as that
     * person, for its owner -- before it answers; one that changes
     * something only when $changes allows it.
     *
     * @param  (Closure(string): void)|null  $piece  told each piece of the answer as it comes
     * @return Answer
     *
     * @throws AiFailed when it has no model to ask, or the model fails
     */
    public function ask(Agent $agent, ?int $version, string $message, ?ChatRoom $room = null, ?string $more = null, ?Closure $piece = null, ?User $for = null, bool $changes = false): array
    {
        $at = $agent->at($version) ?? throw new AiFailed($version === null
            ? "\"{$agent->name}\" has no model yet: choose one on its page."
            : "\"{$agent->name}\" has no version {$version}.");
        $connection = $at->connection ?? throw new AiFailed("Version {$at->number} of \"{$agent->name}\" has no AI connection: it is gone, or none was chosen.");
        $model = $at->model ?: $connection->setting('default_model') ?: throw new AiFailed("Version {$at->number} of \"{$agent->name}\" names no model.");

        [$context, $sources] = $this->known($at, $message);

        $asked = implode("\n\n", array_filter([
            $context === null ? null : "Knowledge:\n{$context}",
            $room === null ? null : "The conversation so far:\n".self::transcript($room),
            "Their latest message: {$message}",
        ]));

        $messages = [
            ['role' => 'system', 'content' => trim(implode("\n\n", array_filter([(string) $at->instructions, $more])))],
            ['role' => 'user', 'content' => $asked],
        ];
        $actions = [];
        [$offered, $named] = $for !== null && ($at->tools ?? []) !== [] ? Toolbox::offer($agent->owner(), $at->tools ?? [], $changes) : [[], []];

        if ($offered === []) {
            $text = '';

            foreach ($this->ai->stream($connection, $model, $messages) as $part) {
                $text .= $part;

                if ($piece !== null) {
                    $piece($part);
                }
            }
        } else {
            [$text, $actions] = $this->withTools($connection, $model, $messages, $offered, $named, $for, $agent);
            $piece !== null && $piece($text);
        }

        $text = trim($text);

        return [
            'text' => $text,
            'json' => json_decode(self::unfenced($text), true),
            'version' => $at->number,
            'model' => $model,
            'found' => count($sources),
            // Whether it has a knowledge base at all, and what it was given of it: for whoever reads the answer to check it by
            'knows' => $at->knowledgeBase !== null,
            'sources' => $sources,
            // What it did with its tools before it answered: each call, and what came of it
            'actions' => $actions,
        ];
    }

    /**
     * A model going to its tools until it answers: each call it asks for is
     * made, and what came of it given back, a few rounds at most.
     *
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>  $offered
     * @param  array<string, Offered>  $named
     * @return array{0: string, 1: list<Done>}
     *
     * @throws AiFailed
     */
    private function withTools(Connection $connection, string $model, array $messages, array $offered, array $named, ?User $for, Agent $agent): array
    {
        $actions = [];

        for ($round = 0; $round < self::ROUNDS; $round++) {
            // The last round is for answering: no more tools then
            $said = $this->ai->complete($connection, $model, $messages, $round === self::ROUNDS - 1 ? [] : $offered);

            if ($said['calls'] === []) {
                return [$said['content'], $actions];
            }

            $messages[] = ['role' => 'assistant', 'content' => $said['content'], 'tool_calls' => array_map(fn (array $call) => [
                'id' => $call['id'], 'type' => 'function', 'function' => ['name' => $call['name'], 'arguments' => (string) json_encode((object) $call['arguments'])],
            ], $said['calls'])];

            foreach (array_slice($said['calls'], 0, self::CALLS_AT_ONCE) as $call) {
                $tool = $named[$call['name']] ?? null;

                if ($tool === null || $for === null) {
                    $result = "There is no tool {$call['name']} to use.";
                } else {
                    [$result, $done] = Toolbox::use($tool, $call['arguments'], $for, $agent->owner());
                    $actions[] = $done;
                }

                $messages[] = ['role' => 'tool', 'tool_call_id' => $call['id'], 'content' => $result];
            }
        }

        return ['', $actions];
    }

    /**
     * What the version's knowledge base finds for the question, as one
     * text, and the passages it is, each as a source.
     *
     * @return array{0: string|null, 1: list<Source>}
     */
    private function known(AgentVersion $at, string $message): array
    {
        $base = $at->knowledgeBase;

        if ($base === null || $base->read_at === null) {
            return [null, []];
        }

        $found = $this->knowledge->find($base, $message, 5);

        return [$found === [] ? '(nothing in the knowledge base about this)' : Knowledge::context($found), Sourced::from($found)];
    }

    /**
     * A chat's latest messages as a model reads them: who said what, a line
     * each, notes for the team left out.
     */
    public static function transcript(ChatRoom $room, int $most = self::RECENT): string
    {
        return $room->messages()->with('author:id,name')->reorder('id', 'desc')->limit($most)->get()->reverse()
            ->reject(fn (ChatMessage $message) => $message->isInternal())
            ->map(fn (ChatMessage $message) => match (true) {
                $message->isFromOutside() => 'Customer: ',
                $message->role === ChatMessage::USER => 'Team ('.($message->author->name ?? 'someone').'): ',
                default => 'Assistant: ',
            }.trim(preg_replace('/\s+/u', ' ', $message->content) ?? ''))
            ->implode("\n");
    }

    /**
     * An answer without the ``` fence a model may wrap JSON in.
     */
    private static function unfenced(string $text): string
    {
        return preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', trim($text), $match) === 1 ? $match[1] : trim($text);
    }
}
