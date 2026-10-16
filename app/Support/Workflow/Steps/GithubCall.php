<?php

namespace App\Support\Workflow\Steps;

use App\Models\Connection;
use App\Support\Connections\Services;
use App\Support\Workflow\References;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * GitHub's calls, each a kind of step of its own (ServiceKinds names them;
 * this runs them): issues and their comments, pull requests, a repository's
 * files and commits, Actions runs, releases -- and any other call of its
 * REST API, written out.
 *
 * Each acts with the connection's token, on a repository named "owner/name"
 * -- or, left empty, the connection's own. What adds something (an issue, a
 * comment, a pull request, a release, a run of an Action) is sent once:
 * broken off after it may have gone out, it is not sent again unasked.
 */
final class GithubCall implements Step
{
    public const API = 'https://api.github.com';

    /** The most of a file a step gives out as its text. */
    private const MOST_TEXT = 200 * 1024;

    public function run(StepContext $step): StepResult
    {
        $github = self::connection($step);

        return new StepResult(match ($step->node['type']) {
            'github.issue_create' => self::issueCreate($step, $github),
            'github.issue_update' => self::issueUpdate($step, $github),
            'github.issue' => self::issue(self::ask($step, $github, 'GET', self::repo($step, $github).'/issues/'.self::number($step))),
            'github.issues' => self::issues($step, $github),
            'github.comment' => self::comment($step, $github),
            'github.comments' => self::comments($step, $github),
            'github.labels_add' => self::labelsAdd($step, $github),
            'github.pull' => self::pull(self::ask($step, $github, 'GET', self::repo($step, $github).'/pulls/'.self::number($step))),
            'github.pulls' => self::pulls($step, $github),
            'github.pull_create' => self::pullCreate($step, $github),
            'github.merge' => self::merge($step, $github),
            'github.file' => self::file($step, $github),
            'github.file_save' => self::fileSave($step, $github),
            'github.commits' => self::commits($step, $github),
            'github.branch_create' => self::branchCreate($step, $github),
            'github.dispatch' => self::dispatch($step, $github),
            'github.runs' => self::runs($step, $github),
            'github.release_create' => self::releaseCreate($step, $github),
            'github.release_latest' => self::release(self::ask($step, $github, 'GET', self::repo($step, $github).'/releases/latest')),
            'github.request' => self::request($step, $github),
            'github.me' => self::me($step, $github),

            default => throw new StepFailed("This app doesn't know the GitHub step \"{$step->node['type']}\"."),
        });
    }

    // --- Issues ---

    /**
     * @return array<string, mixed>
     */
    private static function issueCreate(StepContext $step, Connection $github): array
    {
        $repo = self::repo($step, $github);

        return self::issue(self::send($step, $github, 'POST', "{$repo}/issues", array_filter([
            'title' => SearchSteps::needs($step, 'title', 'no title for the issue'),
            'body' => trim($step->text('body')) ?: null,
            'labels' => SearchSteps::listed($step, 'labels') ?: null,
            'assignees' => SearchSteps::listed($step, 'assignees') ?: null,
        ], fn ($value) => $value !== null)));
    }

    /**
     * Only what is given changes: an empty title or body is left as it was,
     * and labels or people named replace the ones it had.
     *
     * @return array<string, mixed>
     */
    private static function issueUpdate(StepContext $step, Connection $github): array
    {
        $state = (string) $step->setting('state');
        $change = array_filter([
            'title' => trim($step->text('title')) ?: null,
            'body' => trim($step->text('body')) ?: null,
            'state' => in_array($state, ['open', 'closed'], true) ? $state : null,
            'state_reason' => $state === 'closed' ? ($step->setting('reason') === 'not_planned' ? 'not_planned' : 'completed') : null,
            'labels' => SearchSteps::listed($step, 'labels') ?: null,
            'assignees' => SearchSteps::listed($step, 'assignees') ?: null,
        ], fn ($value) => $value !== null);

        if ($change === []) {
            throw new StepFailed('There is nothing to change: give it a title, words, a state, labels or people.');
        }

        return self::issue(self::ask($step, $github, 'PATCH', self::repo($step, $github).'/issues/'.self::number($step), $change));
    }

    /**
     * @return array<string, mixed>
     */
    private static function issues(StepContext $step, Connection $github): array
    {
        $kind = (string) ($step->setting('kind') ?: 'issues');
        $since = trim($step->text('since'));
        $found = self::ask($step, $github, 'GET', self::repo($step, $github).'/issues', array_filter([
            'state' => in_array($step->setting('state'), ['open', 'closed', 'all'], true) ? $step->setting('state') : 'open',
            'labels' => implode(',', SearchSteps::listed($step, 'labels')) ?: null,
            'assignee' => trim($step->text('assignee')) ?: null,
            'since' => $since === '' ? null : self::when($since),
            'sort' => 'updated',
            'per_page' => SearchSteps::count($step, 'count', 30, 100),
        ], fn ($value) => $value !== null));

        // GitHub counts a pull request as an issue too
        $rows = array_values(array_filter(
            array_filter((array) $found, is_array(...)),
            fn (array $one) => match ($kind) {
                'pulls' => isset($one['pull_request']),
                'both' => true,
                default => ! isset($one['pull_request']),
            },
        ));
        $issues = array_map(fn (array $one) => self::issue($one, short: true), $rows);

        return ['issues' => $issues, 'count' => count($issues)];
    }

    /**
     * @return array<string, mixed>
     */
    private static function comment(StepContext $step, Connection $github): array
    {
        $said = self::send($step, $github, 'POST', self::repo($step, $github).'/issues/'.self::number($step).'/comments', [
            'body' => SearchSteps::needs($step, 'body', 'nothing to say'),
        ]);

        return self::said($said);
    }

    /**
     * @return array<string, mixed>
     */
    private static function comments(StepContext $step, Connection $github): array
    {
        $found = self::ask($step, $github, 'GET', self::repo($step, $github).'/issues/'.self::number($step).'/comments', [
            'per_page' => SearchSteps::count($step, 'count', 30, 100),
        ]);
        $comments = array_map(self::said(...), array_values(array_filter((array) $found, is_array(...))));

        return ['comments' => $comments, 'count' => count($comments)];
    }

    /**
     * @return array<string, mixed>
     */
    private static function labelsAdd(StepContext $step, Connection $github): array
    {
        $labels = SearchSteps::listed($step, 'labels') ?: throw new StepFailed('There are no labels to put on.');
        $now = self::ask($step, $github, 'POST', self::repo($step, $github).'/issues/'.self::number($step).'/labels', ['labels' => $labels]);

        return ['labels' => array_values(array_filter(array_map(fn ($label) => is_array($label) ? ($label['name'] ?? null) : null, (array) $now), is_string(...)))];
    }

    // --- Pull requests ---

    /**
     * @return array<string, mixed>
     */
    private static function pulls(StepContext $step, Connection $github): array
    {
        $found = self::ask($step, $github, 'GET', self::repo($step, $github).'/pulls', array_filter([
            'state' => in_array($step->setting('state'), ['open', 'closed', 'all'], true) ? $step->setting('state') : 'open',
            'base' => trim($step->text('base')) ?: null,
            'sort' => 'updated',
            'direction' => 'desc',
            'per_page' => SearchSteps::count($step, 'count', 30, 100),
        ], fn ($value) => $value !== null));
        $pulls = array_map(fn (array $one) => self::pull($one, short: true), array_values(array_filter((array) $found, is_array(...))));

        return ['pulls' => $pulls, 'count' => count($pulls)];
    }

    /**
     * @return array<string, mixed>
     */
    private static function pullCreate(StepContext $step, Connection $github): array
    {
        return self::pull(self::send($step, $github, 'POST', self::repo($step, $github).'/pulls', array_filter([
            'title' => SearchSteps::needs($step, 'title', 'no title for the pull request'),
            'head' => SearchSteps::needs($step, 'head', 'no branch to bring in'),
            'base' => SearchSteps::needs($step, 'base', 'no branch to bring it into'),
            'body' => trim($step->text('body')) ?: null,
            'draft' => $step->setting('draft') === 'yes' ? true : null,
        ], fn ($value) => $value !== null)));
    }

    /**
     * @return array<string, mixed>
     */
    private static function merge(StepContext $step, Connection $github): array
    {
        $method = in_array($step->setting('method'), ['merge', 'squash', 'rebase'], true) ? $step->setting('method') : 'merge';
        $merged = self::send($step, $github, 'PUT', self::repo($step, $github).'/pulls/'.self::number($step).'/merge', array_filter([
            'merge_method' => $method,
            'commit_title' => trim($step->text('title')) ?: null,
        ], fn ($value) => $value !== null));

        return ['merged' => (bool) ($merged['merged'] ?? false), 'sha' => $merged['sha'] ?? null, 'message' => $merged['message'] ?? null];
    }

    // --- Code ---

    /**
     * A file's words, as they are on a branch -- or a folder's list of what is in it.
     *
     * @return array<string, mixed>
     */
    private static function file(StepContext $step, Connection $github): array
    {
        $path = trim(SearchSteps::needs($step, 'path', 'no file to read: its path'), '/');
        $ref = trim($step->text('ref'));
        $found = self::ask($step, $github, 'GET', self::repo($step, $github).'/contents/'.self::path($path), $ref === '' ? [] : ['ref' => $ref]);

        // A folder is a list of what is in it
        if (array_is_list($found)) {
            return ['path' => $path, 'folder' => true, 'items' => array_map(fn ($one) => is_array($one) ? [
                'name' => $one['name'] ?? null,
                'path' => $one['path'] ?? null,
                'type' => $one['type'] ?? null,
                'size' => $one['size'] ?? null,
            ] : null, $found)];
        }

        $bytes = ($found['encoding'] ?? '') === 'base64' ? (string) base64_decode((string) ($found['content'] ?? ''), true) : '';
        $text = mb_check_encoding($bytes, 'UTF-8') ? $bytes : null;
        $cut = $text !== null && strlen($text) > self::MOST_TEXT;

        return [
            'path' => $found['path'] ?? $path,
            'sha' => $found['sha'] ?? null,
            'size' => $found['size'] ?? strlen($bytes),
            // Words, cut to what a step may give out; a picture or a zip has none
            'text' => $text === null ? null : ($cut ? mb_strcut($text, 0, self::MOST_TEXT) : $text),
            'cut' => $cut,
            'binary' => $text === null,
            'url' => $found['html_url'] ?? null,
            'download' => $found['download_url'] ?? null,
        ];
    }

    /**
     * A file written, as a commit on a branch: made, or changed from what it was.
     *
     * @return array<string, mixed>
     */
    private static function fileSave(StepContext $step, Connection $github): array
    {
        $repo = self::repo($step, $github);
        $path = trim(SearchSteps::needs($step, 'path', 'no file to write: its path'), '/');
        $branch = trim($step->text('branch'));
        $at = "{$repo}/contents/".self::path($path);

        // Changing a file names the one it changes
        $was = self::found($github, $at, $branch === '' ? [] : ['ref' => $branch]);
        $saved = self::send($step, $github, 'PUT', $at, array_filter([
            'message' => trim($step->text('message')) ?: "Update {$path}",
            'content' => base64_encode($step->text('content')),
            'branch' => $branch ?: null,
            'sha' => is_array($was) && ! array_is_list($was) ? ($was['sha'] ?? null) : null,
        ], fn ($value) => $value !== null));

        return [
            'path' => $saved['content']['path'] ?? $path,
            'sha' => $saved['content']['sha'] ?? null,
            'made' => $was === null,
            'commit' => $saved['commit']['sha'] ?? null,
            'url' => $saved['commit']['html_url'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function commits(StepContext $step, Connection $github): array
    {
        $since = trim($step->text('since'));
        $found = self::ask($step, $github, 'GET', self::repo($step, $github).'/commits', array_filter([
            'sha' => trim($step->text('branch')) ?: null,
            'path' => trim($step->text('path')) ?: null,
            'author' => trim($step->text('author')) ?: null,
            'since' => $since === '' ? null : self::when($since),
            'per_page' => SearchSteps::count($step, 'count', 30, 100),
        ], fn ($value) => $value !== null));
        $commits = array_map(fn (array $one) => [
            'sha' => $one['sha'] ?? null,
            'message' => $one['commit']['message'] ?? null,
            'author' => $one['author']['login'] ?? $one['commit']['author']['name'] ?? null,
            'at' => $one['commit']['author']['date'] ?? null,
            'url' => $one['html_url'] ?? null,
        ], array_values(array_filter((array) $found, is_array(...))));

        return ['commits' => $commits, 'count' => count($commits)];
    }

    /**
     * @return array<string, mixed>
     */
    private static function branchCreate(StepContext $step, Connection $github): array
    {
        $repo = self::repo($step, $github);
        $name = SearchSteps::needs($step, 'name', 'no name for the branch');
        $from = trim($step->text('from'));

        // From the repository's usual branch, unless another is named
        if ($from === '') {
            $from = (string) (self::ask($step, $github, 'GET', $repo)['default_branch'] ?? 'main');
        }

        $sha = self::ask($step, $github, 'GET', "{$repo}/git/ref/heads/".self::path($from))['object']['sha']
            ?? throw new StepFailed("GitHub did not say where \"{$from}\" is.");
        $made = self::ask($step, $github, 'POST', "{$repo}/git/refs", ['ref' => "refs/heads/{$name}", 'sha' => $sha]);

        return ['branch' => $name, 'from' => $from, 'sha' => $made['object']['sha'] ?? $sha];
    }

    // --- Actions and releases ---

    /**
     * An Action set to run by hand (on: workflow_dispatch) started. GitHub
     * says only that it was taken; "GitHub: Actions runs" finds the run.
     *
     * @return array<string, mixed>
     */
    private static function dispatch(StepContext $step, Connection $github): array
    {
        $repo = self::repo($step, $github);
        $workflow = SearchSteps::needs($step, 'workflow', 'no Action to run: its file name, like build.yml');
        $ref = trim($step->text('ref'));

        if ($ref === '') {
            $ref = (string) (self::ask($step, $github, 'GET', $repo)['default_branch'] ?? 'main');
        }

        $inputs = array_map(fn ($value) => is_scalar($value) ? (string) $value : json_encode($value), $step->fields('inputs'));
        self::send($step, $github, 'POST', "{$repo}/actions/workflows/".self::path($workflow).'/dispatches', [
            'ref' => $ref,
            ...$inputs === [] ? [] : ['inputs' => $inputs],
        ]);

        return ['started' => true, 'workflow' => $workflow, 'ref' => $ref];
    }

    /**
     * @return array<string, mixed>
     */
    private static function runs(StepContext $step, Connection $github): array
    {
        $repo = self::repo($step, $github);
        $workflow = trim($step->text('workflow'));
        $found = self::ask($step, $github, 'GET', $workflow === '' ? "{$repo}/actions/runs" : "{$repo}/actions/workflows/".self::path($workflow).'/runs', array_filter([
            'branch' => trim($step->text('branch')) ?: null,
            'status' => in_array($step->setting('status'), ['queued', 'in_progress', 'completed', 'success', 'failure', 'cancelled'], true) ? $step->setting('status') : null,
            'per_page' => SearchSteps::count($step, 'count', 10, 100),
        ], fn ($value) => $value !== null));
        $runs = array_map(fn (array $one) => [
            'id' => $one['id'] ?? null,
            'name' => $one['name'] ?? null,
            'status' => $one['status'] ?? null,
            'conclusion' => $one['conclusion'] ?? null,
            'branch' => $one['head_branch'] ?? null,
            'sha' => $one['head_sha'] ?? null,
            'event' => $one['event'] ?? null,
            'at' => $one['created_at'] ?? null,
            'url' => $one['html_url'] ?? null,
        ], array_values(array_filter((array) ($found['workflow_runs'] ?? []), is_array(...))));

        return ['runs' => $runs, 'count' => count($runs), 'total' => $found['total_count'] ?? count($runs)];
    }

    /**
     * @return array<string, mixed>
     */
    private static function releaseCreate(StepContext $step, Connection $github): array
    {
        return self::release(self::send($step, $github, 'POST', self::repo($step, $github).'/releases', array_filter([
            'tag_name' => SearchSteps::needs($step, 'tag', 'no tag for the release, like v1.2.0'),
            'name' => trim($step->text('name')) ?: null,
            'body' => trim($step->text('body')) ?: null,
            'target_commitish' => trim($step->text('target')) ?: null,
            'draft' => $step->setting('draft') === 'yes' ? true : null,
            'prerelease' => $step->setting('prerelease') === 'yes' ? true : null,
            'generate_release_notes' => $step->setting('notes') === 'yes' ? true : null,
        ], fn ($value) => $value !== null)));
    }

    // --- Anything else ---

    /**
     * Any other call of GitHub's REST API, as its docs write it: a path from
     * the top ("/repos/{owner}/{repo}/topics"), with a JSON body for what
     * changes something. One that changes something is sent once.
     *
     * @return array<string, mixed>
     */
    private static function request(StepContext $step, Connection $github): array
    {
        $method = in_array($step->setting('method'), ['GET', 'POST', 'PATCH', 'PUT', 'DELETE'], true) ? $step->setting('method') : 'GET';
        $path = '/'.ltrim(SearchSteps::needs($step, 'path', 'no path to call, like /repos/owner/name/topics'), '/');

        if (preg_match('#^/[A-Za-z0-9._~\-/{}%]*$#', (string) parse_url($path, PHP_URL_PATH)) !== 1 || str_contains($path, '..')) {
            throw new StepFailed("\"{$path}\" is not a path of GitHub's API.");
        }

        $raw = trim($step->text('body'));
        $body = $raw === '' ? [] : json_decode($raw, true);

        if (! is_array($body)) {
            throw new StepFailed('Its body is not JSON: an object, like {"names": ["zyrenn"]}.');
        }

        $query = $step->fields('query');
        $answer = $method === 'GET'
            ? self::ask($step, $github, 'GET', $path, $query)
            : self::send($step, $github, $method, $path.($query === [] ? '' : '?'.http_build_query($query)), $body);

        return ['answer' => $answer];
    }

    /**
     * @return array<string, mixed>
     */
    private static function me(StepContext $step, Connection $github): array
    {
        $me = self::ask($step, $github, 'GET', '/user');

        return ['login' => $me['login'] ?? null, 'name' => $me['name'] ?? null, 'url' => $me['html_url'] ?? null];
    }

    // --- Shapes ---

    /**
     * An issue -- or a pull request, which GitHub counts as one too -- as a step gives it out.
     *
     * @param  array<mixed>  $one
     * @return array<string, mixed>
     */
    private static function issue(array $one, bool $short = false): array
    {
        return [
            'number' => $one['number'] ?? null,
            'title' => $one['title'] ?? null,
            'state' => $one['state'] ?? null,
            'pull' => isset($one['pull_request']),
            'labels' => self::names($one['labels'] ?? []),
            'assignees' => self::names($one['assignees'] ?? [], 'login'),
            'author' => $one['user']['login'] ?? null,
            'comments' => $one['comments'] ?? null,
            'url' => $one['html_url'] ?? null,
            'updated_at' => $one['updated_at'] ?? null,
            ...$short ? [] : [
                'body' => $one['body'] ?? '',
                'id' => $one['id'] ?? null,
                'created_at' => $one['created_at'] ?? null,
                'closed_at' => $one['closed_at'] ?? null,
                'reason' => $one['state_reason'] ?? null,
            ],
        ];
    }

    /**
     * @param  array<mixed>  $one
     * @return array<string, mixed>
     */
    private static function pull(array $one, bool $short = false): array
    {
        return [
            'number' => $one['number'] ?? null,
            'title' => $one['title'] ?? null,
            'state' => $one['state'] ?? null,
            'draft' => (bool) ($one['draft'] ?? false),
            'merged' => (bool) ($one['merged'] ?? ($one['merged_at'] ?? null) !== null),
            'head' => $one['head']['ref'] ?? null,
            'base' => $one['base']['ref'] ?? null,
            'author' => $one['user']['login'] ?? null,
            'url' => $one['html_url'] ?? null,
            'updated_at' => $one['updated_at'] ?? null,
            ...$short ? [] : [
                'body' => $one['body'] ?? '',
                'sha' => $one['head']['sha'] ?? null,
                'mergeable' => $one['mergeable'] ?? null,
                'additions' => $one['additions'] ?? null,
                'deletions' => $one['deletions'] ?? null,
                'changed_files' => $one['changed_files'] ?? null,
                'labels' => self::names($one['labels'] ?? []),
                'reviewers' => self::names($one['requested_reviewers'] ?? [], 'login'),
            ],
        ];
    }

    /**
     * A comment as a step gives it out.
     *
     * @param  array<mixed>  $one
     * @return array<string, mixed>
     */
    private static function said(array $one): array
    {
        return [
            'id' => $one['id'] ?? null,
            'body' => $one['body'] ?? '',
            'author' => $one['user']['login'] ?? null,
            'at' => $one['created_at'] ?? null,
            'url' => $one['html_url'] ?? null,
        ];
    }

    /**
     * @param  array<mixed>  $one
     * @return array<string, mixed>
     */
    private static function release(array $one): array
    {
        return [
            'id' => $one['id'] ?? null,
            'tag' => $one['tag_name'] ?? null,
            'name' => $one['name'] ?? null,
            'body' => $one['body'] ?? '',
            'draft' => (bool) ($one['draft'] ?? false),
            'prerelease' => (bool) ($one['prerelease'] ?? false),
            'at' => $one['published_at'] ?? $one['created_at'] ?? null,
            'url' => $one['html_url'] ?? null,
        ];
    }

    /**
     * @return list<string>
     */
    private static function names(mixed $things, string $by = 'name'): array
    {
        return array_values(array_filter(array_map(fn ($one) => is_array($one) ? ($one[$by] ?? null) : (is_string($one) ? $one : null), (array) $things), is_string(...)));
    }

    // --- Asking ---

    /**
     * The connection the step names.
     */
    private static function connection(StepContext $step): Connection
    {
        $github = References::connection($step->owner(), (string) $step->setting('connection'), [Services::GITHUB])
            ?? throw new StepFailed('Its connection is gone, or is not this workflow\'s.');

        return filled($github->secret('token')) ? $github : throw new StepFailed("\"{$github->name}\" has no token: give it one on the connection.");
    }

    /**
     * Where the step works: the repository it names, or the connection's own.
     */
    private static function repo(StepContext $step, Connection $github): string
    {
        $repo = trim($step->text('repo')) ?: trim((string) $github->setting('repo'));
        // A repository's address is taken as its name
        $repo = trim((string) preg_replace('#^(https?://)?(www\.)?github\.com/|\.git$#i', '', $repo), '/');

        if (preg_match('#^[A-Za-z0-9-]+/[A-Za-z0-9._-]+$#', $repo) !== 1) {
            throw new StepFailed($repo === '' ? 'There is no repository: name one as owner/name, or give the connection one.' : "\"{$repo}\" is not a repository: name it as owner/name.");
        }

        return "/repos/{$repo}";
    }

    private static function number(StepContext $step): int
    {
        $number = (int) ltrim(trim($step->text('number')), '#');

        return $number > 0 ? $number : throw new StepFailed('There is no number: the issue\'s or pull request\'s, like 12.');
    }

    /**
     * A path into a repository, each part of it made safe for an address.
     */
    private static function path(string $path): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', $path)));
    }

    /**
     * A time as GitHub reads one.
     */
    private static function when(string $since): string
    {
        try {
            return now()->parse($since)->utc()->format('Y-m-d\TH:i:s\Z');
        } catch (\Throwable) {
            throw new StepFailed("\"{$since}\" is not a time it can read, like 2026-10-01 or \"7 days ago\".");
        }
    }

    private static function http(Connection $github): PendingRequest
    {
        return Http::timeout(30)
            ->withToken((string) $github->secret('token'))
            ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'])
            ->withUserAgent('Zyrenn');
    }

    /**
     * A call that only asks -- or changes something in a way that, made
     * twice, comes to the same.
     *
     * @param  array<string, mixed>  $data
     * @return array<mixed>
     *
     * @throws StepFailed
     */
    private static function ask(StepContext $step, Connection $github, string $method, string $path, array $data = []): array
    {
        return self::answered(self::called($github, $method, $path, $data));
    }

    /**
     * What is at a path, or null when nothing is.
     *
     * @param  array<string, mixed>  $query
     * @return array<mixed>|null
     */
    private static function found(Connection $github, string $path, array $query): ?array
    {
        $response = self::called($github, 'GET', $path, $query);

        return $response->status() === 404 ? null : self::answered($response);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function called(Connection $github, string $method, string $path, array $data): Response
    {
        try {
            return self::http($github)->send($method, self::API.$path, $method === 'GET' ? ['query' => $data] : ['json' => $data]);
        } catch (ConnectionException $e) {
            throw preg_match('/cURL error (6|7|35)\b/', $e->getMessage()) === 1
                ? new StepFailed('Could not reach GitHub.', retryable: true)
                : new StepFailed('GitHub did not answer in time.', retryable: $method === 'GET');
        }
    }

    /**
     * A call that adds something, sent once: recorded before it goes, and
     * not made again when it broke off after it may have gone out.
     *
     * @param  array<string, mixed>  $data
     * @return array<mixed>
     */
    private static function send(StepContext $step, Connection $github, string $method, string $path, array $data): array
    {
        $response = $step->deliver(Services::GITHUB, "{$method} {$path}", null, function () use ($github, $method, $path, $data) {
            try {
                return self::http($github)->send($method, self::API.$path, ['json' => $data]);
            } catch (ConnectionException $e) {
                throw StepFailed::unreached($e, 'GitHub');
            }
        });

        return self::answered($response);
    }

    /**
     * GitHub's answer, or why there is none worth having.
     *
     * @return array<mixed>
     *
     * @throws StepFailed
     */
    private static function answered(Response $response): array
    {
        if ($response->successful()) {
            $json = $response->json();

            // Some calls answer with nothing at all: that it was taken
            return is_array($json) ? $json : ($response->body() === '' ? [] : ['text' => $response->body()]);
        }

        $said = (string) ($response->json('message') ?? "nothing it could be read by ({$response->status()})");
        $why = implode('; ', array_filter(array_map(
            fn ($error) => is_array($error) ? (string) ($error['message'] ?? trim(($error['field'] ?? '').' '.($error['code'] ?? ''))) : (is_string($error) ? $error : ''),
            (array) ($response->json('errors') ?? []),
        )));
        $said = mb_strimwidth($said.($why === '' ? '' : ": {$why}"), 0, 300, '…');
        $left = $response->header('X-RateLimit-Remaining');
        $reset = (int) $response->header('X-RateLimit-Reset');
        $after = $response->header('Retry-After');

        throw match (true) {
            $response->status() === 401 => new StepFailed('GitHub refused the token: check it on the connection, and that it has not run out.'),
            $response->status() === 429, in_array($response->status(), [403, 429], true) && ($left === '0' || is_numeric($after)) => new StepFailed(
                'GitHub asked to slow down: the token has made too many calls for now.',
                retryable: true,
                retryAfter: is_numeric($after) ? (int) $after : max(1, min(3600, $reset - time())),
            ),
            $response->status() === 403 => new StepFailed("GitHub would not let the token do that: {$said} A fine-grained token needs this repository, and the permission for it."),
            $response->status() === 404 => new StepFailed("GitHub found nothing there, or the token cannot see it: {$said}"),
            default => new StepFailed("GitHub said: {$said}", retryable: $response->serverError()),
        };
    }
}
