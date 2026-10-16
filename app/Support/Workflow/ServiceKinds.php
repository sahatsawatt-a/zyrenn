<?php

namespace App\Support\Workflow;

use App\Support\Workflow\Steps\PromptxRun;

/**
 * The kinds of step that are one call to a service each -- SerpApi, Tavily,
 * PromptX, Telegram, LINE, WhatsApp, LinkedIn, Facebook, Instagram, Threads -- beside the ones
 * NodeTypes lists itself: every call a service offers is a step of its own,
 * in that service's group. Run by that service's ...Call class.
 *
 * @phpstan-import-type Setting from NodeTypes
 * @phpstan-import-type NodeType from NodeTypes
 */
final class ServiceKinds
{
    /** What a step that may have sent something does when it can't tell. */
    public const UNSURE = [
        'name' => 'unsure',
        'label' => 'If it may not have gone out',
        'type' => 'choice',
        'required' => true,
        'default' => 'ask',
        'options' => ['ask' => 'Ask me', 'retry' => 'Send it again', 'never' => 'Don\'t send it again'],
    ];

    /**
     * @return list<NodeType>
     */
    public static function all(): array
    {
        return [...self::serpapi(), ...self::tavily(), ...self::github(), ...self::stripe(), ...self::promptx(), ...self::promptxProject(), ...self::telegram(), ...self::line(), ...self::whatsapp(), ...self::channel(), ...self::linkedin(), ...self::facebook(), ...self::instagram(), ...self::threads()];
    }

    // --- SerpApi ---

    /**
     * @return list<NodeType>
     */
    private static function serpapi(): array
    {
        $key = ['name' => 'connection', 'label' => 'Key', 'type' => 'connection', 'required' => true, 'services' => ['serpapi']];
        $for = ['name' => 'q', 'label' => 'Search for', 'type' => 'template', 'required' => true, 'placeholder' => '{{ trigger.text }}'];
        $country = ['name' => 'gl', 'label' => 'Country', 'type' => 'template', 'required' => false, 'placeholder' => 'th', 'help' => 'Two letters: th, us, jp.'];
        $language = ['name' => 'hl', 'label' => 'Language', 'type' => 'template', 'required' => false, 'placeholder' => 'th', 'help' => 'Two letters: th, en, ja.'];
        $most = ['name' => 'count', 'label' => 'At most', 'type' => 'number', 'required' => false, 'default' => 20, 'help' => 'At most 100.'];
        $page = ['name' => 'page', 'label' => 'Page', 'type' => 'number', 'required' => false, 'default' => 1];
        $more = ['name' => 'more', 'label' => 'More', 'type' => 'fields', 'required' => false, 'default' => [], 'help' => 'Anything else the engine takes, named as its page on serpapi.com names it: safe active, no_cache true.'];
        $costs = ' A search counts as one against the key\'s month; the same one asked again within the hour is free.';

        return [
            self::kind('serpapi.google', Steps\SerpApiCall::class, 'Search Google', 'search', 'Searches Google, through SerpApi. Gives out results -- each with its title, link, snippet, source and date -- with Google\'s own answer when it gives one, what it knows of the thing searched for as knowledge, questions people also ask, related searches, and text: the results as words for a model to read.'.$costs, [
                $key,
                $for,
                ['name' => 'location', 'label' => 'From', 'type' => 'template', 'required' => false, 'placeholder' => 'Bangkok, Thailand', 'help' => 'Where the search is made from: a place as "SerpApi: find a place" names it. Left empty, wherever SerpApi asks from.'],
                $country,
                $language,
                ['name' => 'period', 'label' => 'From the last', 'type' => 'choice', 'required' => true, 'default' => 'any', 'options' => ['any' => 'Any time', 'hour' => 'Hour', 'day' => 'Day', 'week' => 'Week', 'month' => 'Month', 'year' => 'Year']],
                [...$page, 'help' => 'Ten results to a page.'],
                $more,
            ]),
            self::kind('serpapi.news', Steps\SerpApiCall::class, 'Search Google News', 'newspaper', 'Searches Google News, through SerpApi. Gives out results -- each with its title, link, source, date and thumbnail -- and text: them as words for a model to read.'.$costs, [
                $key,
                [...$for, 'required' => false, 'help' => 'Left empty, the top stories. As Google News reads it: site:bbc.com, when:7d.'],
                $country,
                $language,
                ['name' => 'sort', 'label' => 'First', 'type' => 'choice', 'required' => true, 'default' => 'relevance', 'options' => ['relevance' => 'What fits best', 'date' => 'The newest']],
                $most,
                [...$more, 'help' => 'Anything else the engine takes, named as its page on serpapi.com names it: a topic_token, a publication_token.'],
            ]),
            self::kind('serpapi.images', Steps\SerpApiCall::class, 'Search Google Images', 'image', 'Searches Google Images, through SerpApi. Gives out results: each with its title, the picture\'s own address as image, its width and height, a thumbnail, and the link and source of the page it is on. "Save to Drive" keeps one.'.$costs, [
                $key,
                $for,
                $country,
                $language,
                ['name' => 'kind', 'label' => 'Kind', 'type' => 'choice', 'required' => true, 'default' => 'any', 'options' => ['any' => 'Any', 'photo' => 'Photos', 'face' => 'Faces', 'clipart' => 'Clip art', 'lineart' => 'Line drawings', 'animated' => 'Animated']],
                $most,
                [...$page, 'help' => 'A hundred pictures to a page.'],
                [...$more, 'help' => 'Anything else the engine takes, named as its page on serpapi.com names it: imgsz l, licenses fc, safe active.'],
            ]),
            self::kind('serpapi.maps', Steps\SerpApiCall::class, 'Search Google Maps', 'map-pinned', 'Searches Google Maps for places, through SerpApi. Gives out results: each with its title, address, lat and lng, rating, reviews, type, price, phone, website and whether it is open -- a result as it is can go with a chat message as its place.'.$costs, [
                $key,
                [...$for, 'placeholder' => 'coffee near Ari'],
                ['name' => 'around', 'label' => 'Around', 'type' => 'template', 'required' => false, 'placeholder' => '{{ trigger.location }}', 'help' => 'The place searched around: its latitude and longitude, like 13.7563, 100.5018, or what a message that sent a place gives. Left empty, Google goes by the words.'],
                ['name' => 'zoom', 'label' => 'How close', 'type' => 'number', 'required' => false, 'default' => 14, 'help' => 'From 3, the whole world, to 21, one street. Only with a place to search around.', 'when' => ['around' => true]],
                $language,
                [...$page, 'help' => 'Twenty places to a page. A later page needs a place to search around.'],
                [...$more, 'help' => 'Anything else the engine takes, named as its page on serpapi.com names it: open_state now, min_rating 4.'],
            ]),
            self::kind('serpapi.youtube', Steps\SerpApiCall::class, 'Search YouTube', 'square-play', 'Searches YouTube, through SerpApi. Gives out results -- each with its title, link, id, channel, when it was published, views, length, description and thumbnail -- and text: them as words for a model to read.'.$costs, [
                $key,
                $for,
                $country,
                $language,
                $most,
                [...$more, 'help' => 'Anything else the engine takes, named as its page on serpapi.com names it: sp, YouTube\'s own mark for a filter or a later page.'],
            ]),
            self::kind('serpapi.search', Steps\SerpApiCall::class, 'SerpApi: any engine', 'text-search', 'Asks any other engine SerpApi has -- Google Shopping, Scholar, Trends, Flights, Hotels, Bing, DuckDuckGo, Amazon and the rest -- and gives out its answer whole, as body: each engine has its own things to ask and its own shape of answer, set out on its page on serpapi.com.'.$costs, [
                $key,
                ['name' => 'engine', 'label' => 'Engine', 'type' => 'template', 'required' => true, 'placeholder' => 'google_shopping', 'help' => 'Its name as SerpApi writes it: google_shopping, google_scholar, google_trends, bing, duckduckgo, amazon.'],
                ['name' => 'params', 'label' => 'What it is asked', 'type' => 'fields', 'required' => false, 'default' => [], 'help' => 'Each named as the engine\'s page names it: q running shoes, gl th. A step gives out at most 256 KB: json_restrictor keeps only the parts named, like shopping_results[].{title,price,link}.'],
            ]),
            self::kind('serpapi.locations', Steps\SerpApiCall::class, 'SerpApi: find a place', 'locate', 'Finds the places a search can be made from, by part of a name. Gives out places -- each with its name as a search takes it, country, kind, lat and lng -- and name, the first of them. Free, and asks with no key.', [
                ['name' => 'q', 'label' => 'Place', 'type' => 'template', 'required' => true, 'placeholder' => 'Chiang Mai'],
                ['name' => 'count', 'label' => 'At most', 'type' => 'number', 'required' => false, 'default' => 5, 'help' => 'At most 10.'],
            ]),
            self::kind('serpapi.account', Steps\SerpApiCall::class, 'SerpApi: searches left', 'gauge', 'Asks SerpApi about the key\'s plan. Gives out plan, left, used and per_month -- searches this month -- with this_hour and per_hour, and when it renews. Free.', [$key]),
        ];
    }

    // --- Tavily ---

    /**
     * @return list<NodeType>
     */
    private static function tavily(): array
    {
        $key = ['name' => 'connection', 'label' => 'Key', 'type' => 'connection', 'required' => true, 'services' => ['tavily']];
        $depth = ['name' => 'depth', 'label' => 'Read', 'type' => 'choice', 'required' => true, 'default' => 'basic', 'options' => ['basic' => 'The page\'s words', 'advanced' => 'Tables and what is set into it too (slower, twice the credits)']];
        $format = ['name' => 'format', 'label' => 'As', 'type' => 'choice', 'required' => true, 'default' => 'markdown', 'options' => ['markdown' => 'Markdown', 'text' => 'Plain text']];
        $more = ['name' => 'more', 'label' => 'More', 'type' => 'fields', 'required' => false, 'default' => [], 'help' => 'Anything else the call takes, named as Tavily\'s docs name it. A value written as yes or no, a number or a list is sent as one: include_images true.'];
        $long = ' The pages\' text is cut short where it is more than a step may give out, and cut says so.';
        $site = fn (int $limit, int $most) => [
            ['name' => 'url', 'label' => 'Site', 'type' => 'template', 'required' => true, 'placeholder' => 'https://docs.example.com'],
            ['name' => 'instructions', 'label' => 'Looking for', 'type' => 'template', 'required' => false, 'placeholder' => 'Pages about pricing', 'help' => 'In plain words, which pages matter. Costs twice the credits.'],
            ['name' => 'max_depth', 'label' => 'How many links deep', 'type' => 'number', 'required' => false, 'default' => 1, 'help' => 'From 1 to 5.'],
            ['name' => 'limit', 'label' => 'At most', 'type' => 'number', 'required' => false, 'default' => $limit, 'help' => "Pages, at most {$most}. It stops after two minutes with what it has."],
            ['name' => 'select_paths', 'label' => 'Only paths', 'type' => 'template', 'required' => false, 'placeholder' => '/docs/.*', 'help' => 'Patterns a page\'s path has to match, one on a line.'],
            ['name' => 'exclude_paths', 'label' => 'Not paths', 'type' => 'template', 'required' => false, 'placeholder' => '/blog/.*', 'help' => 'Patterns a page\'s path must not match, one on a line.'],
            ['name' => 'external', 'label' => 'Other sites it links to', 'type' => 'choice', 'required' => true, 'default' => 'no', 'options' => ['no' => 'Left out', 'yes' => 'Kept']],
        ];

        return [
            self::kind('tavily.search', Steps\TavilyCall::class, 'Search with Tavily', 'search', 'Searches the web with Tavily, which reads what it finds and gives the part that answers. Gives out results -- each with its title, url, content and score -- an answer written from them when asked for one, and text: the results as words for a model to read. One credit, two when thorough.', [
                $key,
                ['name' => 'query', 'label' => 'Search for', 'type' => 'template', 'required' => true, 'placeholder' => '{{ trigger.text }}'],
                ['name' => 'topic', 'label' => 'Among', 'type' => 'choice', 'required' => true, 'default' => 'general', 'options' => ['general' => 'The whole web', 'news' => 'The news', 'finance' => 'Finance']],
                ['name' => 'depth', 'label' => 'How', 'type' => 'choice', 'required' => true, 'default' => 'basic', 'options' => ['basic' => 'The usual way', 'advanced' => 'Thorough: the parts of each page that fit best (two credits)', 'fast' => 'Fast', 'ultra-fast' => 'Fastest: a line on each page']],
                ['name' => 'count', 'label' => 'At most', 'type' => 'number', 'required' => false, 'default' => 5, 'help' => 'At most 20.'],
                ['name' => 'period', 'label' => 'From the last', 'type' => 'choice', 'required' => true, 'default' => 'any', 'options' => ['any' => 'Any time', 'day' => 'Day', 'week' => 'Week', 'month' => 'Month', 'year' => 'Year']],
                ['name' => 'answer', 'label' => 'Write an answer', 'type' => 'choice', 'required' => true, 'default' => 'no', 'options' => ['no' => 'No', 'basic' => 'A short one', 'advanced' => 'A fuller one']],
                ['name' => 'raw', 'label' => 'Each page whole', 'type' => 'choice', 'required' => true, 'default' => 'no', 'options' => ['no' => 'No, only the part that answers', 'markdown' => 'As Markdown, in each result\'s text', 'text' => 'As plain text, in each result\'s text'], 'help' => 'Cut short where it is more than a step may give out; cut says so.'],
                ['name' => 'only', 'label' => 'Only from', 'type' => 'template', 'required' => false, 'placeholder' => 'wikipedia.org, bbc.com', 'help' => 'Sites to keep to, with commas between or one on a line.'],
                ['name' => 'not', 'label' => 'Not from', 'type' => 'template', 'required' => false, 'placeholder' => 'pinterest.com', 'help' => 'Sites to leave out.'],
                [...$more, 'help' => 'Anything else the call takes, named as Tavily\'s docs name it. A value written as yes or no, a number or a list is sent as one: country thailand, include_images true, start_date 2026-01-01.'],
            ]),
            self::kind('tavily.extract', Steps\TavilyCall::class, 'Tavily: read pages', 'book-open-text', 'Reads pages whole, by their addresses: up to 20 at once. Gives out text -- one page\'s words, or every page\'s each under its address -- results, each with its url and text, and failed, the ones that could not be read and why. A credit for every five pages.'.$long, [
                $key,
                ['name' => 'urls', 'label' => 'Pages', 'type' => 'template', 'required' => true, 'placeholder' => '{{ item(node("search").results, 1).url }}', 'help' => 'Their addresses: one, several with commas between or one on a line, or a list.'],
                $depth,
                $format,
                ['name' => 'about', 'label' => 'Only what bears on', 'type' => 'template', 'required' => false, 'placeholder' => '{{ trigger.text }}', 'help' => 'Left empty, the whole page. With words here, the parts of each page that fit them best.'],
                $more,
            ]),
            self::kind('tavily.crawl', Steps\TavilyCall::class, 'Tavily: crawl a site', 'network', 'Follows a site\'s links from a page and reads what it finds. Gives out results -- each page with its url and text -- count, and text: every page\'s words, each under its address. Credits by the page.'.$long, [
                $key,
                ...$site(20, 100),
                $depth,
                $format,
                $more,
            ]),
            self::kind('tavily.map', Steps\TavilyCall::class, 'Tavily: list a site\'s pages', 'list-tree', 'Follows a site\'s links from a page and lists what it finds, without reading it. Gives out urls and count. A credit for every ten pages.', [
                $key,
                ...$site(50, 500),
                $more,
            ]),
            self::kind('tavily.usage', Steps\TavilyCall::class, 'Tavily: credits used', 'gauge', 'Asks Tavily what the key has used. Gives out used and limit for the key, left, and the plan with plan_used and plan_limit. Free.', [$key]),
        ];
    }

    // --- GitHub ---

    /**
     * @return list<NodeType>
     */
    private static function github(): array
    {
        $as = ['name' => 'connection', 'label' => 'GitHub', 'type' => 'connection', 'required' => true, 'services' => ['github']];
        $repo = ['name' => 'repo', 'label' => 'Repository', 'type' => 'template', 'required' => false, 'placeholder' => 'owner/name', 'help' => 'Empty, the connection\'s usual one.'];
        $number = ['name' => 'number', 'label' => 'Number', 'type' => 'template', 'required' => true, 'placeholder' => '{{ trigger.issue.number }}'];
        $title = ['name' => 'title', 'label' => 'Title', 'type' => 'template', 'required' => true];
        $body = ['name' => 'body', 'label' => 'Words', 'type' => 'template', 'required' => false, 'help' => 'Markdown, as GitHub writes it.'];
        $labels = ['name' => 'labels', 'label' => 'Labels', 'type' => 'template', 'required' => false, 'placeholder' => 'bug, from-zyrenn', 'help' => 'Split by commas or lines.'];
        $people = ['name' => 'assignees', 'label' => 'Given to', 'type' => 'template', 'required' => false, 'placeholder' => 'octocat', 'help' => 'GitHub logins, split by commas.'];
        $state = ['name' => 'state', 'label' => 'Which', 'type' => 'choice', 'required' => true, 'default' => 'open', 'options' => ['open' => 'Open', 'closed' => 'Closed', 'all' => 'Either']];
        $count = ['name' => 'count', 'label' => 'At most', 'type' => 'number', 'required' => false, 'default' => 30, 'help' => 'At most 100.'];
        $since = ['name' => 'since', 'label' => 'Changed since', 'type' => 'template', 'required' => false, 'placeholder' => '7 days ago', 'help' => 'A date, or a time like "7 days ago". Empty, any time.'];
        $yes = fn (string $name, string $label, ?string $help = null) => ['name' => $name, 'label' => $label, 'type' => 'choice', 'required' => true, 'default' => 'no', 'options' => ['no' => 'No', 'yes' => 'Yes'], 'help' => $help];
        $kind = fn (string $type, string $label, string $icon, string $description, array $settings, bool $sends = false) => self::kind($type, Steps\GithubCall::class, $label, $icon, $description, [$as, ...array_values($settings)], $sends);

        return [
            // Issues
            $kind('github.issue_create', 'GitHub: open an issue', 'circle-dot', 'Opens an issue. Gives out its number, url, title and state.', [$repo, $title, $body, $labels, $people], sends: true),
            $kind('github.issue_update', 'GitHub: change an issue', 'pencil', 'Changes an issue or pull request: only what is given changes. Labels or people named replace the ones it had. Gives out the issue as it is now.', [
                $repo,
                $number,
                [...$title, 'required' => false, 'help' => 'Empty, as it was.'],
                [...$body, 'help' => 'Empty, as it was.'],
                ['name' => 'state', 'label' => 'Open or closed', 'type' => 'choice', 'required' => true, 'default' => 'keep', 'options' => ['keep' => 'As it is', 'open' => 'Open', 'closed' => 'Closed']],
                ['name' => 'reason', 'label' => 'Closed as', 'type' => 'choice', 'required' => true, 'default' => 'completed', 'options' => ['completed' => 'Done', 'not_planned' => 'Not planned'], 'when' => ['state' => 'closed']],
                [...$labels, 'help' => 'Replace its labels. Empty, as they were.'],
                [...$people, 'help' => 'Replace who has it. Empty, as it was.'],
            ]),
            $kind('github.issue', 'GitHub: read an issue', 'file-text', 'Reads an issue or pull request by its number: its title, words, state, labels, who has it, and pull, true for a pull request.', [$repo, $number]),
            $kind('github.issues', 'GitHub: find issues', 'list', 'Lists a repository\'s issues, the last changed first. Gives out issues -- each with its number, title, state, labels, who has it and url -- and count.', [
                $repo,
                $state,
                ['name' => 'kind', 'label' => 'Of', 'type' => 'choice', 'required' => true, 'default' => 'issues', 'options' => ['issues' => 'Issues', 'pulls' => 'Pull requests', 'both' => 'Both']],
                [...$labels, 'help' => 'Only those with all of these.'],
                ['name' => 'assignee', 'label' => 'Given to', 'type' => 'template', 'required' => false, 'placeholder' => 'octocat', 'help' => 'A login; none for those nobody has.'],
                $since,
                $count,
            ]),
            $kind('github.comment', 'GitHub: comment', 'message-square', 'Writes a comment on an issue or pull request. Gives out its id and url.', [$repo, $number, [...$body, 'required' => true]], sends: true),
            $kind('github.comments', 'GitHub: read comments', 'messages-square', 'Reads the comments on an issue or pull request, the first first. Gives out comments -- each with its id, words, author and url -- and count.', [$repo, $number, $count]),
            $kind('github.labels_add', 'GitHub: add labels', 'tag', 'Puts labels on an issue or pull request, beside the ones it has; a label the repository lacks is made. Gives out all its labels now.', [$repo, $number, [...$labels, 'required' => true, 'help' => 'Split by commas or lines.']]),

            // Pull requests
            $kind('github.pull_create', 'GitHub: open a pull request', 'git-pull-request', 'Asks to bring a branch into another. Gives out its number, url and state.', [
                $repo,
                $title,
                ['name' => 'head', 'label' => 'Bring in', 'type' => 'template', 'required' => true, 'placeholder' => 'feature/new-step', 'help' => 'The branch with the changes.'],
                ['name' => 'base', 'label' => 'Into', 'type' => 'template', 'required' => true, 'placeholder' => 'main'],
                $body,
                $yes('draft', 'As a draft'),
            ], sends: true),
            $kind('github.pull', 'GitHub: read a pull request', 'git-pull-request', 'Reads a pull request by its number: title, words, state, whether it is merged or can be, its branches, and how much it changes.', [$repo, $number]),
            $kind('github.pulls', 'GitHub: find pull requests', 'list', 'Lists a repository\'s pull requests, the last changed first. Gives out pulls and count.', [
                $repo,
                $state,
                ['name' => 'base', 'label' => 'Into', 'type' => 'template', 'required' => false, 'placeholder' => 'main', 'help' => 'Only those into this branch.'],
                $count,
            ]),
            $kind('github.merge', 'GitHub: merge a pull request', 'git-merge', 'Merges a pull request. Gives out merged and the commit it made.', [
                $repo,
                $number,
                ['name' => 'method', 'label' => 'How', 'type' => 'choice', 'required' => true, 'default' => 'merge', 'options' => ['merge' => 'A merge commit', 'squash' => 'Squashed into one', 'rebase' => 'Rebased']],
                ['name' => 'title', 'label' => 'Commit title', 'type' => 'template', 'required' => false, 'help' => 'Empty, GitHub\'s own.'],
            ], sends: true),

            // Code
            $kind('github.file', 'GitHub: read a file', 'file-code', 'Reads a file of a repository: text (cut at 200 KB, and cut says so), sha, size, url. A folder gives out items instead: what is in it.', [
                $repo,
                ['name' => 'path', 'label' => 'Path', 'type' => 'template', 'required' => true, 'placeholder' => 'docs/CHANGELOG.md'],
                ['name' => 'ref', 'label' => 'On', 'type' => 'template', 'required' => false, 'placeholder' => 'main', 'help' => 'A branch, tag or commit. Empty, the usual branch.'],
            ]),
            $kind('github.file_save', 'GitHub: write a file', 'file-pen', 'Writes a file as a commit: made, or changed from what it was. Gives out the commit and its url, and made, true for a new file.', [
                $repo,
                ['name' => 'path', 'label' => 'Path', 'type' => 'template', 'required' => true, 'placeholder' => 'data/today.json'],
                ['name' => 'content', 'label' => 'What it holds', 'type' => 'template', 'required' => true],
                ['name' => 'message', 'label' => 'Commit message', 'type' => 'template', 'required' => false, 'placeholder' => 'Update today.json'],
                ['name' => 'branch', 'label' => 'On', 'type' => 'template', 'required' => false, 'placeholder' => 'main', 'help' => 'Empty, the usual branch.'],
            ], sends: true),
            $kind('github.commits', 'GitHub: list commits', 'git-commit-horizontal', 'Lists commits, the newest first: each with its sha, message, author, time and url.', [
                $repo,
                ['name' => 'branch', 'label' => 'On', 'type' => 'template', 'required' => false, 'placeholder' => 'main'],
                ['name' => 'path', 'label' => 'Touching', 'type' => 'template', 'required' => false, 'placeholder' => 'src/', 'help' => 'Only those that change this file or folder.'],
                ['name' => 'author', 'label' => 'By', 'type' => 'template', 'required' => false, 'placeholder' => 'octocat'],
                [...$since, 'label' => 'Since'],
                $count,
            ]),
            $kind('github.branch_create', 'GitHub: make a branch', 'git-branch', 'Makes a branch where another is now. Gives out its name and the commit it starts at.', [
                $repo,
                ['name' => 'name', 'label' => 'Name', 'type' => 'template', 'required' => true, 'placeholder' => 'fix/{{ trigger.issue.number }}'],
                ['name' => 'from', 'label' => 'From', 'type' => 'template', 'required' => false, 'placeholder' => 'main', 'help' => 'Empty, the usual branch.'],
            ]),

            // Actions and releases
            $kind('github.dispatch', 'GitHub: run an Action', 'play', 'Starts an Action that can be run by hand (on: workflow_dispatch). GitHub says only that it was taken: "GitHub: Actions runs" finds how it went.', [
                $repo,
                ['name' => 'workflow', 'label' => 'Action', 'type' => 'template', 'required' => true, 'placeholder' => 'deploy.yml', 'help' => 'Its file name in .github/workflows, or its id.'],
                ['name' => 'ref', 'label' => 'On', 'type' => 'template', 'required' => false, 'placeholder' => 'main', 'help' => 'Empty, the usual branch.'],
                ['name' => 'inputs', 'label' => 'Inputs', 'type' => 'fields', 'required' => false, 'default' => [], 'help' => 'As the Action names them under workflow_dispatch.inputs.'],
            ], sends: true),
            $kind('github.runs', 'GitHub: Actions runs', 'activity', 'Lists Actions runs, the newest first: each with its status, conclusion (success, failure...), branch and url.', [
                $repo,
                ['name' => 'workflow', 'label' => 'Of the Action', 'type' => 'template', 'required' => false, 'placeholder' => 'deploy.yml', 'help' => 'Empty, every Action\'s.'],
                ['name' => 'branch', 'label' => 'On', 'type' => 'template', 'required' => false, 'placeholder' => 'main'],
                ['name' => 'status', 'label' => 'Only', 'type' => 'choice', 'required' => true, 'default' => 'any', 'options' => ['any' => 'Any', 'in_progress' => 'Running', 'queued' => 'Waiting', 'completed' => 'Finished', 'success' => 'Succeeded', 'failure' => 'Failed', 'cancelled' => 'Cancelled']],
                [...$count, 'default' => 10],
            ]),
            $kind('github.release_create', 'GitHub: publish a release', 'package', 'Publishes a release at a tag, made if it is not there yet. Gives out its url.', [
                $repo,
                ['name' => 'tag', 'label' => 'Tag', 'type' => 'template', 'required' => true, 'placeholder' => 'v1.2.0'],
                ['name' => 'name', 'label' => 'Name', 'type' => 'template', 'required' => false, 'help' => 'Empty, the tag.'],
                $body,
                ['name' => 'target', 'label' => 'From', 'type' => 'template', 'required' => false, 'placeholder' => 'main', 'help' => 'Where a new tag is made: a branch or commit. Empty, the usual branch.'],
                $yes('notes', 'Write its notes', 'GitHub writes what changed since the last release.'),
                $yes('draft', 'As a draft'),
                $yes('prerelease', 'As a pre-release'),
            ], sends: true),
            $kind('github.release_latest', 'GitHub: latest release', 'package', 'Reads the newest release that is not a draft or pre-release: its tag, name, notes and url.', [$repo]),

            // Anything else
            $kind('github.request', 'GitHub: any call', 'globe', 'Any call of GitHub\'s REST API, as docs.github.com writes it, with the connection\'s token. Gives out answer: what GitHub answered.', [
                ['name' => 'method', 'label' => 'Method', 'type' => 'choice', 'required' => true, 'default' => 'GET', 'options' => ['GET' => 'GET', 'POST' => 'POST', 'PATCH' => 'PATCH', 'PUT' => 'PUT', 'DELETE' => 'DELETE']],
                ['name' => 'path', 'label' => 'Path', 'type' => 'template', 'required' => true, 'placeholder' => '/repos/owner/name/topics'],
                ['name' => 'query', 'label' => 'Query', 'type' => 'fields', 'required' => false, 'default' => []],
                ['name' => 'body', 'label' => 'Body', 'type' => 'template', 'required' => false, 'placeholder' => '{"names": ["zyrenn"]}', 'help' => 'JSON. Not for GET.', 'when' => ['method' => ['POST', 'PATCH', 'PUT', 'DELETE']]],
            ], sends: true),
            $kind('github.me', 'GitHub: who the token is', 'user', 'Who the connection acts as: login, name, url.', []),
        ];
    }

    // --- Stripe ---

    /**
     * @return list<NodeType>
     */
    private static function stripe(): array
    {
        $as = ['name' => 'connection', 'label' => 'Stripe', 'type' => 'connection', 'required' => true, 'services' => ['stripe']];
        $amount = ['name' => 'amount', 'label' => 'Amount', 'type' => 'template', 'required' => true, 'placeholder' => '12.50', 'help' => 'As people write it: 12.50, or 1200 for yen.'];
        $currency = ['name' => 'currency', 'label' => 'Currency', 'type' => 'template', 'required' => false, 'placeholder' => 'thb', 'help' => 'Three letters: thb, usd, jpy. Empty, thb.'];
        $name = ['name' => 'name', 'label' => 'What it is for', 'type' => 'template', 'required' => true, 'placeholder' => 'Khao soi for two'];
        $price = ['name' => 'price', 'label' => 'Or a price in Stripe', 'type' => 'template', 'required' => false, 'placeholder' => 'price_…', 'help' => 'Its id; given, the amount, currency and name are not needed.'];
        $quantity = ['name' => 'quantity', 'label' => 'How many', 'type' => 'number', 'required' => false, 'default' => 1];
        $metadata = ['name' => 'metadata', 'label' => 'Metadata', 'type' => 'fields', 'required' => false, 'default' => [], 'help' => 'Your own values kept with it, like order 1234; they come back with what Stripe sends.'];
        $customer = ['name' => 'customer', 'label' => 'Customer', 'type' => 'template', 'required' => true, 'placeholder' => 'cus_…'];
        $count = ['name' => 'count', 'label' => 'At most', 'type' => 'number', 'required' => false, 'default' => 20, 'help' => 'At most 100.'];
        $person = [
            ['name' => 'email', 'label' => 'Email', 'type' => 'template', 'required' => false],
            ['name' => 'name', 'label' => 'Name', 'type' => 'template', 'required' => false],
            ['name' => 'phone', 'label' => 'Phone', 'type' => 'template', 'required' => false],
            ['name' => 'description', 'label' => 'Note', 'type' => 'template', 'required' => false],
            $metadata,
        ];
        $kind = fn (string $type, string $label, string $icon, string $description, array $settings) => self::kind($type, Steps\StripeCall::class, $label, $icon, $description, [$as, ...array_values($settings)]);
        $once = ' Made once, however often the step is tried.';

        return [
            // Payments
            $kind('stripe.payment_link_create', 'Stripe: make a payment link', 'link', 'Makes a link anyone can pay by -- to send in a chat or an email. Gives out url, and its id.'.$once, [$amount, $currency, $name, $price, $quantity, ['name' => 'after', 'label' => 'Then go to', 'type' => 'template', 'required' => false, 'placeholder' => 'https://example.com/thanks', 'help' => 'A page the payer is sent to once paid. Empty, Stripe\'s own.'], $metadata]),
            $kind('stripe.checkout_create', 'Stripe: make a checkout page', 'credit-card', 'Makes a checkout page for one payment, or a subscription at a price that recurs, for one payer. Gives out url, id and expires_at.'.$once, [
                ['name' => 'mode', 'label' => 'For', 'type' => 'choice', 'required' => true, 'default' => 'payment', 'options' => ['payment' => 'One payment', 'subscription' => 'A subscription (a price that recurs)']],
                [...$amount, 'required' => false, 'help' => 'For one payment: as people write it, 12.50.', 'when' => ['mode' => 'payment']],
                [...$currency, 'when' => ['mode' => 'payment']],
                [...$name, 'required' => false, 'when' => ['mode' => 'payment']],
                $price,
                $quantity,
                ['name' => 'success_url', 'label' => 'Once paid, go to', 'type' => 'template', 'required' => true, 'placeholder' => 'https://example.com/thanks'],
                ['name' => 'cancel_url', 'label' => 'If they go back, go to', 'type' => 'template', 'required' => false],
                [...$customer, 'required' => false, 'help' => 'One already in Stripe; or their email below.'],
                ['name' => 'email', 'label' => 'Their email', 'type' => 'template', 'required' => false],
                ['name' => 'reference', 'label' => 'Your reference', 'type' => 'template', 'required' => false, 'placeholder' => '{{ trigger.chat.id }}', 'help' => 'Comes back as trigger.reference when it is paid.'],
                $metadata,
            ]),
            $kind('stripe.checkout', 'Stripe: read a checkout', 'file-text', 'Reads a checkout page: status, paid, amount, who paid and their email, the payment.', [['name' => 'session', 'label' => 'Checkout', 'type' => 'template', 'required' => true, 'placeholder' => 'cs_…']]),
            $kind('stripe.payment', 'Stripe: read a payment', 'file-text', 'Reads a payment: status, paid, amount, received, customer, metadata.', [['name' => 'payment', 'label' => 'Payment', 'type' => 'template', 'required' => true, 'placeholder' => 'pi_…']]),
            $kind('stripe.payments', 'Stripe: list payments', 'list', 'Lists payments, the newest first. Gives out payments and count.', [[...$customer, 'required' => false], ['name' => 'since', 'label' => 'Since', 'type' => 'template', 'required' => false, 'placeholder' => '7 days ago'], $count]),
            $kind('stripe.refund_create', 'Stripe: refund a payment', 'undo-2', 'Gives money back: all of a payment, or the amount said. Gives out the refund\'s id, status and amount.'.$once, [
                ['name' => 'payment', 'label' => 'Payment', 'type' => 'template', 'required' => true, 'placeholder' => 'pi_… or ch_…'],
                [...$amount, 'required' => false, 'help' => 'Empty, all of it.'],
                ['name' => 'reason', 'label' => 'Why', 'type' => 'choice', 'required' => true, 'default' => 'requested_by_customer', 'options' => ['requested_by_customer' => 'They asked', 'duplicate' => 'Paid twice', 'fraudulent' => 'Fraud', 'none' => 'Not said']],
                $metadata,
            ]),

            // Customers
            $kind('stripe.customer_create', 'Stripe: add a customer', 'user-plus', 'Adds a customer. Gives out its id, to bill or check out.'.$once, $person),
            $kind('stripe.customer_find', 'Stripe: find a customer', 'user-search', 'Finds customers by email. Gives out found, customer (the first) and customers.', [[...$person[0], 'required' => true], [...$count, 'default' => 10]]),
            $kind('stripe.customer', 'Stripe: read a customer', 'user', 'Reads a customer: email, name, phone, metadata.', [$customer]),
            $kind('stripe.customer_update', 'Stripe: change a customer', 'pencil', 'Changes a customer: only what is given changes.', [$customer, ...$person]),

            // Invoices
            $kind('stripe.invoice_create', 'Stripe: send an invoice', 'receipt', 'Makes an invoice for a customer, a line for each thing and its amount, due in the days said -- emailed by Stripe at once, or kept a draft. Gives out its number, status, amount and url, its page to pay on.'.$once, [
                $customer,
                ['name' => 'items', 'label' => 'Lines', 'type' => 'fields', 'required' => true, 'default' => [], 'labels' => true, 'help' => 'Each named for what it is, its value the amount: Design work 4500.'],
                $currency,
                ['name' => 'days', 'label' => 'Due in, days', 'type' => 'number', 'required' => false, 'default' => 30],
                ['name' => 'memo', 'label' => 'Note on it', 'type' => 'template', 'required' => false],
                ['name' => 'send', 'label' => 'Email it now', 'type' => 'choice', 'required' => true, 'default' => 'yes', 'options' => ['yes' => 'Yes', 'no' => 'No, keep it a draft']],
                $metadata,
            ]),
            $kind('stripe.invoices', 'Stripe: list invoices', 'list', 'Lists invoices, the newest first: each with its number, status, amount and url.', [
                [...$customer, 'required' => false],
                ['name' => 'status', 'label' => 'Only', 'type' => 'choice', 'required' => true, 'default' => 'any', 'options' => ['any' => 'Any', 'open' => 'Not paid yet', 'paid' => 'Paid', 'draft' => 'Drafts', 'void' => 'Voided', 'uncollectible' => 'Given up on']],
                $count,
            ]),

            // Products and subscriptions
            $kind('stripe.product_create', 'Stripe: add a product', 'package', 'Adds a product with its price: once, or every day, week, month or year. Gives out the product\'s id and its price\'s.'.$once, [
                [...$name, 'label' => 'Name'],
                ['name' => 'description', 'label' => 'Description', 'type' => 'template', 'required' => false],
                $amount,
                $currency,
                ['name' => 'every', 'label' => 'Paid', 'type' => 'choice', 'required' => true, 'default' => 'once', 'options' => ['once' => 'Once', 'day' => 'Every day', 'week' => 'Every week', 'month' => 'Every month', 'year' => 'Every year']],
                $metadata,
            ]),
            $kind('stripe.prices', 'Stripe: list prices', 'tag', 'Lists the prices on sale: each with its id, product, name, amount, and how often it recurs.', [['name' => 'product', 'label' => 'Of the product', 'type' => 'template', 'required' => false, 'placeholder' => 'prod_…'], $count]),
            $kind('stripe.subscriptions', 'Stripe: list subscriptions', 'repeat', 'Lists subscriptions: each with its status, customer, price and when its period ends.', [
                [...$customer, 'required' => false],
                ['name' => 'status', 'label' => 'Only', 'type' => 'choice', 'required' => true, 'default' => 'active', 'options' => ['active' => 'Active', 'trialing' => 'On a trial', 'past_due' => 'Behind on paying', 'unpaid' => 'Unpaid', 'canceled' => 'Ended', 'all' => 'All']],
                $count,
            ]),
            $kind('stripe.subscription_cancel', 'Stripe: end a subscription', 'circle-x', 'Ends a subscription: at the end of what is paid for, or now.', [
                ['name' => 'subscription', 'label' => 'Subscription', 'type' => 'template', 'required' => true, 'placeholder' => 'sub_…'],
                ['name' => 'when', 'label' => 'When', 'type' => 'choice', 'required' => true, 'default' => 'period_end', 'options' => ['period_end' => 'When what is paid for ends', 'now' => 'Now']],
            ]),

            // The account
            $kind('stripe.balance', 'Stripe: the balance', 'gauge', 'What the account holds: available, and pending, by currency; live, false for a test key.', []),
            $kind('stripe.request', 'Stripe: any call', 'globe', 'Any call of Stripe\'s API, as docs.stripe.com writes it, with the connection\'s key. Gives out answer: what Stripe answered. What it makes is made once.', [
                ['name' => 'method', 'label' => 'Method', 'type' => 'choice', 'required' => true, 'default' => 'GET', 'options' => ['GET' => 'GET', 'POST' => 'POST', 'DELETE' => 'DELETE']],
                ['name' => 'path', 'label' => 'Path', 'type' => 'template', 'required' => true, 'placeholder' => '/coupons'],
                ['name' => 'params', 'label' => 'Parameters', 'type' => 'fields', 'required' => false, 'default' => [], 'labels' => true, 'help' => 'Named as the docs name them; brackets for what is inside: metadata[order] 1234.'],
            ]),
        ];
    }

    // --- PromptX ---

    /**
     * @return list<NodeType>
     */
    private static function promptx(): array
    {
        $as = ['name' => 'connection', 'label' => 'Account', 'type' => 'connection', 'required' => true, 'services' => ['promptx']];
        $project = ['name' => 'project', 'label' => 'Project', 'type' => 'template', 'required' => false, 'placeholder' => 'Ab3dEf6hIj9kLm2nOp5qR', 'help' => 'Its id: in the address of a PromptX page, after /projects/. Left empty, the account\'s usual project.'];
        $flow = ['name' => 'flow', 'label' => 'Flow', 'type' => 'template', 'required' => true, 'placeholder' => '{{ node("new_flow").id }}', 'help' => 'Its id: in the address of the flow\'s page, after /flows/.'];
        $gives = ' Gives out the flow: id, name, status (ENABLED or DISABLED), version, valid, published and draft.';

        return [
            self::kind('promptx.flows', Steps\PromptxCall::class, 'PromptX: list flows', 'list', 'Lists the project\'s flows, or those of one folder. Gives out flows -- each with its id, name, status, folder, version, valid, published, draft and updated -- and count.', [
                $as,
                $project,
                ['name' => 'folder', 'label' => 'In the folder', 'type' => 'template', 'required' => false, 'help' => 'Its id, as "PromptX: list folders" gives it. Left empty, every flow.'],
                ['name' => 'count', 'label' => 'At most', 'type' => 'number', 'required' => false, 'default' => 100, 'help' => 'At most 100.'],
            ]),
            self::kind('promptx.flow_create', Steps\PromptxCall::class, 'PromptX: new flow', 'pencil', 'Makes an empty flow in the project, switched off, for "PromptX: import a flow" to fill.'.$gives, [
                $as,
                $project,
                ['name' => 'name', 'label' => 'Name', 'type' => 'template', 'required' => true, 'placeholder' => 'Daily report'],
            ], sends: true),
            self::kind('promptx.flow', Steps\PromptxCall::class, 'PromptX: read a flow', 'split', 'Reads a flow as its draft is now, or one of its versions.'.$gives.' With them, steps -- each with its name, label, type, piece and action -- and definition, which "PromptX: import a flow" takes back once changed. definition_json is the same written just as PromptX keeps it, every empty {} and [] the one it is: what to keep as a backup, and what an import takes back exactly.', [
                $as,
                $project,
                $flow,
                ['name' => 'version', 'label' => 'Version', 'type' => 'template', 'required' => false, 'help' => 'Its id, as "PromptX: list versions" gives it. Left empty, the draft.'],
            ]),
            self::kind('promptx.flow_import', Steps\PromptxCall::class, 'PromptX: import a flow', 'file-up', 'Puts a definition in a flow\'s draft, in place of all of its steps. What runs stays the published version until "PromptX: publish a flow".'.$gives, [
                $as,
                $project,
                $flow,
                ['name' => 'definition', 'label' => 'Definition', 'type' => 'template', 'required' => true, 'placeholder' => '{{ node("read_flow").definition }}', 'help' => 'A trigger with the steps after it, as "PromptX: read a flow" gives out its definition: changed by a step before this one, or JSON exported from PromptX.'],
                ['name' => 'name', 'label' => 'Rename it to', 'type' => 'template', 'required' => false, 'help' => 'Left empty, the definition\'s own name.'],
            ]),
            self::kind('promptx.flow_publish', Steps\PromptxCall::class, 'PromptX: publish a flow', 'megaphone', 'Publishes a flow\'s draft, which then runs in place of the version before. A flow published for the first time is switched on by PromptX: "Afterwards" can switch it off again.'.$gives, [
                $as,
                $project,
                $flow,
                ['name' => 'then', 'label' => 'Afterwards', 'type' => 'choice', 'required' => true, 'default' => 'on', 'options' => ['on' => 'On, as PromptX leaves it', 'off' => 'Switched off', 'as_was' => 'On or off, as it was before']],
            ]),
            self::kind('promptx.flow_status', Steps\PromptxCall::class, 'PromptX: switch a flow on or off', 'check-check', 'Switches a flow on, so it starts when its trigger fires, or off, keeping its steps and runs. One never published cannot be switched on: publish it first.'.$gives, [
                $as,
                $project,
                $flow,
                ['name' => 'status', 'label' => 'Switch it', 'type' => 'choice', 'required' => true, 'default' => 'DISABLED', 'options' => ['DISABLED' => 'Off', 'ENABLED' => 'On']],
            ]),
            self::kind('promptx.flow_versions', Steps\PromptxCall::class, 'PromptX: list versions', 'list-tree', 'Lists a flow\'s versions, newest first: the draft, and each published one, locked. Gives out versions -- each with its id, name, state (DRAFT or LOCKED), valid, created and updated -- and count. "PromptX: read a flow" reads one, and "PromptX: put a version back" makes one the draft again.', [
                $as,
                $project,
                $flow,
                ['name' => 'count', 'label' => 'At most', 'type' => 'number', 'required' => false, 'default' => 25, 'help' => 'At most 100.'],
            ]),
            self::kind('promptx.flow_restore', Steps\PromptxCall::class, 'PromptX: put a version back', 'reply', 'Makes one of a flow\'s versions its draft again, in place of the draft there is. What runs stays the published version until "PromptX: publish a flow".'.$gives, [
                $as,
                $project,
                $flow,
                ['name' => 'version', 'label' => 'Version', 'type' => 'template', 'required' => true, 'placeholder' => '{{ item(node("versions").versions, 2).id }}'],
            ]),
            self::kind('promptx.flow_delete', Steps\PromptxCall::class, 'PromptX: delete a flow', 'trash-2', 'Deletes a flow, with its versions and runs: it cannot be had back. Gives out id, deleted, and was_there -- false when it was gone already.', [$as, $project, $flow]),
            ...self::promptxSteps($as, $project, $flow),
            ...self::promptxRunning($as, $project, $flow),
            self::kind('promptx.runs', Steps\PromptxCall::class, 'PromptX: list runs', 'list', 'Lists the newest runs of a flow, or of the whole project. Gives out runs -- each with its id, flow, flow_name, status, started, finished, seconds and failed_step -- and count.', [
                $as,
                $project,
                [...$flow, 'required' => false, 'placeholder' => '', 'help' => 'Left empty, every flow\'s runs.'],
                ['name' => 'status', 'label' => 'Only', 'type' => 'choice', 'required' => true, 'default' => 'any', 'options' => ['any' => 'Any', 'FAILED' => 'Failed', 'SUCCEEDED' => 'Succeeded', 'RUNNING' => 'Running', 'PAUSED' => 'Paused', 'TIMEOUT' => 'Timed out', 'INTERNAL_ERROR' => 'Went wrong inside PromptX', 'QUOTA_EXCEEDED' => 'Out of allowance']],
                ['name' => 'count', 'label' => 'At most', 'type' => 'number', 'required' => false, 'default' => 10, 'help' => 'At most 100.'],
            ]),
            self::kind('promptx.run', Steps\PromptxCall::class, 'PromptX: read a run', 'text-search', 'Reads one run: how it went, failed_step and its error, and steps -- each with its name, type, status, ms, error, input and output, those in a loop with loop and round -- each input and output cut short where long. kept is false for a run so old that PromptX keeps only how it went.', [
                $as,
                $project,
                ['name' => 'run', 'label' => 'Run', 'type' => 'template', 'required' => true, 'placeholder' => '{{ item(node("list_runs").runs, 1).id }}'],
            ]),
        ];
    }

    /**
     * PromptX's kinds that change a flow's steps.
     *
     * @param  Setting  $as
     * @param  Setting  $project
     * @param  Setting  $flow
     * @return list<NodeType>
     */
    private static function promptxSteps(array $as, array $project, array $flow): array
    {
        $step = ['name' => 'step', 'label' => 'Step', 'type' => 'template', 'required' => true, 'placeholder' => 'step_2', 'help' => 'Its name, as "PromptX: read a flow" lists its steps.'];
        $after = ['name' => 'after', 'label' => 'After', 'type' => 'template', 'required' => false, 'placeholder' => 'trigger', 'help' => 'The step it goes after, or the loop or branch it goes in. Left empty, the trigger.'];
        $where = ['name' => 'where', 'label' => 'Where', 'type' => 'choice', 'required' => true, 'default' => 'AFTER', 'options' => ['AFTER' => 'After it', 'INSIDE_LOOP' => 'First inside its loop', 'INSIDE_TRUE' => 'First if its branch is true', 'INSIDE_FALSE' => 'First if its branch is false']];
        $gives = ' Gives out flow, and step as PromptX kept it -- its name, label, type, piece, action, valid, input, and settings_json: all its settings, each empty the one it is.';

        return [
            self::kind('promptx.step_update', Steps\PromptxEdit::class, 'PromptX: change a step', 'pencil', 'Changes one step of a flow\'s draft without importing the whole flow: the input values given, each in place of the one of that name, anything else in its settings, and what it is called. Values go just as written -- a new line as a new line, a {{step_1.body}} as PromptX\'s own -- and the step is read back.'.$gives, [
                $as,
                $project,
                $flow,
                $step,
                ['name' => 'input', 'label' => 'Input values', 'type' => 'json', 'required' => false, 'default' => '{
}', 'help' => 'A JSON object of the step\'s input values to set, named as PromptX names them: {"prompt": "Sum up {{trigger.body.text}}"}. The rest stay as they are.'],
                ['name' => 'settings', 'label' => 'Other settings', 'type' => 'json', 'required' => false, 'default' => '{
}', 'help' => 'Settings besides its input, e.g. a loop\'s {"items": "{{step_1.rows}}"} or a code step\'s sourceCode.'],
                ['name' => 'label', 'label' => 'Rename it to', 'type' => 'template', 'required' => false],
            ]),
            self::kind('promptx.step_add', Steps\PromptxEdit::class, 'PromptX: add a step', 'braces', 'Adds a step to a flow\'s draft, after another or inside a loop or branch. Written as PromptX writes one, or as its piece and action alone: {"displayName": "Ask AI", "pieceName": "@activepieces/piece-openai", "pieceVersion": "0.5.4", "actionName": "ask_chatgpt", "input": {"prompt": "{{trigger.body.text}}"}}. Left nameless, it is given the next free name. A Return Response step answers a waiting caller only with "respond": "stop" in its input.'.$gives, [
                $as,
                $project,
                $flow,
                $after,
                $where,
                ['name' => 'step', 'label' => 'The step', 'type' => 'json', 'required' => true, 'default' => '{
}'],
            ], sends: true),
            self::kind('promptx.step_delete', Steps\PromptxEdit::class, 'PromptX: delete steps', 'trash-2', 'Deletes steps from a flow\'s draft, by name. Gives out flow, deleted, and missing: those it did not have.', [
                $as,
                $project,
                $flow,
                ['name' => 'steps', 'label' => 'Steps', 'type' => 'template', 'required' => true, 'placeholder' => 'step_3, step_4'],
            ]),
            self::kind('promptx.step_move', Steps\PromptxEdit::class, 'PromptX: move a step', 'corner-down-right', 'Moves a step of a flow\'s draft after another, or into or out of a loop or branch. Gives out flow and steps: each in its new order.', [$as, $project, $flow, $step, $after, $where]),
            self::kind('promptx.trigger_update', Steps\PromptxEdit::class, 'PromptX: set the trigger', 'webhook', 'Sets what starts a flow -- a call to its webhook, a timetable, or a trigger written out -- keeping every step after it, in the draft. Gives out flow, trigger as PromptX kept it, kept_steps, and for a webhook its addresses: webhook, and webhook_sync, which waits for the flow\'s answer.', [
                $as,
                $project,
                $flow,
                ['name' => 'kind', 'label' => 'Started by', 'type' => 'choice', 'required' => true, 'default' => 'webhook', 'options' => ['webhook' => 'A call to its webhook', 'schedule' => 'A timetable', 'custom' => 'A trigger written out']],
                ['name' => 'cron', 'label' => 'When', 'type' => 'cron', 'required' => false, 'default' => '0 9 * * 1-5', 'help' => 'A cron line: minute, hour, day, month, weekday.', 'when' => ['kind' => 'schedule']],
                ['name' => 'timezone', 'label' => 'Time zone', 'type' => 'timezone', 'required' => false, 'when' => ['kind' => 'schedule']],
                ['name' => 'trigger', 'label' => 'The trigger', 'type' => 'json', 'required' => false, 'default' => '{
}', 'help' => 'Its pieceName, pieceVersion, triggerName and input, as "PromptX: read a flow" shows a trigger.', 'when' => ['kind' => 'custom']],
            ]),
        ];
    }

    /**
     * PromptX's kinds that run flows.
     *
     * @param  Setting  $as
     * @param  Setting  $project
     * @param  Setting  $flow
     * @return list<NodeType>
     */
    private static function promptxRunning(array $as, array $project, array $flow): array
    {
        $body = ['name' => 'body', 'label' => 'Body', 'type' => 'template', 'required' => false, 'placeholder' => '{"text": "{{ trigger.text }}"}', 'help' => 'JSON, or a value worked out here: what the flow reads as {{trigger.body}}.'];
        $seconds = ['name' => 'seconds', 'label' => 'Wait at most', 'type' => 'number', 'required' => false, 'default' => 30, 'help' => 'Seconds, at most '.PromptxRun::MOST_WAIT.'.'];
        $run = ['name' => 'run', 'label' => 'Run', 'type' => 'template', 'required' => true, 'placeholder' => '{{ item(node("list_runs").runs, 1).id }}'];
        $answer = ' Gives out status, ok, answer -- what the flow\'s Return Response step returned -- and timed_out.';

        return [
            self::kind('promptx.flow_run', PromptxRun::class, 'PromptX: run a flow', 'play', 'Calls a flow\'s webhook with a body: its published version, switched on, with a webhook trigger. Either leaves it to run, or waits for what it answers -- which takes a Return Response step whose input has "respond": "stop"; without it the flow runs and nothing comes back.'.$answer, [
                $flow,
                $body,
                ['name' => 'wait', 'label' => 'Wait for its answer', 'type' => 'choice', 'required' => true, 'default' => 'no', 'options' => ['no' => 'No: leave it running', 'yes' => 'Yes: until it answers']],
                [...$seconds, 'when' => ['wait' => 'yes']],
            ], sends: true),
            self::kind('promptx.flow_test', PromptxRun::class, 'PromptX: try a flow\'s draft', 'terminal', 'Runs a flow\'s draft through its webhook and waits for what it answers, to try a change before publishing it. PromptX keeps no run of it.'.$answer, [$flow, $body, $seconds], sends: true),
            self::kind('promptx.run_retry', PromptxRun::class, 'PromptX: try a run again', 'repeat', 'Tries a run again: from the step it failed at, as the same run, or from the top as a new run of the flow as it is now. Gives out the run.', [
                $as,
                $project,
                $run,
                ['name' => 'from', 'label' => 'From', 'type' => 'choice', 'required' => true, 'default' => 'failed', 'options' => ['failed' => 'The step it failed at', 'top' => 'The top, on the flow as it is now']],
            ], sends: true),
            self::kind('promptx.runs_cancel', PromptxRun::class, 'PromptX: stop runs', 'ban', 'Stops runs waiting to start or waiting part way. Gives out cancelled: their ids.', [
                $as,
                $project,
                ['name' => 'runs', 'label' => 'Runs', 'type' => 'template', 'required' => true, 'placeholder' => '{{ node("list_runs").runs }}', 'help' => 'Their ids, with commas between, or a list of runs as "PromptX: list runs" gives them.'],
            ], sends: true),
            self::kind('promptx.run_resume', PromptxRun::class, 'PromptX: answer a waiting run', 'user-check', 'Answers a run waiting for an approval or for what a person fills in, so it goes on. Gives out status and answer.', [
                $as,
                $project,
                $run,
                ['name' => 'request', 'label' => 'Request', 'type' => 'template', 'required' => true, 'help' => 'The id of what the run waits on, as PromptX gave it when the run paused.'],
                [...$body, 'help' => 'What the step waiting asked for, as JSON.'],
            ], sends: true),
        ];
    }

    /**
     * PromptX's kinds about a project's own things.
     *
     * @return list<NodeType>
     */
    private static function promptxProject(): array
    {
        $as = ['name' => 'connection', 'label' => 'Account', 'type' => 'connection', 'required' => true, 'services' => ['promptx']];
        $project = ['name' => 'project', 'label' => 'Project', 'type' => 'template', 'required' => false, 'placeholder' => 'Ab3dEf6hIj9kLm2nOp5qR', 'help' => 'Its id. Left empty, the account\'s usual project.'];
        $table = ['name' => 'table', 'label' => 'Table', 'type' => 'template', 'required' => true, 'help' => 'Its id, as "PromptX: list tables" gives it.'];

        return [
            self::kind('promptx.projects', Steps\PromptxProject::class, 'PromptX: list projects', 'network', 'Lists the account\'s projects. Gives out projects -- each with its id and name -- and count.', [$as]),
            self::kind('promptx.connections', Steps\PromptxProject::class, 'PromptX: list connections', 'link', 'Lists a project\'s connections by name -- never what they hold. Gives out connections -- each with its id, name, external_id (what a step names it by), piece and status -- and count.', [$as, $project]),
            self::kind('promptx.folders', Steps\PromptxProject::class, 'PromptX: list folders', 'folder-search', 'Lists a project\'s folders of flows. Gives out folders -- each with its id, name and flows, how many -- and count.', [$as, $project]),
            self::kind('promptx.folder_create', Steps\PromptxProject::class, 'PromptX: new folder', 'pencil', 'Makes a folder for flows in a project. Gives out its id and name.', [
                $as,
                $project,
                ['name' => 'name', 'label' => 'Name', 'type' => 'template', 'required' => true],
            ], sends: true),
            self::kind('promptx.tables', Steps\PromptxProject::class, 'PromptX: list tables', 'table-2', 'Lists a project\'s tables. Gives out tables -- each with its id, name and created -- and count.', [$as, $project]),
            self::kind('promptx.table_records', Steps\PromptxProject::class, 'PromptX: read a table', 'table-2', 'Reads a table\'s records. Gives out fields -- each with its id, name and type -- records, each with its id and its values by field name, and count.', [
                $as,
                $project,
                $table,
                ['name' => 'count', 'label' => 'At most', 'type' => 'number', 'required' => false, 'default' => 100, 'help' => 'At most 500.'],
            ]),
            self::kind('promptx.table_add', Steps\PromptxProject::class, 'PromptX: add records', 'table-2', 'Adds records to a table. Gives out added, how many, and ids.', [
                $as,
                $project,
                $table,
                ['name' => 'records', 'label' => 'Records', 'type' => 'template', 'required' => true, 'placeholder' => '[{"Name": "Ada", "Email": "ada@example.com"}]', 'help' => 'One record or a list, each with its values by field name -- as JSON, or a value worked out here.'],
            ], sends: true),
        ];
    }

    // --- Telegram ---

    /**
     * Buttons under a Telegram message, and how many go side by side.
     *
     * @return list<Setting>
     */
    public static function telegramButtons(string $help = 'Each is what the button says, and what pressing it does.'): array
    {
        return [
            ['name' => 'buttons', 'label' => 'Buttons', 'type' => 'fields', 'required' => false, 'default' => [], 'labels' => true, 'help' => $help, 'does' => [
                ['kind' => 'press', 'label' => 'Comes back as a press', 'written' => 'anything else', 'placeholder' => 'Its own words', 'hint' => 'For a "Telegram message" trigger set to buttons, which reads it as trigger.text; at most 64 bytes. Left empty, the button\'s own words come back.'],
                ['kind' => 'link', 'label' => 'Opens a link', 'address' => Steps\TelegramApi::ADDRESS, 'prefix' => 'link:', 'written' => 'an address, or "link:" and a formula that gives one', 'placeholder' => 'https://…'],
                ['kind' => 'webapp', 'label' => 'Opens a mini app', 'prefix' => 'webapp:', 'written' => '"webapp:" and its address', 'placeholder' => 'https://…', 'hint' => 'An https address, opened inside Telegram.'],
            ]],
            ['name' => 'per_row', 'label' => 'Buttons side by side', 'type' => 'number', 'required' => false, 'default' => 1, 'when' => ['buttons' => true]],
        ];
    }

    /**
     * @return list<NodeType>
     */
    private static function telegram(): array
    {
        $bot = ['name' => 'connection', 'label' => 'Bot', 'type' => 'connection', 'required' => true, 'services' => ['telegram'], 'linked' => true];
        $chat = ['name' => 'chat', 'label' => 'In chat', 'type' => 'template', 'required' => true, 'default' => '{{ trigger.chat.id }}', 'help' => 'From a linked chat, its other end is {{ trigger.room.link.chat }}.'];
        $to = [...$chat, 'label' => 'To chat'];
        $topic = ['name' => 'topic', 'label' => 'In topic', 'type' => 'template', 'required' => false, 'placeholder' => '{{ trigger.topic }}', 'help' => 'In a group with topics: the topic\'s id. Left empty, the general one.'];
        $message = ['name' => 'message', 'label' => 'Message', 'type' => 'template', 'required' => true, 'default' => '{{ trigger.reply.to }}', 'help' => 'Its id: the message that started the run is {{ trigger.reply.to }}; one a step sent, {{ node("send").message_id }}.'];
        $user = ['name' => 'user', 'label' => 'Who', 'type' => 'template', 'required' => true, 'default' => '{{ trigger.sender.id }}', 'help' => 'A Telegram user\'s id.'];
        $format = ['name' => 'format', 'label' => 'Written in', 'type' => 'choice', 'required' => true, 'default' => 'plain', 'options' => ['plain' => 'Plain text', 'markdown' => 'Markdown', 'html' => 'HTML']];
        $until = ['name' => 'minutes', 'label' => 'For how many minutes', 'type' => 'template', 'required' => false, 'help' => 'Left empty, for good.'];

        return [
            // Sent: something new in a chat
            self::kind('telegram.album', Steps\TelegramCall::class, 'Send an album on Telegram', 'images', 'Sends from 2 to 10 pictures and videos from the Drive as one message.', [
                $bot, $to, $topic,
                ['name' => 'files', 'label' => 'Files', 'type' => 'template', 'required' => true, 'placeholder' => '{{ node("find").files }}', 'help' => 'Files in the Drive: a list of them, or their ref_ids with commas between.'],
                ['name' => 'text', 'label' => 'Words under it', 'type' => 'template', 'required' => false],
            ], sends: true),
            self::kind('telegram.sticker', Steps\TelegramCall::class, 'Send a sticker on Telegram', 'sticker', 'Sends a sticker to a chat.', [
                $bot, $to, $topic,
                ['name' => 'sticker', 'label' => 'Sticker', 'type' => 'template', 'required' => true, 'placeholder' => '{{ trigger.sticker.id }}', 'help' => 'A sticker\'s id, as a message that carried one gives it, or the address of a .webp picture.'],
            ], sends: true),
            self::kind('telegram.location', Steps\TelegramCall::class, 'Send a location on Telegram', 'map-pin', 'Sends a place to a chat: a pin on the map, or with a name, a venue.', [
                $bot, $to, $topic,
                ['name' => 'lat', 'label' => 'Latitude', 'type' => 'template', 'required' => true, 'placeholder' => '{{ trigger.location.lat }}'],
                ['name' => 'lng', 'label' => 'Longitude', 'type' => 'template', 'required' => true, 'placeholder' => '{{ trigger.location.lng }}'],
                ['name' => 'title', 'label' => 'Name', 'type' => 'template', 'required' => false, 'help' => 'With a name it is sent as a venue.'],
                ['name' => 'address', 'label' => 'Address', 'type' => 'template', 'required' => false, 'when' => ['title' => true]],
            ], sends: true),
            self::kind('telegram.contact', Steps\TelegramCall::class, 'Send a contact on Telegram', 'contact', 'Sends someone\'s name and phone number to a chat, to save or call.', [
                $bot, $to, $topic,
                ['name' => 'phone', 'label' => 'Phone number', 'type' => 'template', 'required' => true],
                ['name' => 'first_name', 'label' => 'First name', 'type' => 'template', 'required' => true],
                ['name' => 'last_name', 'label' => 'Last name', 'type' => 'template', 'required' => false],
            ], sends: true),
            self::kind('telegram.poll', Steps\TelegramCall::class, 'Send a poll on Telegram', 'chart-bar', 'Asks a chat a question with answers to choose from. A "Telegram message" trigger set to poll answers hears who chose what, when the poll is not anonymous.', [
                $bot, $to, $topic,
                ['name' => 'question', 'label' => 'Question', 'type' => 'template', 'required' => true],
                ['name' => 'options', 'label' => 'Answers', 'type' => 'template', 'required' => true, 'placeholder' => "Yes\nNo\nMaybe", 'help' => 'One on each line, from 2 to 12.'],
                ['name' => 'anonymous', 'label' => 'Who chose what', 'type' => 'choice', 'required' => true, 'default' => 'yes', 'options' => ['yes' => 'Is kept secret', 'no' => 'Can be seen']],
                ['name' => 'several', 'label' => 'Choosing', 'type' => 'choice', 'required' => true, 'default' => 'no', 'options' => ['no' => 'One answer', 'yes' => 'Several answers']],
            ], sends: true),
            self::kind('telegram.forward', Steps\TelegramCall::class, 'Forward a message on Telegram', 'forward', 'Forwards a message from one chat to another, marked as forwarded.', [
                $bot,
                ['name' => 'from', 'label' => 'From chat', 'type' => 'template', 'required' => true, 'default' => '{{ trigger.chat.id }}'],
                $message,
                [...$to, 'default' => null, 'placeholder' => '-1001234567890'],
                $topic,
            ], sends: true),
            self::kind('telegram.copy', Steps\TelegramCall::class, 'Copy a message on Telegram', 'copy', 'Sends a copy of a message to another chat, as the bot\'s own: no mark of where it came from.', [
                $bot,
                ['name' => 'from', 'label' => 'From chat', 'type' => 'template', 'required' => true, 'default' => '{{ trigger.chat.id }}'],
                $message,
                [...$to, 'default' => null, 'placeholder' => '-1001234567890'],
                $topic,
            ], sends: true),
            self::kind('telegram.invoice', Steps\TelegramCall::class, 'Send an invoice on Telegram', 'credit-card', 'Asks a chat to pay for something: in Telegram Stars, or in money through a payment provider. Telegram then asks the bot to agree ("Telegram message" set to payments; "Answer a checkout on Telegram"), and says when it is paid.', [
                $bot, $to,
                ['name' => 'title', 'label' => 'For', 'type' => 'template', 'required' => true, 'placeholder' => 'Coffee beans, 250 g'],
                ['name' => 'description', 'label' => 'Description', 'type' => 'template', 'required' => false],
                ['name' => 'amount', 'label' => 'Amount', 'type' => 'template', 'required' => true, 'help' => 'A whole number: Stars, or for money its smallest unit -- 1050 for 10.50.'],
                ['name' => 'currency', 'label' => 'In', 'type' => 'template', 'required' => true, 'default' => 'XTR', 'help' => 'XTR is Telegram Stars. Money (USD, THB…) needs a payment provider.'],
                ['name' => 'provider', 'label' => 'Payment provider\'s token', 'type' => 'connection', 'required' => false, 'services' => ['webhook_secret'], 'help' => 'For money: the token @BotFather gives for a provider, kept as a "Webhook secret" connection. Not needed for Stars.'],
                ['name' => 'payload', 'label' => 'Your own reference', 'type' => 'template', 'required' => false, 'placeholder' => 'order-{{ trigger.id }}', 'help' => 'Comes back with the payment, as trigger.payment.payload. Never shown.'],
            ], sends: true),
            self::kind('telegram.topic', Steps\TelegramCall::class, 'Make a topic on Telegram', 'message-square-dashed', 'Makes a topic in a group that has topics, and gives out its id as topic, for a later step\'s "In topic".', [
                $bot, $chat,
                ['name' => 'name', 'label' => 'Called', 'type' => 'template', 'required' => true],
            ], sends: true),

            // Changed: what is there, set to what the step says
            self::kind('telegram.action', Steps\TelegramCall::class, 'Show typing on Telegram', 'ellipsis', 'Shows the chat that the bot is busy -- "typing…" -- for a few seconds, or until it sends something: before a step that takes a while, like asking an AI.', [
                $bot, $chat, $topic,
                ['name' => 'action', 'label' => 'Busy', 'type' => 'choice', 'required' => true, 'default' => 'typing', 'options' => [
                    'typing' => 'Typing', 'upload_photo' => 'Sending a picture', 'upload_document' => 'Sending a file', 'upload_video' => 'Sending a video',
                    'record_voice' => 'Recording', 'choose_sticker' => 'Choosing a sticker', 'find_location' => 'Finding a place',
                ]],
            ]),
            self::kind('telegram.edit', Steps\TelegramCall::class, 'Edit a message on Telegram', 'pencil', 'Changes what a message the bot sent says, in place -- and its buttons: the ones named here, or none.', [
                $bot, $chat,
                [...$message, 'default' => null, 'placeholder' => '{{ node("send").message_id }}', 'help' => 'The id of a message the bot sent: {{ node("send").message_id }}; after a press, the message pressed is {{ trigger.reply.to }}.'],
                ['name' => 'text', 'label' => 'Now says', 'type' => 'template', 'required' => true],
                $format,
                ...self::telegramButtons('Left empty, the message\'s buttons are taken off. Each is what the button says, and what pressing it does.'),
            ]),
            self::kind('telegram.edit_buttons', Steps\TelegramCall::class, 'Change a message\'s buttons on Telegram', 'square-mouse-pointer', 'Puts other buttons under a message the bot sent, or takes them off -- once one has been pressed, say -- leaving its words as they are.', [
                $bot, $chat,
                [...$message, 'help' => 'The id of a message the bot sent; after a press, the message pressed is {{ trigger.reply.to }}.'],
                ...self::telegramButtons('Left empty, the buttons are taken off. Each is what the button says, and what pressing it does.'),
            ]),
            self::kind('telegram.delete', Steps\TelegramCall::class, 'Delete a message on Telegram', 'trash-2', 'Takes a message out of a chat: the bot\'s own, or in a group it looks after, anyone\'s. Only within two days of it.', [$bot, $chat, $message]),
            self::kind('telegram.pin', Steps\TelegramCall::class, 'Pin a message on Telegram', 'pin', 'Pins a message at the top of a chat. In a group, the bot has to be allowed to.', [
                $bot, $chat, $message,
                ['name' => 'notify', 'label' => 'Tell everyone', 'type' => 'choice', 'required' => true, 'default' => 'no', 'options' => ['no' => 'Quietly', 'yes' => 'With a notification']],
            ]),
            self::kind('telegram.unpin', Steps\TelegramCall::class, 'Unpin a message on Telegram', 'pin-off', 'Takes a pinned message down from the top of a chat.', [
                $bot, $chat,
                [...$message, 'required' => false, 'default' => null, 'help' => 'Its id. Left empty, the one pinned last.'],
            ]),
            self::kind('telegram.react', Steps\TelegramCall::class, 'React to a message on Telegram', 'smile', 'Puts an emoji on a message, as the bot\'s reaction -- seen at once, where an answer would take longer.', [
                $bot, $chat, $message,
                ['name' => 'emoji', 'label' => 'Emoji', 'type' => 'template', 'required' => false, 'default' => '👍', 'help' => 'One of the emoji Telegram lets a reaction be. Left empty, the bot\'s reaction is taken off.'],
            ]),
            self::kind('telegram.answer_button', Steps\TelegramCall::class, 'Answer a button press on Telegram', 'mouse-pointer-click', 'Tells Telegram a press was heard, so the button stops waiting -- with a few words shown to whoever pressed, if any.', [
                $bot,
                ['name' => 'press', 'label' => 'The press', 'type' => 'template', 'required' => true, 'default' => '{{ trigger.button.id }}'],
                ['name' => 'text', 'label' => 'Say', 'type' => 'template', 'required' => false, 'placeholder' => 'Saved!'],
                ['name' => 'show', 'label' => 'Shown', 'type' => 'choice', 'required' => true, 'default' => 'toast', 'options' => ['toast' => 'Briefly, at the top', 'alert' => 'In a box to close'], 'when' => ['text' => true]],
            ]),
            self::kind('telegram.commands', Steps\TelegramCall::class, 'Set the bot\'s commands on Telegram', 'slash', 'Sets the list a chat offers when "/" is typed: each command, and what it does.', [
                $bot,
                ['name' => 'commands', 'label' => 'Commands', 'type' => 'fields', 'required' => true, 'default' => [], 'help' => 'Each named without its "/", in lowercase letters, digits and _: expense, and what it does: "Note an expense". None at all clears the list.'],
            ]),
            self::kind('telegram.ban', Steps\TelegramCall::class, 'Ban someone on Telegram', 'ban', 'Puts someone out of a group the bot looks after, and keeps them out.', [$bot, $chat, $user, $until]),
            self::kind('telegram.unban', Steps\TelegramCall::class, 'Lift a ban on Telegram', 'user-check', 'Lets someone banned from a group come back; they are not added, only allowed.', [$bot, $chat, $user]),
            self::kind('telegram.restrict', Steps\TelegramCall::class, 'Limit someone on Telegram', 'shield', 'Sets what someone may do in a group the bot looks after.', [
                $bot, $chat, $user,
                ['name' => 'allow', 'label' => 'They may', 'type' => 'choice', 'required' => true, 'default' => 'nothing', 'options' => ['nothing' => 'Only read', 'messages' => 'Write, but send nothing else', 'everything' => 'Do everything again']],
                $until,
            ]),
            self::kind('telegram.answer_inline', Steps\TelegramCall::class, 'Answer an inline question on Telegram', 'at-sign', 'Answers what someone typed after the bot\'s @name in any chat, with results to choose from. Starts from a "Telegram message" trigger set to inline questions; the bot needs inline mode switched on in @BotFather.', [
                $bot,
                ['name' => 'question', 'label' => 'The question', 'type' => 'template', 'required' => true, 'default' => '{{ trigger.inline.id }}'],
                ['name' => 'results', 'label' => 'Results', 'type' => 'fields', 'required' => true, 'default' => [], 'labels' => true, 'help' => 'Each is a result\'s title, and the message that choosing it sends. What was typed is {{ trigger.text }}.'],
            ]),
            self::kind('telegram.answer_checkout', Steps\TelegramCall::class, 'Answer a checkout on Telegram', 'badge-check', 'Agrees to a payment about to be made, or refuses it. Telegram waits ten seconds for this, so put it first after the trigger.', [
                $bot,
                ['name' => 'checkout', 'label' => 'The checkout', 'type' => 'template', 'required' => true, 'default' => '{{ trigger.checkout.id }}'],
                ['name' => 'answer', 'label' => 'Answer', 'type' => 'choice', 'required' => true, 'default' => 'ok', 'options' => ['ok' => 'Go ahead', 'refuse' => 'Refuse it']],
                ['name' => 'why', 'label' => 'Why not', 'type' => 'template', 'required' => false, 'placeholder' => 'Sorry, the last one just went.', 'when' => ['answer' => 'refuse']],
            ]),

            // Asked
            self::kind('telegram.chat', Steps\TelegramCall::class, 'Telegram: about a chat', 'info', 'Asks Telegram about a chat: gives out its id, kind, title, username, description, whether it has topics, and its photo -- the photo of whoever a one-to-one chat is with -- as a file "Telegram: get the file" fetches.', [$bot, $chat]),
            self::kind('telegram.member', Steps\TelegramCall::class, 'Telegram: about a member', 'user-search', 'Asks Telegram how someone stands in a chat: gives out their name, their status, and whether they are in it and look after it.', [$bot, $chat, $user]),
            self::kind('telegram.member_count', Steps\TelegramCall::class, 'Telegram: how many members', 'users', 'Asks Telegram how many people are in a chat, as count.', [$bot, $chat]),
        ];
    }

    // --- LINE ---

    /**
     * What a LINE message is made of, the same for every call that carries
     * one (reply, push, multicast, broadcast).
     *
     * @return list<Setting>
     */
    public static function lineMessage(): array
    {
        $buttonHelp = 'Each is what it says (20 letters), and what tapping it does.';
        $does = self::lineDoes();

        return [
            ['name' => 'kind', 'label' => 'Send', 'type' => 'choice', 'required' => true, 'default' => 'text', 'options' => [
                'text' => 'Words, a picture, or both', 'sticker' => 'A sticker', 'location' => 'A place', 'video' => 'A video', 'audio' => 'A recording',
                'buttons' => 'Buttons to choose from', 'confirm' => 'A yes-or-no question', 'flex' => 'A Flex card',
            ]],
            ['name' => 'text', 'label' => 'Message', 'type' => 'template', 'required' => false, 'when' => ['kind' => ['text', 'location', 'buttons', 'confirm']], 'help' => 'With a picture or a place, it can be left empty.'],
            ['name' => 'file', 'label' => 'Picture', 'type' => 'template', 'required' => false, 'placeholder' => '{{ node("save").ref_id }}', 'drive' => 'image', 'when' => ['kind' => 'text'], 'help' => 'A JPEG or PNG in the Drive, by its ref_id, at most 10 MB. LINE fetches it from this app, which must be reachable from outside.'],
            ['name' => 'quote', 'label' => 'Quoting', 'type' => 'template', 'required' => false, 'placeholder' => '{{ trigger.reply.quote }}', 'when' => ['kind' => 'text'], 'help' => 'The quote token of a message, to show the words as an answer to it.'],
            ['name' => 'package', 'label' => 'Sticker package', 'type' => 'template', 'required' => false, 'placeholder' => '446', 'when' => ['kind' => 'sticker'], 'help' => 'Only the stickers LINE lists as sendable; a message\'s own are trigger.sticker.package and trigger.sticker.id.'],
            ['name' => 'sticker', 'label' => 'Sticker', 'type' => 'template', 'required' => false, 'placeholder' => '1988', 'when' => ['kind' => 'sticker']],
            ['name' => 'title', 'label' => 'Title', 'type' => 'template', 'required' => false, 'when' => ['kind' => ['location', 'buttons']]],
            ['name' => 'address', 'label' => 'Address', 'type' => 'template', 'required' => false, 'when' => ['kind' => 'location']],
            ['name' => 'lat', 'label' => 'Latitude', 'type' => 'template', 'required' => false, 'placeholder' => '{{ trigger.location.lat }}', 'when' => ['kind' => 'location']],
            ['name' => 'lng', 'label' => 'Longitude', 'type' => 'template', 'required' => false, 'placeholder' => '{{ trigger.location.lng }}', 'when' => ['kind' => 'location']],
            ['name' => 'media', 'label' => 'File', 'type' => 'template', 'required' => false, 'placeholder' => '{{ node("save").ref_id }}', 'when' => ['kind' => ['video', 'audio']], 'help' => 'In the Drive, by its ref_id: an MP4 video, or an MP3 or M4A recording. LINE fetches it from this app.'],
            ['name' => 'preview', 'label' => 'Picture shown first', 'type' => 'template', 'required' => false, 'drive' => 'image', 'when' => ['kind' => 'video'], 'help' => 'A JPEG or PNG in the Drive: what the video shows before it plays.'],
            ['name' => 'seconds', 'label' => 'How long, in seconds', 'type' => 'template', 'required' => false, 'when' => ['kind' => 'audio'], 'help' => 'LINE shows this as the recording\'s length.'],
            ['name' => 'choices', 'label' => 'Buttons', 'type' => 'fields', 'required' => false, 'default' => [], 'labels' => true, 'when' => ['kind' => 'buttons'], 'help' => "Up to four. {$buttonHelp}", 'does' => $does],
            ['name' => 'yes', 'label' => 'Yes says', 'type' => 'template', 'required' => false, 'default' => 'Yes', 'when' => ['kind' => 'confirm'], 'help' => 'Tapping it says so in the chat.'],
            ['name' => 'no', 'label' => 'No says', 'type' => 'template', 'required' => false, 'default' => 'No', 'when' => ['kind' => 'confirm']],
            ['name' => 'flex', 'label' => 'Card', 'type' => 'template', 'required' => false, 'when' => ['kind' => 'flex'], 'placeholder' => '{ "type": "bubble", "body": { … } }', 'help' => 'The JSON of a bubble or a carousel, as LINE\'s Flex Message Simulator gives it. It can hold {{ formulas }}.'],
            ['name' => 'alt', 'label' => 'In a notification', 'type' => 'template', 'required' => false, 'when' => ['kind' => ['flex', 'buttons', 'confirm']], 'help' => 'What a notification says, where the card or the buttons can\'t be drawn.'],
            ['name' => 'quick', 'label' => 'Quick replies', 'type' => 'fields', 'required' => false, 'default' => [], 'labels' => true, 'help' => "Shown over the keyboard until one is tapped; up to 13. {$buttonHelp}", 'does' => $does],
        ];
    }

    /**
     * @return list<NodeType>
     */
    private static function line(): array
    {
        $channel = ['name' => 'connection', 'label' => 'Channel', 'type' => 'connection', 'required' => true, 'services' => ['line'], 'linked' => true];
        $user = ['name' => 'user', 'label' => 'Who', 'type' => 'template', 'required' => true, 'default' => '{{ trigger.sender.id }}', 'help' => 'A LINE user id (U…).'];
        $group = ['name' => 'chat', 'label' => 'Group', 'type' => 'template', 'required' => true, 'default' => '{{ trigger.chat.id }}', 'help' => 'A group (C…) or a multi-person chat (R…).'];

        return [
            self::kind('line.multicast', Steps\LineCall::class, 'Send to many on LINE', 'users', 'Sends one message to several people at once, by LINE\'s multicast call: up to 500, each counted against the channel\'s monthly allowance.', [
                $channel,
                ['name' => 'to', 'label' => 'To', 'type' => 'template', 'required' => true, 'placeholder' => '{{ node("find").rows.line_id }}', 'help' => 'LINE user ids (U…): a list of them, or with commas between. Not groups.'],
                ...self::lineMessage(),
            ], sends: true, unsureHelp: 'It never goes out twice: LINE is given a key for it.'),
            self::kind('line.broadcast', Steps\LineCall::class, 'Send to everyone on LINE', 'megaphone', 'Sends one message to everyone who has added the channel as a friend, by LINE\'s broadcast call: each of them counted against the monthly allowance.', [
                $channel,
                ...self::lineMessage(),
            ], sends: true, unsureHelp: 'It never goes out twice: LINE is given a key for it.'),
            self::kind('line.loading', Steps\LineCall::class, 'Show loading on LINE', 'ellipsis', 'Shows someone that an answer is on its way -- three moving dots -- until a message comes or the time is up. Only in a chat with one person, and only while they have it open.', [
                $channel,
                ['name' => 'chat', 'label' => 'To', 'type' => 'template', 'required' => true, 'default' => '{{ trigger.sender.id }}', 'help' => 'A LINE user id (U…).'],
                ['name' => 'seconds', 'label' => 'For at most, in seconds', 'type' => 'number', 'required' => true, 'default' => 20],
            ]),
            self::kind('line.read', Steps\LineCall::class, 'Mark as read on LINE', 'check-check', 'Marks a message, and all before it in its chat, as read. With Chat switched on for the channel; with it off, LINE marks everything read by itself.', [
                $channel,
                ['name' => 'token', 'label' => 'Read token', 'type' => 'template', 'required' => true, 'default' => '{{ trigger.reply.read }}', 'help' => 'What a LINE message comes with: {{ trigger.reply.read }}.'],
            ]),
            self::kind('line.leave', Steps\LineCall::class, 'Leave a group on LINE', 'log-out', 'Takes the channel out of a group or a multi-person chat.', [$channel, $group]),
            ...self::lineRichMenus($channel, $user),
            self::kind('line.quota', Steps\LineCall::class, 'LINE: monthly allowance', 'gauge', 'Asks LINE how many messages the channel may send first this month: limited, and limit.', [$channel]),
            self::kind('line.consumption', Steps\LineCall::class, 'LINE: sent this month', 'chart-bar', 'Asks LINE how many messages counted against the allowance have gone out this month, as sent.', [$channel]),
            self::kind('line.group', Steps\LineCall::class, 'LINE: about a group', 'info', 'Asks LINE for a group\'s name and picture.', [$channel, $group]),
            self::kind('line.group_count', Steps\LineCall::class, 'LINE: how many members', 'users', 'Asks LINE how many people are in a group or a multi-person chat, as count.', [$channel, $group]),
        ];
    }

    /**
     * What tapping a LINE button may do, and how each is written as the
     * button's value (LineApi::action reads it). On a rich menu, a button
     * may turn to another menu too.
     *
     * @return list<array<string, string>>
     */
    private static function lineDoes(bool $menu = false): array
    {
        return [
            ['kind' => 'say', 'label' => 'Says a message in the chat', 'written' => 'anything else', 'placeholder' => 'Its own words', 'hint' => 'Said as the person\'s own message. Left empty, the button\'s own words are said.'],
            ['kind' => 'link', 'label' => 'Opens a link', 'address' => Steps\LineApi::ADDRESS, 'prefix' => 'link:', 'written' => 'an address, or "link:" and a formula that gives one', 'placeholder' => 'https://…', 'hint' => 'An address on the internet, tel: and a number to call, or line:// for somewhere in LINE.'],
            ['kind' => 'postback', 'label' => 'Sends data back, unseen', 'prefix' => 'postback:', 'written' => '"postback:" and the data', 'placeholder' => 'hours', 'hint' => 'Comes back as trigger.text of a "LINE message" trigger set to buttons.'],
            ...$menu ? [['kind' => 'menu', 'label' => 'Turns to another menu', 'prefix' => 'menu:', 'written' => '"menu:" and the other menu\'s short name', 'placeholder' => 'more', 'hint' => 'The other menu\'s short name. It turns to it as a tab does.']] : [],
        ];
    }

    /**
     * The rich menu -- the panel under a chat -- made, shown and named.
     *
     * @param  Setting  $channel
     * @param  Setting  $user
     * @return list<NodeType>
     */
    private static function lineRichMenus(array $channel, array $user): array
    {
        $menu = ['name' => 'menu', 'label' => 'Rich menu', 'type' => 'template', 'required' => true, 'placeholder' => '{{ node("make").id }}', 'help' => 'Its id (richmenu-…), or a short name given to it; "LINE: rich menus" lists the channel\'s.'];
        $alias = ['name' => 'alias', 'label' => 'Short name', 'type' => 'template', 'required' => true, 'placeholder' => 'main', 'help' => 'Up to 32 of a-z, 0-9, "-" and "_".'];
        $many = ['name' => 'to', 'label' => 'Who', 'type' => 'template', 'required' => true, 'placeholder' => '{{ node("find").rows.line_id }}', 'help' => 'LINE user ids (U…): a list of them, or with commas between; up to 500.'];
        $layouts = [
            'six' => 'Six: three across, two rows',
            'four' => 'Four: two across, two rows',
            'top' => 'Four: one across the top, three under it',
            'large' => 'Three: a large one, two beside it',
            'rows' => 'Two: one over the other',
            'three' => 'Three, side by side',
            'two' => 'Two, side by side',
            'narrow' => 'Two: a narrow one, then a wide one',
            'wide' => 'Two: a wide one, then a narrow one',
            'one' => 'One: the whole picture',
            'custom' => 'Areas of my own',
        ];

        return [
            self::kind('line.richmenu_make', Steps\LineRichMenu::class, 'Make a rich menu on LINE', 'panel-bottom-open', 'Makes a rich menu -- the panel under a chat: a picture, and places on it to tap -- and puts its picture on it. It is shown to no one until it is set for everyone or for someone. Its picture and its buttons can\'t be changed after: a menu that is to look different is made again. Gives out its id.', [
                $channel,
                ['name' => 'name', 'label' => 'Called', 'type' => 'template', 'required' => true, 'placeholder' => 'Main menu', 'help' => 'For telling the channel\'s menus apart; people never see it.'],
                ['name' => 'picture', 'label' => 'Picture', 'type' => 'template', 'required' => true, 'placeholder' => '{{ node("save").ref_id }}', 'drive' => 'image', 'help' => 'A JPEG or PNG in the Drive, by its ref_id, at most 1 MB: 800 to 2500 wide, at least 250 high, at least 1.45 times as wide as it is high. LINE\'s own are 2500 × 1686 and, half as high, 2500 × 843. The menu is made the picture\'s size.'],
                ['name' => 'layout', 'label' => 'Buttons on it', 'type' => 'choice', 'required' => true, 'default' => 'six', 'options' => $layouts, 'help' => 'Where the picture can be tapped. Draw the picture to match.'],
                ['name' => 'buttons', 'label' => 'Buttons', 'type' => 'fields', 'required' => false, 'default' => [], 'labels' => true, 'when' => ['layout' => array_values(array_diff(array_keys($layouts), ['custom']))], 'help' => 'From left to right, top to bottom. Each is what it is (read aloud for those who can\'t see the picture; 20 letters), and what tapping it does. A place left without a button does nothing.', 'does' => self::lineDoes(menu: true), 'places' => ['by' => 'layout', 'picture' => 'picture', 'layouts' => Steps\LineRichMenu::LAYOUTS]],
                ['name' => 'areas', 'label' => 'Areas', 'type' => 'template', 'required' => false, 'when' => ['layout' => 'custom'], 'placeholder' => '[{ "bounds": { "x": 0, "y": 0, "width": 1250, "height": 843 }, "action": { "type": "message", "text": "Hours" } }]', 'help' => 'A JSON list of up to 20, each with its bounds on the picture and its action, as LINE takes them. It can hold {{ formulas }}.'],
                ['name' => 'bar', 'label' => 'On the bar', 'type' => 'template', 'required' => false, 'default' => 'Menu', 'help' => 'The words on the bar under the chat that opens and closes the menu; at most 14 letters.'],
                ['name' => 'open', 'label' => 'When the chat is opened', 'type' => 'choice', 'required' => true, 'default' => 'yes', 'options' => ['yes' => 'The menu is open', 'no' => 'The menu is closed until its bar is tapped']],
            ]),
            self::kind('line.richmenu_default', Steps\LineRichMenu::class, 'Show a rich menu to everyone on LINE', 'panel-bottom', 'Makes a rich menu the one everyone sees who has none of their own, in place of the one shown before. It comes before a menu set in LINE Official Account Manager.', [$channel, $menu]),
            self::kind('line.richmenu_default_off', Steps\LineRichMenu::class, 'Stop showing a rich menu to everyone on LINE', 'panel-bottom-close', 'Takes away the rich menu shown to everyone. Those given one of their own keep it; the rest see the one set in LINE Official Account Manager, if there is one.', [$channel]),
            self::kind('line.richmenu', Steps\LineRichMenu::class, 'Set someone\'s rich menu on LINE', 'panel-bottom', 'Gives one person a rich menu of the channel\'s -- the panel under their chat -- in place of the usual one.', [$channel, $user, $menu]),
            self::kind('line.richmenu_off', Steps\LineRichMenu::class, 'Take off someone\'s rich menu on LINE', 'panel-bottom-close', 'Takes away the rich menu given to one person; they see the channel\'s usual one again.', [$channel, $user]),
            self::kind('line.richmenu_many', Steps\LineRichMenu::class, 'Set many people\'s rich menu on LINE', 'panel-bottom', 'Gives several people a rich menu of the channel\'s at once, in place of the usual one. LINE does it shortly after, not at once.', [$channel, $many, $menu]),
            self::kind('line.richmenu_many_off', Steps\LineRichMenu::class, 'Take off many people\'s rich menu on LINE', 'panel-bottom-close', 'Takes away the rich menu given to several people at once; they see the channel\'s usual one again. LINE does it shortly after, not at once.', [$channel, $many]),
            self::kind('line.richmenu_of', Steps\LineRichMenu::class, 'LINE: someone\'s rich menu', 'panel-bottom-dashed', 'Asks LINE which rich menu one person was given: its id as menu, or nothing when they see the usual one.', [$channel, $user]),
            self::kind('line.richmenu_alias', Steps\LineRichMenu::class, 'Name a rich menu on LINE', 'tag', 'Gives a rich menu a short name, by which a button on another menu turns to it ("menu:" and the name) and any step can name it. A name already in use is moved to this menu: every tab that names it then turns to the new one.', [$channel, $alias, $menu]),
            self::kind('line.richmenu_alias_off', Steps\LineRichMenu::class, 'Take away a rich menu\'s name on LINE', 'tag', 'Takes a short name away from the rich menu that goes by it. A tab that names it does nothing after.', [$channel, $alias]),
            self::kind('line.richmenu_delete', Steps\LineRichMenu::class, 'Delete a rich menu on LINE', 'trash-2', 'Deletes a rich menu of the channel\'s for good. Those shown it see the usual one, or none.', [$channel, $menu]),
            self::kind('line.richmenus', Steps\LineRichMenu::class, 'LINE: rich menus', 'list', 'Asks LINE for the channel\'s rich menus: gives out menus, each with its id, name, the words on its bar, its size, how many places it has to tap, the short names it goes by (aliases), and whether everyone is shown it (default). Menus drawn in LINE Official Account Manager are not among them.', [$channel]),
        ];
    }

    // --- LinkedIn ---

    /**
     * Who sees a LinkedIn post.
     *
     * @return list<Setting>
     */
    public static function linkedinSeenBy(): array
    {
        return [
            ['name' => 'visibility', 'label' => 'Seen by', 'type' => 'choice', 'required' => true, 'default' => 'PUBLIC', 'options' => ['PUBLIC' => 'Anyone', 'CONNECTIONS' => 'Connections only']],
        ];
    }

    /**
     * @return list<NodeType>
     */
    private static function linkedin(): array
    {
        $as = ['name' => 'connection', 'label' => 'As', 'type' => 'connection', 'required' => true, 'services' => ['linkedin']];
        $post = ['name' => 'post', 'label' => 'Post', 'type' => 'template', 'required' => true, 'placeholder' => '{{ node("post").id }}', 'help' => 'Its id, as "Post on LinkedIn" gives it, or its link.'];

        return [
            self::kind('linkedin.repost', Steps\LinkedInCall::class, 'Repost on LinkedIn', 'repeat', 'Shares a post again on the member\'s own profile, with words of their own over it or none. Gives out the new post\'s id and its address as url.', [
                $as,
                $post,
                ['name' => 'text', 'label' => 'Words over it', 'type' => 'template', 'required' => false, 'help' => 'Left empty, a plain repost.'],
                ...self::linkedinSeenBy(),
            ], sends: true),
            self::kind('linkedin.delete', Steps\LinkedInCall::class, 'Delete a post on LinkedIn', 'trash-2', 'Deletes one of the member\'s own posts for good. One already gone is no error.', [$as, $post]),
            self::kind('linkedin.comment', Steps\LinkedInCall::class, 'Comment on LinkedIn', 'message-square', 'Writes a comment under a post, as the member. Gives out the comment\'s id, and urn, which "Delete a comment on LinkedIn" takes.', [
                $as,
                $post,
                ['name' => 'text', 'label' => 'Comment', 'type' => 'template', 'required' => true, 'help' => 'At most 1,250 letters.'],
            ], sends: true),
            self::kind('linkedin.comment_delete', Steps\LinkedInCall::class, 'Delete a comment on LinkedIn', 'trash-2', 'Takes away a comment the member wrote.', [
                $as,
                ['name' => 'comment', 'label' => 'Comment', 'type' => 'template', 'required' => true, 'placeholder' => '{{ node("comment").urn }}', 'help' => 'What "Comment on LinkedIn" gives as urn -- or its id, with its post below.'],
                [...$post, 'required' => false, 'label' => 'Its post', 'help' => 'Only with a comment named by its id alone.'],
            ]),
            self::kind('linkedin.like', Steps\LinkedInCall::class, 'Like a post on LinkedIn', 'thumbs-up', 'Likes a post, as the member.', [$as, $post]),
            self::kind('linkedin.unlike', Steps\LinkedInCall::class, 'Take back a like on LinkedIn', 'thumbs-down', 'Takes the member\'s like off a post.', [$as, $post]),
            self::kind('linkedin.me', Steps\LinkedInCall::class, 'LinkedIn: who I am', 'badge-check', 'Asks LinkedIn who the connection is signed in as. Gives out id, name, first, last, picture and language.', [$as]),
        ];
    }

    // --- WhatsApp ---

    /**
     * @return list<NodeType>
     */
    private static function whatsapp(): array
    {
        $number = ['name' => 'connection', 'label' => 'Number', 'type' => 'connection', 'required' => true, 'services' => ['whatsapp'], 'linked' => true];
        $to = ['name' => 'to', 'label' => 'To', 'type' => 'template', 'required' => true, 'default' => '{{ trigger.chat.id }}', 'help' => 'Their number with its country code, like 66812345678.'];
        $under = ['name' => 'reply_to', 'label' => 'In answer to', 'type' => 'template', 'required' => false, 'placeholder' => '{{ trigger.reply.to }}', 'help' => 'A message\'s id: this is then shown under it. Left empty, it stands alone.'];
        $window = 'Only within 24 hours of their last message: after that, WhatsApp takes a template alone.';

        return [
            self::kind('whatsapp.send', Steps\WhatsAppSend::class, 'Send on WhatsApp', 'send', 'Sends words from a business number to someone who wrote to it, with up to three buttons under them when any are named. '.$window, [
                $number,
                $to,
                ['name' => 'text', 'label' => 'Message', 'type' => 'template', 'required' => true],
                ['name' => 'buttons', 'label' => 'Buttons', 'type' => 'fields', 'required' => false, 'default' => [], 'labels' => true, 'help' => 'Up to three, each at most 20 letters: what it says, and what a press sends back as trigger.text -- its own words when left empty.'],
                $under,
            ], sends: true),
            self::kind('whatsapp.send_file', Steps\WhatsAppCall::class, 'Send a file on WhatsApp', 'file-up', 'Sends a file from the Drive: a picture (JPEG or PNG, at most 5 MB), a video or a recording (16 MB) is shown as one, anything else goes as a document (100 MB). '.$window, [
                $number,
                $to,
                ['name' => 'file', 'label' => 'File', 'type' => 'template', 'required' => true, 'placeholder' => '{{ node("save").ref_id }}', 'help' => 'A file in the Drive, by its ref_id.'],
                ['name' => 'text', 'label' => 'Words under it', 'type' => 'template', 'required' => false],
                $under,
            ], sends: true),
            self::kind('whatsapp.template', Steps\WhatsAppCall::class, 'Send a template on WhatsApp', 'file-text', 'Sends a message made from a template WhatsApp has approved for the business: the one thing that reaches someone first, or after the 24 hours since they last wrote. Meta charges for most of them.', [
                $number,
                [...$to, 'default' => null, 'placeholder' => '66812345678'],
                ['name' => 'name', 'label' => 'Template', 'type' => 'template', 'required' => true, 'placeholder' => 'hello_world', 'help' => 'Its name, as WhatsApp Manager lists it.'],
                ['name' => 'language', 'label' => 'In', 'type' => 'template', 'required' => true, 'default' => 'en_US', 'help' => 'The language it was approved in, as WhatsApp writes it: en_US, th.'],
                ['name' => 'values', 'label' => 'Its values', 'type' => 'template', 'required' => false, 'placeholder' => "{{ trigger.name }}\n{{ trigger.total }}", 'help' => 'What goes where its words leave room ({{1}}, {{2}}…), one on a line, in order.'],
            ], sends: true),
            self::kind('whatsapp.location', Steps\WhatsAppCall::class, 'Send a place on WhatsApp', 'map-pin', 'Sends a place, shown on a map. '.$window, [
                $number,
                $to,
                ['name' => 'lat', 'label' => 'Latitude', 'type' => 'template', 'required' => true, 'placeholder' => '13.7563'],
                ['name' => 'lng', 'label' => 'Longitude', 'type' => 'template', 'required' => true, 'placeholder' => '100.5018'],
                ['name' => 'name', 'label' => 'Called', 'type' => 'template', 'required' => false],
                ['name' => 'address', 'label' => 'Address', 'type' => 'template', 'required' => false],
                $under,
            ], sends: true),
            self::kind('whatsapp.react', Steps\WhatsAppCall::class, 'React on WhatsApp', 'smile', 'Puts an emoji on a message someone sent, in place of one put there before.', [
                $number,
                $to,
                ['name' => 'message', 'label' => 'On message', 'type' => 'template', 'required' => true, 'default' => '{{ trigger.reply.to }}', 'help' => 'The message\'s id.'],
                ['name' => 'emoji', 'label' => 'Emoji', 'type' => 'template', 'required' => false, 'default' => '👍', 'help' => 'Left empty, the reaction is taken back.'],
            ]),
            self::kind('whatsapp.read', Steps\WhatsAppCall::class, 'Mark as read on WhatsApp', 'check-check', 'Marks a message, and all before it in its chat, as read: the two ticks under theirs turn blue.', [
                $number,
                ['name' => 'message', 'label' => 'Message', 'type' => 'template', 'required' => true, 'default' => '{{ trigger.reply.to }}', 'help' => 'The message\'s id.'],
                ['name' => 'typing', 'label' => 'And show "typing…"', 'type' => 'choice', 'required' => true, 'default' => 'no', 'options' => ['no' => 'No', 'yes' => 'Yes, until an answer goes or 25 seconds pass']],
            ]),
        ];
    }

    // --- A custom channel ---

    /**
     * @return list<NodeType>
     */
    private static function channel(): array
    {
        return [
            self::kind('channel.send', Steps\ChannelSend::class, 'Send on a custom channel', 'send', 'Posts words, a file and a place to the channel\'s "Send to" address as JSON -- {"chat", "text", "file": {"url", "name", "mime", "size"}, "location"} -- signed with its secret, with an Idempotency-Key for a repeat to be seen by. A file goes as a link the platform fetches it by, good for a day. Gives out the platform\'s answer as body, and its id when it gives one.', [
                ['name' => 'connection', 'label' => 'Channel', 'type' => 'connection', 'required' => true, 'services' => ['channel'], 'linked' => true],
                ['name' => 'to', 'label' => 'Chat', 'type' => 'template', 'required' => true, 'default' => '{{ trigger.chat.id }}', 'help' => 'The chat\'s id on the platform, as its messages give it.'],
                ['name' => 'text', 'label' => 'Message', 'type' => 'template', 'required' => false],
                ['name' => 'file', 'label' => 'With file', 'type' => 'template', 'required' => false, 'placeholder' => '{{ item(trigger.files, 1) }}', 'help' => 'A file in the Drive, by its ref_id.'],
                ['name' => 'place', 'label' => 'With a place', 'type' => 'template', 'required' => false, 'placeholder' => '{{ trigger.location }}', 'help' => 'A record with lat and lng, and a title and address when it has them.'],
            ], sends: true),
        ];
    }

    // --- Facebook ---

    /**
     * @return list<NodeType>
     */
    private static function facebook(): array
    {
        $page = ['name' => 'connection', 'label' => 'Page', 'type' => 'connection', 'required' => true, 'services' => ['facebook'], 'help' => 'A Facebook connection, signed in.'];
        $on = ['name' => 'post', 'label' => 'Post', 'type' => 'template', 'required' => true, 'placeholder' => '{{ node("post").id }}', 'help' => 'Its id, as "Post on a Facebook Page" or "Facebook: the Page\'s posts" gives it.'];
        $either = [...$on, 'label' => 'Post or comment', 'help' => 'The id of a post, or of a comment under one.'];
        $count = fn (int $usual) => ['name' => 'count', 'label' => 'How many', 'type' => 'number', 'required' => false, 'default' => $usual, 'help' => 'The newest first; at most 100.'];

        return [
            self::kind('facebook.post', Steps\FacebookPost::class, 'Post on a Facebook Page', 'megaphone', 'Posts as a Facebook Page: words, alone or with a link shown as a card, a picture or several, or a video. Facebook lets an app post as a Page, never on a person\'s own profile. Gives out the post\'s id and its address as url.', [
                $page,
                ['name' => 'text', 'label' => 'Words', 'type' => 'template', 'required' => false],
                ['name' => 'with', 'label' => 'With', 'type' => 'choice', 'required' => true, 'default' => 'none', 'options' => ['none' => 'Nothing else', 'link' => 'A link, shown as a card', 'picture' => 'A picture from the Drive', 'pictures' => 'Several pictures from the Drive', 'video' => 'A video from the Drive']],
                ['name' => 'link', 'label' => 'Link', 'type' => 'template', 'required' => false, 'placeholder' => 'https://example.com/news/{{ trigger.id }}', 'when' => ['with' => 'link']],
                ['name' => 'picture', 'label' => 'Picture', 'type' => 'template', 'required' => false, 'placeholder' => '{{ node("save").ref_id }}', 'drive' => 'image', 'help' => 'A picture in the Drive, by its ref_id.', 'when' => ['with' => 'picture']],
                ['name' => 'pictures', 'label' => 'Pictures', 'type' => 'template', 'required' => false, 'placeholder' => '{{ node("find").files }}', 'help' => 'From 2 to 10 in the Drive: a list of them, or their ref_ids with commas between.', 'when' => ['with' => 'pictures']],
                ['name' => 'video', 'label' => 'Video', 'type' => 'template', 'required' => false, 'placeholder' => '{{ node("save").ref_id }}', 'drive' => 'video', 'help' => 'A video in the Drive, by its ref_id.', 'when' => ['with' => 'video']],
                ['name' => 'title', 'label' => 'Its title', 'type' => 'template', 'required' => false, 'when' => ['with' => 'video']],
            ], sends: true),
            self::kind('facebook.comment', Steps\FacebookCall::class, 'Comment on Facebook', 'message-square', 'Writes a comment as the Page: under one of its posts, or in answer to a comment there. Gives out the comment\'s id.', [
                $page,
                $either,
                ['name' => 'text', 'label' => 'Comment', 'type' => 'template', 'required' => true],
            ], sends: true),
            self::kind('facebook.like', Steps\FacebookCall::class, 'Like on Facebook', 'thumbs-up', 'Likes a post or a comment, as the Page.', [$page, $either]),
            self::kind('facebook.unlike', Steps\FacebookCall::class, 'Take back a like on Facebook', 'thumbs-down', 'Takes the Page\'s like off a post or a comment.', [$page, $either]),
            self::kind('facebook.delete', Steps\FacebookCall::class, 'Delete on Facebook', 'trash-2', 'Deletes a post of the Page\'s, or a comment under one, for good.', [$page, $either]),
            self::kind('facebook.posts', Steps\FacebookCall::class, 'Facebook: the Page\'s posts', 'list', 'Asks Facebook for the Page\'s newest posts: gives out posts, each with its id, text, at and url, and the newest alone as first.', [$page, $count(10)]),
            self::kind('facebook.comments', Steps\FacebookCall::class, 'Facebook: a post\'s comments', 'scroll-text', 'Asks Facebook for the comments under a post of the Page\'s, the newest first: gives out comments, each with its id, text, at and from, and how many as count.', [$page, $on, $count(25)]),
            self::kind('facebook.me', Steps\FacebookCall::class, 'Facebook: the Page', 'badge-check', 'Asks Facebook about the Page the connection is signed in as. Gives out id, name, url, likes, followers and picture.', [$page]),
        ];
    }

    // --- Instagram ---

    /**
     * @return list<NodeType>
     */
    private static function instagram(): array
    {
        $account = ['name' => 'connection', 'label' => 'As', 'type' => 'connection', 'required' => true, 'services' => ['instagram'], 'help' => 'An Instagram connection, signed in.'];
        $post = ['name' => 'post', 'label' => 'Post', 'type' => 'template', 'required' => true, 'placeholder' => '{{ node("post").id }}', 'help' => 'Its id, as "Post on Instagram" or "Instagram: my posts" gives it -- not its link.'];
        $comment = ['name' => 'comment', 'label' => 'Comment', 'type' => 'template', 'required' => true, 'placeholder' => '{{ item(node("comments").comments, 1).id }}', 'help' => 'Its id, as a step gives it.'];
        $fetched = 'Instagram fetches it from the app, so the app needs its public address.';
        $chats = [...$account, 'linked' => true, 'help' => 'An Instagram connection, signed in with Instagram and allowed its messages.'];
        $to = ['name' => 'to', 'label' => 'To', 'type' => 'template', 'required' => true, 'default' => '{{ trigger.chat.id }}', 'help' => 'Who, by the id Instagram gives the account for them, as a message from them carries it -- not their @name.'];
        $window = 'Only within 24 hours of their last message.';

        return [
            self::kind('instagram.post', Steps\InstagramPost::class, 'Post on Instagram', 'megaphone', 'Posts as an Instagram professional account: a picture, several to swipe through, a reel, or a story. Gives out the post\'s id and its address as url. Taking one down again is "Delete a post on Instagram".', [
                $account,
                ['name' => 'what', 'label' => 'What', 'type' => 'choice', 'required' => true, 'default' => 'picture', 'options' => ['picture' => 'A picture', 'several' => 'Several pictures or videos, to swipe through', 'reel' => 'A reel', 'story' => 'A story']],
                ['name' => 'picture', 'label' => 'Picture', 'type' => 'template', 'required' => false, 'placeholder' => '{{ node("save").ref_id }}', 'drive' => 'image', 'help' => 'A JPEG in the Drive, by its ref_id. '.$fetched, 'when' => ['what' => 'picture']],
                ['name' => 'files', 'label' => 'Pictures and videos', 'type' => 'template', 'required' => false, 'placeholder' => '{{ node("find").files }}', 'help' => 'From 2 to 10 in the Drive, each a JPEG or an MP4: a list of them, or their ref_ids with commas between. '.$fetched, 'when' => ['what' => 'several']],
                ['name' => 'video', 'label' => 'Video', 'type' => 'template', 'required' => false, 'placeholder' => '{{ node("save").ref_id }}', 'drive' => 'video', 'help' => 'An MP4 or a MOV in the Drive, by its ref_id. '.$fetched, 'when' => ['what' => 'reel']],
                ['name' => 'story', 'label' => 'Picture or video', 'type' => 'template', 'required' => false, 'placeholder' => '{{ node("save").ref_id }}', 'help' => 'A JPEG, an MP4 or a MOV in the Drive, by its ref_id. '.$fetched, 'when' => ['what' => 'story']],
                ['name' => 'caption', 'label' => 'Caption', 'type' => 'template', 'required' => false, 'help' => 'At most 2,200 letters.', 'when' => ['what' => ['picture', 'several', 'reel']]],
            ], sends: true),
            self::kind('instagram.delete', Steps\InstagramCall::class, 'Delete a post on Instagram', 'trash-2', 'Takes one of the account\'s posts, reels or stories down for good -- several to swipe through go as the one post they are. Instagram lets an app do this only for a connection signed in with Facebook.', [
                ['name' => 'connection', 'label' => 'As', 'type' => 'connection', 'required' => true, 'services' => ['instagram'], 'help' => 'An Instagram connection, signed in with Facebook.'],
                $post,
            ]),
            self::kind('instagram.comment', Steps\InstagramCall::class, 'Comment on Instagram', 'message-square', 'Writes a comment under one of the account\'s posts, as the account. Gives out the comment\'s id.', [
                $account,
                $post,
                ['name' => 'text', 'label' => 'Comment', 'type' => 'template', 'required' => true],
            ], sends: true),
            self::kind('instagram.reply', Steps\InstagramCall::class, 'Answer a comment on Instagram', 'reply', 'Answers a comment under one of the account\'s posts, as the account. Gives out the answer\'s id.', [
                $account,
                $comment,
                ['name' => 'text', 'label' => 'Answer', 'type' => 'template', 'required' => true],
            ], sends: true),
            self::kind('instagram.comment_delete', Steps\InstagramCall::class, 'Delete a comment on Instagram', 'trash-2', 'Takes away a comment under one of the account\'s posts: its own, or someone else\'s.', [$account, $comment]),
            self::kind('instagram.posts', Steps\InstagramCall::class, 'Instagram: my posts', 'list', 'Asks Instagram for the account\'s newest posts: gives out posts, each with its id, text, kind, at, url, likes and comments, and the newest alone as first.', [
                $account,
                ['name' => 'count', 'label' => 'How many', 'type' => 'number', 'required' => false, 'default' => 10, 'help' => 'The newest first; at most 100.'],
            ]),
            self::kind('instagram.comments', Steps\InstagramCall::class, 'Instagram: a post\'s comments', 'scroll-text', 'Asks Instagram for the comments under one of the account\'s posts: gives out comments, each with its id, text, at, from and likes, and how many as count.', [
                $account,
                $post,
                ['name' => 'count', 'label' => 'How many', 'type' => 'number', 'required' => false, 'default' => 25, 'help' => 'At most 100.'],
            ]),
            self::kind('instagram.send', Steps\InstagramChat::class, 'Send on Instagram', 'send', 'Sends words from the account to someone who wrote to it, with quick answers to tap under them when any are named. Gives out the message\'s id. '.$window, [
                $chats,
                $to,
                ['name' => 'text', 'label' => 'Message', 'type' => 'template', 'required' => true, 'help' => 'At most 1,000 bytes: about that many letters in English, a third as many in Thai.'],
                ['name' => 'buttons', 'label' => 'Quick answers', 'type' => 'fields', 'required' => false, 'default' => [], 'labels' => true, 'help' => 'Up to 13, each at most 20 letters: what it says, and what a tap sends back as trigger.text -- its own words when left empty. They are gone once the chat moves on.'],
            ], sends: true),
            self::kind('instagram.send_file', Steps\InstagramChat::class, 'Send a file on Instagram', 'file-up', 'Sends a picture (JPEG, PNG or GIF, at most 8 MB), a video or a recording (25 MB) from the Drive to someone who wrote to the account. '.$fetched.' '.$window, [
                $chats,
                $to,
                ['name' => 'file', 'label' => 'File', 'type' => 'template', 'required' => true, 'placeholder' => '{{ node("save").ref_id }}', 'help' => 'A file in the Drive, by its ref_id.'],
            ], sends: true),
            self::kind('instagram.message_commenter', Steps\InstagramChat::class, 'Message a commenter on Instagram', 'reply', 'Sends a message to whoever wrote a comment under one of the account\'s posts: the one thing that reaches someone who has not written to it. Once for each comment, within seven days of it; more only after they answer.', [
                $chats,
                ['name' => 'comment', 'label' => 'Comment', 'type' => 'template', 'required' => true, 'default' => '{{ trigger.comment.id }}', 'help' => 'Its id, as "Instagram comment" gives it.'],
                ['name' => 'text', 'label' => 'Message', 'type' => 'template', 'required' => true],
            ], sends: true),
            self::kind('instagram.react', Steps\InstagramChat::class, 'React on Instagram', 'smile', 'Puts a heart on a message someone sent the account, or takes it off again.', [
                $chats,
                $to,
                ['name' => 'message', 'label' => 'On message', 'type' => 'template', 'required' => true, 'default' => '{{ trigger.reply.to }}', 'help' => 'The message\'s id.'],
                ['name' => 'reaction', 'label' => 'Reaction', 'type' => 'choice', 'required' => true, 'default' => 'love', 'options' => ['love' => 'A heart', 'none' => 'None: take it off']],
            ]),
            self::kind('instagram.profile', Steps\InstagramChat::class, 'Instagram: who wrote', 'user-search', 'Asks Instagram about someone who wrote to the account: a message carries only their id. Gives out id, username, name, picture, followers, verified, and whether they follow the account (follows) and it them (followed).', [
                $chats,
                ['name' => 'user', 'label' => 'Who', 'type' => 'template', 'required' => true, 'default' => '{{ trigger.sender.id }}', 'help' => 'Their id, as a message from them gives it.'],
            ]),
            self::kind('instagram.me', Steps\InstagramCall::class, 'Instagram: who I am', 'badge-check', 'Asks Instagram about the account the connection is signed in as. Gives out id, username, name, kind, picture, followers, following and posts.', [$account]),
        ];
    }

    // --- Threads ---

    /**
     * @return list<NodeType>
     */
    private static function threads(): array
    {
        $profile = ['name' => 'connection', 'label' => 'As', 'type' => 'connection', 'required' => true, 'services' => ['threads'], 'help' => 'A Threads connection, signed in.'];
        $post = ['name' => 'post', 'label' => 'Post', 'type' => 'template', 'required' => true, 'placeholder' => '{{ node("post").id }}', 'help' => 'Its id, as "Post on Threads" or "Threads: my posts" gives it -- not its link.'];
        $fetched = 'Threads fetches it from the app, so the app needs its public address.';

        return [
            self::kind('threads.post', Steps\ThreadsPost::class, 'Post on Threads', 'megaphone', 'Posts on a Threads profile: words (at most 500 letters), alone or with a link, a picture, a video, or several to swipe through -- or, told which post it answers, as a reply under it. Gives out the post\'s id and its address as url.', [
                $profile,
                ['name' => 'text', 'label' => 'Words', 'type' => 'template', 'required' => false, 'help' => 'At most 500 letters.'],
                ['name' => 'with', 'label' => 'With', 'type' => 'choice', 'required' => true, 'default' => 'none', 'options' => ['none' => 'Nothing else', 'link' => 'A link, shown as a card', 'picture' => 'A picture from the Drive', 'video' => 'A video from the Drive', 'several' => 'Several pictures or videos']],
                ['name' => 'link', 'label' => 'Link', 'type' => 'template', 'required' => false, 'placeholder' => 'https://example.com/news/{{ trigger.id }}', 'when' => ['with' => 'link']],
                ['name' => 'picture', 'label' => 'Picture', 'type' => 'template', 'required' => false, 'placeholder' => '{{ node("save").ref_id }}', 'drive' => 'image', 'help' => 'A JPEG or a PNG in the Drive, by its ref_id. '.$fetched, 'when' => ['with' => 'picture']],
                ['name' => 'video', 'label' => 'Video', 'type' => 'template', 'required' => false, 'placeholder' => '{{ node("save").ref_id }}', 'drive' => 'video', 'help' => 'An MP4 or a MOV in the Drive, by its ref_id. '.$fetched, 'when' => ['with' => 'video']],
                ['name' => 'files', 'label' => 'Pictures and videos', 'type' => 'template', 'required' => false, 'placeholder' => '{{ node("find").files }}', 'help' => 'From 2 to 20 in the Drive: a list of them, or their ref_ids with commas between. '.$fetched, 'when' => ['with' => 'several']],
                ['name' => 'reply_to', 'label' => 'In answer to', 'type' => 'template', 'required' => false, 'placeholder' => '{{ node("post").id }}', 'help' => 'A post\'s id: this is then a reply under it. Left empty, a post of its own.'],
            ], sends: true),
            self::kind('threads.repost', Steps\ThreadsCall::class, 'Repost on Threads', 'repeat', 'Shares a post again on the profile, as it is. Gives out the repost\'s id.', [$profile, $post], sends: true),
            self::kind('threads.delete', Steps\ThreadsCall::class, 'Delete a post on Threads', 'trash-2', 'Deletes one of the profile\'s own posts or replies for good. Threads lets an app delete 100 a day.', [$profile, $post]),
            self::kind('threads.posts', Steps\ThreadsCall::class, 'Threads: my posts', 'list', 'Asks Threads for the profile\'s newest posts: gives out posts, each with its id, text, from, at and url, and the newest alone as first.', [
                $profile,
                ['name' => 'count', 'label' => 'How many', 'type' => 'number', 'required' => false, 'default' => 10, 'help' => 'The newest first; at most 100.'],
            ]),
            self::kind('threads.replies', Steps\ThreadsCall::class, 'Threads: a post\'s replies', 'scroll-text', 'Asks Threads for the replies under one of the profile\'s posts: gives out replies, each with its id, text, from, at and url, and how many as count.', [
                $profile,
                $post,
                ['name' => 'count', 'label' => 'How many', 'type' => 'number', 'required' => false, 'default' => 25, 'help' => 'At most 100.'],
            ]),
            self::kind('threads.me', Steps\ThreadsCall::class, 'Threads: who I am', 'badge-check', 'Asks Threads about the profile the connection is signed in as. Gives out id, username, name, picture and about.', [$profile]),
        ];
    }

    /**
     * One kind: in its service's group, with one way in and one out. One
     * that sends is not simply made again, and says what happens instead.
     *
     * @param  list<Setting>  $settings
     * @param  class-string<Steps\Step>  $runs
     * @return NodeType
     */
    private static function kind(string $type, string $runs, string $label, string $icon, string $description, array $settings, bool $sends = false, ?string $unsureHelp = null): array
    {
        return [
            'type' => $type,
            'runs' => $runs,
            'version' => 1,
            'label' => $label,
            'group' => explode('.', $type)[0],
            'icon' => $icon,
            'description' => $description,
            'trigger' => false,
            ...$sends ? ['repeatable' => false] : [],
            'inputs' => [NodeTypes::IN],
            'outputs' => [NodeTypes::OUT],
            'config' => [
                ...array_map(fn (array $setting) => array_filter($setting, fn ($part) => $part !== null), $settings),
                ...$sends ? [[...self::UNSURE, ...$unsureHelp === null ? [] : ['help' => $unsureHelp]]] : [],
            ],
        ];
    }
}
