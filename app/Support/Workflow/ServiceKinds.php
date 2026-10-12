<?php

namespace App\Support\Workflow;

/**
 * The kinds of step that are one call to a service each -- SerpApi, Tavily,
 * Telegram, LINE, WhatsApp, LinkedIn, Facebook, Instagram, Threads -- beside the ones
 * NodeTypes lists itself: every call a service offers is a step of its own,
 * in that service's group. Run by that service's ...Call class.
 *
 * @phpstan-import-type Setting from NodeTypes
 * @phpstan-import-type NodeType from NodeTypes
 */
final class ServiceKinds
{
    /** What a step that may have sent something does when it can't tell. */
    private const UNSURE = [
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
        return [...self::serpapi(), ...self::tavily(), ...self::telegram(), ...self::line(), ...self::whatsapp(), ...self::linkedin(), ...self::facebook(), ...self::instagram(), ...self::threads()];
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
            self::kind('serpapi.google', 'Search Google', 'search', 'Searches Google, through SerpApi. Gives out results -- each with its title, link, snippet, source and date -- with Google\'s own answer when it gives one, what it knows of the thing searched for as knowledge, questions people also ask, related searches, and text: the results as words for a model to read.'.$costs, [
                $key,
                $for,
                ['name' => 'location', 'label' => 'From', 'type' => 'template', 'required' => false, 'placeholder' => 'Bangkok, Thailand', 'help' => 'Where the search is made from: a place as "SerpApi: find a place" names it. Left empty, wherever SerpApi asks from.'],
                $country,
                $language,
                ['name' => 'period', 'label' => 'From the last', 'type' => 'choice', 'required' => true, 'default' => 'any', 'options' => ['any' => 'Any time', 'hour' => 'Hour', 'day' => 'Day', 'week' => 'Week', 'month' => 'Month', 'year' => 'Year']],
                [...$page, 'help' => 'Ten results to a page.'],
                $more,
            ]),
            self::kind('serpapi.news', 'Search Google News', 'newspaper', 'Searches Google News, through SerpApi. Gives out results -- each with its title, link, source, date and thumbnail -- and text: them as words for a model to read.'.$costs, [
                $key,
                [...$for, 'required' => false, 'help' => 'Left empty, the top stories. As Google News reads it: site:bbc.com, when:7d.'],
                $country,
                $language,
                ['name' => 'sort', 'label' => 'First', 'type' => 'choice', 'required' => true, 'default' => 'relevance', 'options' => ['relevance' => 'What fits best', 'date' => 'The newest']],
                $most,
                [...$more, 'help' => 'Anything else the engine takes, named as its page on serpapi.com names it: a topic_token, a publication_token.'],
            ]),
            self::kind('serpapi.images', 'Search Google Images', 'image', 'Searches Google Images, through SerpApi. Gives out results: each with its title, the picture\'s own address as image, its width and height, a thumbnail, and the link and source of the page it is on. "Save to Drive" keeps one.'.$costs, [
                $key,
                $for,
                $country,
                $language,
                ['name' => 'kind', 'label' => 'Kind', 'type' => 'choice', 'required' => true, 'default' => 'any', 'options' => ['any' => 'Any', 'photo' => 'Photos', 'face' => 'Faces', 'clipart' => 'Clip art', 'lineart' => 'Line drawings', 'animated' => 'Animated']],
                $most,
                [...$page, 'help' => 'A hundred pictures to a page.'],
                [...$more, 'help' => 'Anything else the engine takes, named as its page on serpapi.com names it: imgsz l, licenses fc, safe active.'],
            ]),
            self::kind('serpapi.maps', 'Search Google Maps', 'map-pinned', 'Searches Google Maps for places, through SerpApi. Gives out results: each with its title, address, lat and lng, rating, reviews, type, price, phone, website and whether it is open -- a result as it is can go with a chat message as its place.'.$costs, [
                $key,
                [...$for, 'placeholder' => 'coffee near Ari'],
                ['name' => 'around', 'label' => 'Around', 'type' => 'template', 'required' => false, 'placeholder' => '{{ trigger.location }}', 'help' => 'The place searched around: its latitude and longitude, like 13.7563, 100.5018, or what a message that sent a place gives. Left empty, Google goes by the words.'],
                ['name' => 'zoom', 'label' => 'How close', 'type' => 'number', 'required' => false, 'default' => 14, 'help' => 'From 3, the whole world, to 21, one street. Only with a place to search around.', 'when' => ['around' => true]],
                $language,
                [...$page, 'help' => 'Twenty places to a page. A later page needs a place to search around.'],
                [...$more, 'help' => 'Anything else the engine takes, named as its page on serpapi.com names it: open_state now, min_rating 4.'],
            ]),
            self::kind('serpapi.youtube', 'Search YouTube', 'square-play', 'Searches YouTube, through SerpApi. Gives out results -- each with its title, link, id, channel, when it was published, views, length, description and thumbnail -- and text: them as words for a model to read.'.$costs, [
                $key,
                $for,
                $country,
                $language,
                $most,
                [...$more, 'help' => 'Anything else the engine takes, named as its page on serpapi.com names it: sp, YouTube\'s own mark for a filter or a later page.'],
            ]),
            self::kind('serpapi.search', 'SerpApi: any engine', 'text-search', 'Asks any other engine SerpApi has -- Google Shopping, Scholar, Trends, Flights, Hotels, Bing, DuckDuckGo, Amazon and the rest -- and gives out its answer whole, as body: each engine has its own things to ask and its own shape of answer, set out on its page on serpapi.com.'.$costs, [
                $key,
                ['name' => 'engine', 'label' => 'Engine', 'type' => 'template', 'required' => true, 'placeholder' => 'google_shopping', 'help' => 'Its name as SerpApi writes it: google_shopping, google_scholar, google_trends, bing, duckduckgo, amazon.'],
                ['name' => 'params', 'label' => 'What it is asked', 'type' => 'fields', 'required' => false, 'default' => [], 'help' => 'Each named as the engine\'s page names it: q running shoes, gl th. A step gives out at most 256 KB: json_restrictor keeps only the parts named, like shopping_results[].{title,price,link}.'],
            ]),
            self::kind('serpapi.locations', 'SerpApi: find a place', 'locate', 'Finds the places a search can be made from, by part of a name. Gives out places -- each with its name as a search takes it, country, kind, lat and lng -- and name, the first of them. Free, and asks with no key.', [
                ['name' => 'q', 'label' => 'Place', 'type' => 'template', 'required' => true, 'placeholder' => 'Chiang Mai'],
                ['name' => 'count', 'label' => 'At most', 'type' => 'number', 'required' => false, 'default' => 5, 'help' => 'At most 10.'],
            ]),
            self::kind('serpapi.account', 'SerpApi: searches left', 'gauge', 'Asks SerpApi about the key\'s plan. Gives out plan, left, used and per_month -- searches this month -- with this_hour and per_hour, and when it renews. Free.', [$key]),
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
            self::kind('tavily.search', 'Search with Tavily', 'search', 'Searches the web with Tavily, which reads what it finds and gives the part that answers. Gives out results -- each with its title, url, content and score -- an answer written from them when asked for one, and text: the results as words for a model to read. One credit, two when thorough.', [
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
            self::kind('tavily.extract', 'Tavily: read pages', 'book-open-text', 'Reads pages whole, by their addresses: up to 20 at once. Gives out text -- one page\'s words, or every page\'s each under its address -- results, each with its url and text, and failed, the ones that could not be read and why. A credit for every five pages.'.$long, [
                $key,
                ['name' => 'urls', 'label' => 'Pages', 'type' => 'template', 'required' => true, 'placeholder' => '{{ item(node("search").results, 1).url }}', 'help' => 'Their addresses: one, several with commas between or one on a line, or a list.'],
                $depth,
                $format,
                ['name' => 'about', 'label' => 'Only what bears on', 'type' => 'template', 'required' => false, 'placeholder' => '{{ trigger.text }}', 'help' => 'Left empty, the whole page. With words here, the parts of each page that fit them best.'],
                $more,
            ]),
            self::kind('tavily.crawl', 'Tavily: crawl a site', 'network', 'Follows a site\'s links from a page and reads what it finds. Gives out results -- each page with its url and text -- count, and text: every page\'s words, each under its address. Credits by the page.'.$long, [
                $key,
                ...$site(20, 100),
                $depth,
                $format,
                $more,
            ]),
            self::kind('tavily.map', 'Tavily: list a site\'s pages', 'list-tree', 'Follows a site\'s links from a page and lists what it finds, without reading it. Gives out urls and count. A credit for every ten pages.', [
                $key,
                ...$site(50, 500),
                $more,
            ]),
            self::kind('tavily.usage', 'Tavily: credits used', 'gauge', 'Asks Tavily what the key has used. Gives out used and limit for the key, left, and the plan with plan_used and plan_limit. Free.', [$key]),
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
            self::kind('telegram.album', 'Send an album on Telegram', 'images', 'Sends from 2 to 10 pictures and videos from the Drive as one message.', [
                $bot, $to, $topic,
                ['name' => 'files', 'label' => 'Files', 'type' => 'template', 'required' => true, 'placeholder' => '{{ node("find").files }}', 'help' => 'Files in the Drive: a list of them, or their ref_ids with commas between.'],
                ['name' => 'text', 'label' => 'Words under it', 'type' => 'template', 'required' => false],
            ], sends: true),
            self::kind('telegram.sticker', 'Send a sticker on Telegram', 'sticker', 'Sends a sticker to a chat.', [
                $bot, $to, $topic,
                ['name' => 'sticker', 'label' => 'Sticker', 'type' => 'template', 'required' => true, 'placeholder' => '{{ trigger.sticker.id }}', 'help' => 'A sticker\'s id, as a message that carried one gives it, or the address of a .webp picture.'],
            ], sends: true),
            self::kind('telegram.location', 'Send a location on Telegram', 'map-pin', 'Sends a place to a chat: a pin on the map, or with a name, a venue.', [
                $bot, $to, $topic,
                ['name' => 'lat', 'label' => 'Latitude', 'type' => 'template', 'required' => true, 'placeholder' => '{{ trigger.location.lat }}'],
                ['name' => 'lng', 'label' => 'Longitude', 'type' => 'template', 'required' => true, 'placeholder' => '{{ trigger.location.lng }}'],
                ['name' => 'title', 'label' => 'Name', 'type' => 'template', 'required' => false, 'help' => 'With a name it is sent as a venue.'],
                ['name' => 'address', 'label' => 'Address', 'type' => 'template', 'required' => false, 'when' => ['title' => true]],
            ], sends: true),
            self::kind('telegram.contact', 'Send a contact on Telegram', 'contact', 'Sends someone\'s name and phone number to a chat, to save or call.', [
                $bot, $to, $topic,
                ['name' => 'phone', 'label' => 'Phone number', 'type' => 'template', 'required' => true],
                ['name' => 'first_name', 'label' => 'First name', 'type' => 'template', 'required' => true],
                ['name' => 'last_name', 'label' => 'Last name', 'type' => 'template', 'required' => false],
            ], sends: true),
            self::kind('telegram.poll', 'Send a poll on Telegram', 'chart-bar', 'Asks a chat a question with answers to choose from. A "Telegram message" trigger set to poll answers hears who chose what, when the poll is not anonymous.', [
                $bot, $to, $topic,
                ['name' => 'question', 'label' => 'Question', 'type' => 'template', 'required' => true],
                ['name' => 'options', 'label' => 'Answers', 'type' => 'template', 'required' => true, 'placeholder' => "Yes\nNo\nMaybe", 'help' => 'One on each line, from 2 to 12.'],
                ['name' => 'anonymous', 'label' => 'Who chose what', 'type' => 'choice', 'required' => true, 'default' => 'yes', 'options' => ['yes' => 'Is kept secret', 'no' => 'Can be seen']],
                ['name' => 'several', 'label' => 'Choosing', 'type' => 'choice', 'required' => true, 'default' => 'no', 'options' => ['no' => 'One answer', 'yes' => 'Several answers']],
            ], sends: true),
            self::kind('telegram.forward', 'Forward a message on Telegram', 'forward', 'Forwards a message from one chat to another, marked as forwarded.', [
                $bot,
                ['name' => 'from', 'label' => 'From chat', 'type' => 'template', 'required' => true, 'default' => '{{ trigger.chat.id }}'],
                $message,
                [...$to, 'default' => null, 'placeholder' => '-1001234567890'],
                $topic,
            ], sends: true),
            self::kind('telegram.copy', 'Copy a message on Telegram', 'copy', 'Sends a copy of a message to another chat, as the bot\'s own: no mark of where it came from.', [
                $bot,
                ['name' => 'from', 'label' => 'From chat', 'type' => 'template', 'required' => true, 'default' => '{{ trigger.chat.id }}'],
                $message,
                [...$to, 'default' => null, 'placeholder' => '-1001234567890'],
                $topic,
            ], sends: true),
            self::kind('telegram.invoice', 'Send an invoice on Telegram', 'credit-card', 'Asks a chat to pay for something: in Telegram Stars, or in money through a payment provider. Telegram then asks the bot to agree ("Telegram message" set to payments; "Answer a checkout on Telegram"), and says when it is paid.', [
                $bot, $to,
                ['name' => 'title', 'label' => 'For', 'type' => 'template', 'required' => true, 'placeholder' => 'Coffee beans, 250 g'],
                ['name' => 'description', 'label' => 'Description', 'type' => 'template', 'required' => false],
                ['name' => 'amount', 'label' => 'Amount', 'type' => 'template', 'required' => true, 'help' => 'A whole number: Stars, or for money its smallest unit -- 1050 for 10.50.'],
                ['name' => 'currency', 'label' => 'In', 'type' => 'template', 'required' => true, 'default' => 'XTR', 'help' => 'XTR is Telegram Stars. Money (USD, THB…) needs a payment provider.'],
                ['name' => 'provider', 'label' => 'Payment provider\'s token', 'type' => 'connection', 'required' => false, 'services' => ['webhook_secret'], 'help' => 'For money: the token @BotFather gives for a provider, kept as a "Webhook secret" connection. Not needed for Stars.'],
                ['name' => 'payload', 'label' => 'Your own reference', 'type' => 'template', 'required' => false, 'placeholder' => 'order-{{ trigger.id }}', 'help' => 'Comes back with the payment, as trigger.payment.payload. Never shown.'],
            ], sends: true),
            self::kind('telegram.topic', 'Make a topic on Telegram', 'message-square-dashed', 'Makes a topic in a group that has topics, and gives out its id as topic, for a later step\'s "In topic".', [
                $bot, $chat,
                ['name' => 'name', 'label' => 'Called', 'type' => 'template', 'required' => true],
            ], sends: true),

            // Changed: what is there, set to what the step says
            self::kind('telegram.action', 'Show typing on Telegram', 'ellipsis', 'Shows the chat that the bot is busy -- "typing…" -- for a few seconds, or until it sends something: before a step that takes a while, like asking an AI.', [
                $bot, $chat, $topic,
                ['name' => 'action', 'label' => 'Busy', 'type' => 'choice', 'required' => true, 'default' => 'typing', 'options' => [
                    'typing' => 'Typing', 'upload_photo' => 'Sending a picture', 'upload_document' => 'Sending a file', 'upload_video' => 'Sending a video',
                    'record_voice' => 'Recording', 'choose_sticker' => 'Choosing a sticker', 'find_location' => 'Finding a place',
                ]],
            ]),
            self::kind('telegram.edit', 'Edit a message on Telegram', 'pencil', 'Changes what a message the bot sent says, in place -- and its buttons: the ones named here, or none.', [
                $bot, $chat,
                [...$message, 'default' => null, 'placeholder' => '{{ node("send").message_id }}', 'help' => 'The id of a message the bot sent: {{ node("send").message_id }}; after a press, the message pressed is {{ trigger.reply.to }}.'],
                ['name' => 'text', 'label' => 'Now says', 'type' => 'template', 'required' => true],
                $format,
                ...self::telegramButtons('Left empty, the message\'s buttons are taken off. Each is what the button says, and what pressing it does.'),
            ]),
            self::kind('telegram.edit_buttons', 'Change a message\'s buttons on Telegram', 'square-mouse-pointer', 'Puts other buttons under a message the bot sent, or takes them off -- once one has been pressed, say -- leaving its words as they are.', [
                $bot, $chat,
                [...$message, 'help' => 'The id of a message the bot sent; after a press, the message pressed is {{ trigger.reply.to }}.'],
                ...self::telegramButtons('Left empty, the buttons are taken off. Each is what the button says, and what pressing it does.'),
            ]),
            self::kind('telegram.delete', 'Delete a message on Telegram', 'trash-2', 'Takes a message out of a chat: the bot\'s own, or in a group it looks after, anyone\'s. Only within two days of it.', [$bot, $chat, $message]),
            self::kind('telegram.pin', 'Pin a message on Telegram', 'pin', 'Pins a message at the top of a chat. In a group, the bot has to be allowed to.', [
                $bot, $chat, $message,
                ['name' => 'notify', 'label' => 'Tell everyone', 'type' => 'choice', 'required' => true, 'default' => 'no', 'options' => ['no' => 'Quietly', 'yes' => 'With a notification']],
            ]),
            self::kind('telegram.unpin', 'Unpin a message on Telegram', 'pin-off', 'Takes a pinned message down from the top of a chat.', [
                $bot, $chat,
                [...$message, 'required' => false, 'default' => null, 'help' => 'Its id. Left empty, the one pinned last.'],
            ]),
            self::kind('telegram.react', 'React to a message on Telegram', 'smile', 'Puts an emoji on a message, as the bot\'s reaction -- seen at once, where an answer would take longer.', [
                $bot, $chat, $message,
                ['name' => 'emoji', 'label' => 'Emoji', 'type' => 'template', 'required' => false, 'default' => '👍', 'help' => 'One of the emoji Telegram lets a reaction be. Left empty, the bot\'s reaction is taken off.'],
            ]),
            self::kind('telegram.answer_button', 'Answer a button press on Telegram', 'mouse-pointer-click', 'Tells Telegram a press was heard, so the button stops waiting -- with a few words shown to whoever pressed, if any.', [
                $bot,
                ['name' => 'press', 'label' => 'The press', 'type' => 'template', 'required' => true, 'default' => '{{ trigger.button.id }}'],
                ['name' => 'text', 'label' => 'Say', 'type' => 'template', 'required' => false, 'placeholder' => 'Saved!'],
                ['name' => 'show', 'label' => 'Shown', 'type' => 'choice', 'required' => true, 'default' => 'toast', 'options' => ['toast' => 'Briefly, at the top', 'alert' => 'In a box to close'], 'when' => ['text' => true]],
            ]),
            self::kind('telegram.commands', 'Set the bot\'s commands on Telegram', 'slash', 'Sets the list a chat offers when "/" is typed: each command, and what it does.', [
                $bot,
                ['name' => 'commands', 'label' => 'Commands', 'type' => 'fields', 'required' => true, 'default' => [], 'help' => 'Each named without its "/", in lowercase letters, digits and _: expense, and what it does: "Note an expense". None at all clears the list.'],
            ]),
            self::kind('telegram.ban', 'Ban someone on Telegram', 'ban', 'Puts someone out of a group the bot looks after, and keeps them out.', [$bot, $chat, $user, $until]),
            self::kind('telegram.unban', 'Lift a ban on Telegram', 'user-check', 'Lets someone banned from a group come back; they are not added, only allowed.', [$bot, $chat, $user]),
            self::kind('telegram.restrict', 'Limit someone on Telegram', 'shield', 'Sets what someone may do in a group the bot looks after.', [
                $bot, $chat, $user,
                ['name' => 'allow', 'label' => 'They may', 'type' => 'choice', 'required' => true, 'default' => 'nothing', 'options' => ['nothing' => 'Only read', 'messages' => 'Write, but send nothing else', 'everything' => 'Do everything again']],
                $until,
            ]),
            self::kind('telegram.answer_inline', 'Answer an inline question on Telegram', 'at-sign', 'Answers what someone typed after the bot\'s @name in any chat, with results to choose from. Starts from a "Telegram message" trigger set to inline questions; the bot needs inline mode switched on in @BotFather.', [
                $bot,
                ['name' => 'question', 'label' => 'The question', 'type' => 'template', 'required' => true, 'default' => '{{ trigger.inline.id }}'],
                ['name' => 'results', 'label' => 'Results', 'type' => 'fields', 'required' => true, 'default' => [], 'labels' => true, 'help' => 'Each is a result\'s title, and the message that choosing it sends. What was typed is {{ trigger.text }}.'],
            ]),
            self::kind('telegram.answer_checkout', 'Answer a checkout on Telegram', 'badge-check', 'Agrees to a payment about to be made, or refuses it. Telegram waits ten seconds for this, so put it first after the trigger.', [
                $bot,
                ['name' => 'checkout', 'label' => 'The checkout', 'type' => 'template', 'required' => true, 'default' => '{{ trigger.checkout.id }}'],
                ['name' => 'answer', 'label' => 'Answer', 'type' => 'choice', 'required' => true, 'default' => 'ok', 'options' => ['ok' => 'Go ahead', 'refuse' => 'Refuse it']],
                ['name' => 'why', 'label' => 'Why not', 'type' => 'template', 'required' => false, 'placeholder' => 'Sorry, the last one just went.', 'when' => ['answer' => 'refuse']],
            ]),

            // Asked
            self::kind('telegram.chat', 'Telegram: about a chat', 'info', 'Asks Telegram about a chat: gives out its id, kind, title, username, description, whether it has topics, and its photo -- the photo of whoever a one-to-one chat is with -- as a file "Telegram: get the file" fetches.', [$bot, $chat]),
            self::kind('telegram.member', 'Telegram: about a member', 'user-search', 'Asks Telegram how someone stands in a chat: gives out their name, their status, and whether they are in it and look after it.', [$bot, $chat, $user]),
            self::kind('telegram.member_count', 'Telegram: how many members', 'users', 'Asks Telegram how many people are in a chat, as count.', [$bot, $chat]),
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
            self::kind('line.multicast', 'Send to many on LINE', 'users', 'Sends one message to several people at once, by LINE\'s multicast call: up to 500, each counted against the channel\'s monthly allowance.', [
                $channel,
                ['name' => 'to', 'label' => 'To', 'type' => 'template', 'required' => true, 'placeholder' => '{{ node("find").rows.line_id }}', 'help' => 'LINE user ids (U…): a list of them, or with commas between. Not groups.'],
                ...self::lineMessage(),
            ], sends: true, unsureHelp: 'It never goes out twice: LINE is given a key for it.'),
            self::kind('line.broadcast', 'Send to everyone on LINE', 'megaphone', 'Sends one message to everyone who has added the channel as a friend, by LINE\'s broadcast call: each of them counted against the monthly allowance.', [
                $channel,
                ...self::lineMessage(),
            ], sends: true, unsureHelp: 'It never goes out twice: LINE is given a key for it.'),
            self::kind('line.loading', 'Show loading on LINE', 'ellipsis', 'Shows someone that an answer is on its way -- three moving dots -- until a message comes or the time is up. Only in a chat with one person, and only while they have it open.', [
                $channel,
                ['name' => 'chat', 'label' => 'To', 'type' => 'template', 'required' => true, 'default' => '{{ trigger.sender.id }}', 'help' => 'A LINE user id (U…).'],
                ['name' => 'seconds', 'label' => 'For at most, in seconds', 'type' => 'number', 'required' => true, 'default' => 20],
            ]),
            self::kind('line.read', 'Mark as read on LINE', 'check-check', 'Marks a message, and all before it in its chat, as read. With Chat switched on for the channel; with it off, LINE marks everything read by itself.', [
                $channel,
                ['name' => 'token', 'label' => 'Read token', 'type' => 'template', 'required' => true, 'default' => '{{ trigger.reply.read }}', 'help' => 'What a LINE message comes with: {{ trigger.reply.read }}.'],
            ]),
            self::kind('line.leave', 'Leave a group on LINE', 'log-out', 'Takes the channel out of a group or a multi-person chat.', [$channel, $group]),
            ...self::lineRichMenus($channel, $user),
            self::kind('line.quota', 'LINE: monthly allowance', 'gauge', 'Asks LINE how many messages the channel may send first this month: limited, and limit.', [$channel]),
            self::kind('line.consumption', 'LINE: sent this month', 'chart-bar', 'Asks LINE how many messages counted against the allowance have gone out this month, as sent.', [$channel]),
            self::kind('line.group', 'LINE: about a group', 'info', 'Asks LINE for a group\'s name and picture.', [$channel, $group]),
            self::kind('line.group_count', 'LINE: how many members', 'users', 'Asks LINE how many people are in a group or a multi-person chat, as count.', [$channel, $group]),
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
            self::kind('line.richmenu_make', 'Make a rich menu on LINE', 'panel-bottom-open', 'Makes a rich menu -- the panel under a chat: a picture, and places on it to tap -- and puts its picture on it. It is shown to no one until it is set for everyone or for someone. Its picture and its buttons can\'t be changed after: a menu that is to look different is made again. Gives out its id.', [
                $channel,
                ['name' => 'name', 'label' => 'Called', 'type' => 'template', 'required' => true, 'placeholder' => 'Main menu', 'help' => 'For telling the channel\'s menus apart; people never see it.'],
                ['name' => 'picture', 'label' => 'Picture', 'type' => 'template', 'required' => true, 'placeholder' => '{{ node("save").ref_id }}', 'drive' => 'image', 'help' => 'A JPEG or PNG in the Drive, by its ref_id, at most 1 MB: 800 to 2500 wide, at least 250 high, at least 1.45 times as wide as it is high. LINE\'s own are 2500 × 1686 and, half as high, 2500 × 843. The menu is made the picture\'s size.'],
                ['name' => 'layout', 'label' => 'Buttons on it', 'type' => 'choice', 'required' => true, 'default' => 'six', 'options' => $layouts, 'help' => 'Where the picture can be tapped. Draw the picture to match.'],
                ['name' => 'buttons', 'label' => 'Buttons', 'type' => 'fields', 'required' => false, 'default' => [], 'labels' => true, 'when' => ['layout' => array_values(array_diff(array_keys($layouts), ['custom']))], 'help' => 'From left to right, top to bottom. Each is what it is (read aloud for those who can\'t see the picture; 20 letters), and what tapping it does. A place left without a button does nothing.', 'does' => self::lineDoes(menu: true), 'places' => ['by' => 'layout', 'picture' => 'picture', 'layouts' => Steps\LineRichMenu::LAYOUTS]],
                ['name' => 'areas', 'label' => 'Areas', 'type' => 'template', 'required' => false, 'when' => ['layout' => 'custom'], 'placeholder' => '[{ "bounds": { "x": 0, "y": 0, "width": 1250, "height": 843 }, "action": { "type": "message", "text": "Hours" } }]', 'help' => 'A JSON list of up to 20, each with its bounds on the picture and its action, as LINE takes them. It can hold {{ formulas }}.'],
                ['name' => 'bar', 'label' => 'On the bar', 'type' => 'template', 'required' => false, 'default' => 'Menu', 'help' => 'The words on the bar under the chat that opens and closes the menu; at most 14 letters.'],
                ['name' => 'open', 'label' => 'When the chat is opened', 'type' => 'choice', 'required' => true, 'default' => 'yes', 'options' => ['yes' => 'The menu is open', 'no' => 'The menu is closed until its bar is tapped']],
            ]),
            self::kind('line.richmenu_default', 'Show a rich menu to everyone on LINE', 'panel-bottom', 'Makes a rich menu the one everyone sees who has none of their own, in place of the one shown before. It comes before a menu set in LINE Official Account Manager.', [$channel, $menu]),
            self::kind('line.richmenu_default_off', 'Stop showing a rich menu to everyone on LINE', 'panel-bottom-close', 'Takes away the rich menu shown to everyone. Those given one of their own keep it; the rest see the one set in LINE Official Account Manager, if there is one.', [$channel]),
            self::kind('line.richmenu', 'Set someone\'s rich menu on LINE', 'panel-bottom', 'Gives one person a rich menu of the channel\'s -- the panel under their chat -- in place of the usual one.', [$channel, $user, $menu]),
            self::kind('line.richmenu_off', 'Take off someone\'s rich menu on LINE', 'panel-bottom-close', 'Takes away the rich menu given to one person; they see the channel\'s usual one again.', [$channel, $user]),
            self::kind('line.richmenu_many', 'Set many people\'s rich menu on LINE', 'panel-bottom', 'Gives several people a rich menu of the channel\'s at once, in place of the usual one. LINE does it shortly after, not at once.', [$channel, $many, $menu]),
            self::kind('line.richmenu_many_off', 'Take off many people\'s rich menu on LINE', 'panel-bottom-close', 'Takes away the rich menu given to several people at once; they see the channel\'s usual one again. LINE does it shortly after, not at once.', [$channel, $many]),
            self::kind('line.richmenu_of', 'LINE: someone\'s rich menu', 'panel-bottom-dashed', 'Asks LINE which rich menu one person was given: its id as menu, or nothing when they see the usual one.', [$channel, $user]),
            self::kind('line.richmenu_alias', 'Name a rich menu on LINE', 'tag', 'Gives a rich menu a short name, by which a button on another menu turns to it ("menu:" and the name) and any step can name it. A name already in use is moved to this menu: every tab that names it then turns to the new one.', [$channel, $alias, $menu]),
            self::kind('line.richmenu_alias_off', 'Take away a rich menu\'s name on LINE', 'tag', 'Takes a short name away from the rich menu that goes by it. A tab that names it does nothing after.', [$channel, $alias]),
            self::kind('line.richmenu_delete', 'Delete a rich menu on LINE', 'trash-2', 'Deletes a rich menu of the channel\'s for good. Those shown it see the usual one, or none.', [$channel, $menu]),
            self::kind('line.richmenus', 'LINE: rich menus', 'list', 'Asks LINE for the channel\'s rich menus: gives out menus, each with its id, name, the words on its bar, its size, how many places it has to tap, the short names it goes by (aliases), and whether everyone is shown it (default). Menus drawn in LINE Official Account Manager are not among them.', [$channel]),
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
            self::kind('linkedin.repost', 'Repost on LinkedIn', 'repeat', 'Shares a post again on the member\'s own profile, with words of their own over it or none. Gives out the new post\'s id and its address as url.', [
                $as,
                $post,
                ['name' => 'text', 'label' => 'Words over it', 'type' => 'template', 'required' => false, 'help' => 'Left empty, a plain repost.'],
                ...self::linkedinSeenBy(),
            ], sends: true),
            self::kind('linkedin.delete', 'Delete a post on LinkedIn', 'trash-2', 'Deletes one of the member\'s own posts for good. One already gone is no error.', [$as, $post]),
            self::kind('linkedin.comment', 'Comment on LinkedIn', 'message-square', 'Writes a comment under a post, as the member. Gives out the comment\'s id, and urn, which "Delete a comment on LinkedIn" takes.', [
                $as,
                $post,
                ['name' => 'text', 'label' => 'Comment', 'type' => 'template', 'required' => true, 'help' => 'At most 1,250 letters.'],
            ], sends: true),
            self::kind('linkedin.comment_delete', 'Delete a comment on LinkedIn', 'trash-2', 'Takes away a comment the member wrote.', [
                $as,
                ['name' => 'comment', 'label' => 'Comment', 'type' => 'template', 'required' => true, 'placeholder' => '{{ node("comment").urn }}', 'help' => 'What "Comment on LinkedIn" gives as urn -- or its id, with its post below.'],
                [...$post, 'required' => false, 'label' => 'Its post', 'help' => 'Only with a comment named by its id alone.'],
            ]),
            self::kind('linkedin.like', 'Like a post on LinkedIn', 'thumbs-up', 'Likes a post, as the member.', [$as, $post]),
            self::kind('linkedin.unlike', 'Take back a like on LinkedIn', 'thumbs-down', 'Takes the member\'s like off a post.', [$as, $post]),
            self::kind('linkedin.me', 'LinkedIn: who I am', 'badge-check', 'Asks LinkedIn who the connection is signed in as. Gives out id, name, first, last, picture and language.', [$as]),
        ];
    }

    // --- WhatsApp ---

    /**
     * @return list<NodeType>
     */
    private static function whatsapp(): array
    {
        $number = ['name' => 'connection', 'label' => 'Number', 'type' => 'connection', 'required' => true, 'services' => ['whatsapp']];
        $to = ['name' => 'to', 'label' => 'To', 'type' => 'template', 'required' => true, 'default' => '{{ trigger.chat.id }}', 'help' => 'Their number with its country code, like 66812345678.'];
        $under = ['name' => 'reply_to', 'label' => 'In answer to', 'type' => 'template', 'required' => false, 'placeholder' => '{{ trigger.reply.to }}', 'help' => 'A message\'s id: this is then shown under it. Left empty, it stands alone.'];
        $window = 'Only within 24 hours of their last message: after that, WhatsApp takes a template alone.';

        return [
            self::kind('whatsapp.send', 'Send on WhatsApp', 'send', 'Sends words from a business number to someone who wrote to it, with up to three buttons under them when any are named. '.$window, [
                $number,
                $to,
                ['name' => 'text', 'label' => 'Message', 'type' => 'template', 'required' => true],
                ['name' => 'buttons', 'label' => 'Buttons', 'type' => 'fields', 'required' => false, 'default' => [], 'labels' => true, 'help' => 'Up to three, each at most 20 letters: what it says, and what a press sends back as trigger.text -- its own words when left empty.'],
                $under,
            ], sends: true),
            self::kind('whatsapp.send_file', 'Send a file on WhatsApp', 'file-up', 'Sends a file from the Drive: a picture (JPEG or PNG, at most 5 MB), a video or a recording (16 MB) is shown as one, anything else goes as a document (100 MB). '.$window, [
                $number,
                $to,
                ['name' => 'file', 'label' => 'File', 'type' => 'template', 'required' => true, 'placeholder' => '{{ node("save").ref_id }}', 'help' => 'A file in the Drive, by its ref_id.'],
                ['name' => 'text', 'label' => 'Words under it', 'type' => 'template', 'required' => false],
                $under,
            ], sends: true),
            self::kind('whatsapp.template', 'Send a template on WhatsApp', 'file-text', 'Sends a message made from a template WhatsApp has approved for the business: the one thing that reaches someone first, or after the 24 hours since they last wrote. Meta charges for most of them.', [
                $number,
                [...$to, 'default' => null, 'placeholder' => '66812345678'],
                ['name' => 'name', 'label' => 'Template', 'type' => 'template', 'required' => true, 'placeholder' => 'hello_world', 'help' => 'Its name, as WhatsApp Manager lists it.'],
                ['name' => 'language', 'label' => 'In', 'type' => 'template', 'required' => true, 'default' => 'en_US', 'help' => 'The language it was approved in, as WhatsApp writes it: en_US, th.'],
                ['name' => 'values', 'label' => 'Its values', 'type' => 'template', 'required' => false, 'placeholder' => "{{ trigger.name }}\n{{ trigger.total }}", 'help' => 'What goes where its words leave room ({{1}}, {{2}}…), one on a line, in order.'],
            ], sends: true),
            self::kind('whatsapp.location', 'Send a place on WhatsApp', 'map-pin', 'Sends a place, shown on a map. '.$window, [
                $number,
                $to,
                ['name' => 'lat', 'label' => 'Latitude', 'type' => 'template', 'required' => true, 'placeholder' => '13.7563'],
                ['name' => 'lng', 'label' => 'Longitude', 'type' => 'template', 'required' => true, 'placeholder' => '100.5018'],
                ['name' => 'name', 'label' => 'Called', 'type' => 'template', 'required' => false],
                ['name' => 'address', 'label' => 'Address', 'type' => 'template', 'required' => false],
                $under,
            ], sends: true),
            self::kind('whatsapp.react', 'React on WhatsApp', 'smile', 'Puts an emoji on a message someone sent, in place of one put there before.', [
                $number,
                $to,
                ['name' => 'message', 'label' => 'On message', 'type' => 'template', 'required' => true, 'default' => '{{ trigger.reply.to }}', 'help' => 'The message\'s id.'],
                ['name' => 'emoji', 'label' => 'Emoji', 'type' => 'template', 'required' => false, 'default' => '👍', 'help' => 'Left empty, the reaction is taken back.'],
            ]),
            self::kind('whatsapp.read', 'Mark as read on WhatsApp', 'check-check', 'Marks a message, and all before it in its chat, as read: the two ticks under theirs turn blue.', [
                $number,
                ['name' => 'message', 'label' => 'Message', 'type' => 'template', 'required' => true, 'default' => '{{ trigger.reply.to }}', 'help' => 'The message\'s id.'],
                ['name' => 'typing', 'label' => 'And show "typing…"', 'type' => 'choice', 'required' => true, 'default' => 'no', 'options' => ['no' => 'No', 'yes' => 'Yes, until an answer goes or 25 seconds pass']],
            ]),
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
            self::kind('facebook.post', 'Post on a Facebook Page', 'megaphone', 'Posts as a Facebook Page: words, alone or with a link shown as a card, a picture or several, or a video. Facebook lets an app post as a Page, never on a person\'s own profile. Gives out the post\'s id and its address as url.', [
                $page,
                ['name' => 'text', 'label' => 'Words', 'type' => 'template', 'required' => false],
                ['name' => 'with', 'label' => 'With', 'type' => 'choice', 'required' => true, 'default' => 'none', 'options' => ['none' => 'Nothing else', 'link' => 'A link, shown as a card', 'picture' => 'A picture from the Drive', 'pictures' => 'Several pictures from the Drive', 'video' => 'A video from the Drive']],
                ['name' => 'link', 'label' => 'Link', 'type' => 'template', 'required' => false, 'placeholder' => 'https://example.com/news/{{ trigger.id }}', 'when' => ['with' => 'link']],
                ['name' => 'picture', 'label' => 'Picture', 'type' => 'template', 'required' => false, 'placeholder' => '{{ node("save").ref_id }}', 'drive' => 'image', 'help' => 'A picture in the Drive, by its ref_id.', 'when' => ['with' => 'picture']],
                ['name' => 'pictures', 'label' => 'Pictures', 'type' => 'template', 'required' => false, 'placeholder' => '{{ node("find").files }}', 'help' => 'From 2 to 10 in the Drive: a list of them, or their ref_ids with commas between.', 'when' => ['with' => 'pictures']],
                ['name' => 'video', 'label' => 'Video', 'type' => 'template', 'required' => false, 'placeholder' => '{{ node("save").ref_id }}', 'drive' => 'video', 'help' => 'A video in the Drive, by its ref_id.', 'when' => ['with' => 'video']],
                ['name' => 'title', 'label' => 'Its title', 'type' => 'template', 'required' => false, 'when' => ['with' => 'video']],
            ], sends: true),
            self::kind('facebook.comment', 'Comment on Facebook', 'message-square', 'Writes a comment as the Page: under one of its posts, or in answer to a comment there. Gives out the comment\'s id.', [
                $page,
                $either,
                ['name' => 'text', 'label' => 'Comment', 'type' => 'template', 'required' => true],
            ], sends: true),
            self::kind('facebook.like', 'Like on Facebook', 'thumbs-up', 'Likes a post or a comment, as the Page.', [$page, $either]),
            self::kind('facebook.unlike', 'Take back a like on Facebook', 'thumbs-down', 'Takes the Page\'s like off a post or a comment.', [$page, $either]),
            self::kind('facebook.delete', 'Delete on Facebook', 'trash-2', 'Deletes a post of the Page\'s, or a comment under one, for good.', [$page, $either]),
            self::kind('facebook.posts', 'Facebook: the Page\'s posts', 'list', 'Asks Facebook for the Page\'s newest posts: gives out posts, each with its id, text, at and url, and the newest alone as first.', [$page, $count(10)]),
            self::kind('facebook.comments', 'Facebook: a post\'s comments', 'scroll-text', 'Asks Facebook for the comments under a post of the Page\'s, the newest first: gives out comments, each with its id, text, at and from, and how many as count.', [$page, $on, $count(25)]),
            self::kind('facebook.me', 'Facebook: the Page', 'badge-check', 'Asks Facebook about the Page the connection is signed in as. Gives out id, name, url, likes, followers and picture.', [$page]),
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

        return [
            self::kind('instagram.post', 'Post on Instagram', 'megaphone', 'Posts as an Instagram professional account: a picture, several to swipe through, a reel, or a story. Gives out the post\'s id and its address as url. Instagram lets an app post, and not take a post down again.', [
                $account,
                ['name' => 'what', 'label' => 'What', 'type' => 'choice', 'required' => true, 'default' => 'picture', 'options' => ['picture' => 'A picture', 'several' => 'Several pictures or videos, to swipe through', 'reel' => 'A reel', 'story' => 'A story']],
                ['name' => 'picture', 'label' => 'Picture', 'type' => 'template', 'required' => false, 'placeholder' => '{{ node("save").ref_id }}', 'drive' => 'image', 'help' => 'A JPEG in the Drive, by its ref_id. '.$fetched, 'when' => ['what' => 'picture']],
                ['name' => 'files', 'label' => 'Pictures and videos', 'type' => 'template', 'required' => false, 'placeholder' => '{{ node("find").files }}', 'help' => 'From 2 to 10 in the Drive, each a JPEG or an MP4: a list of them, or their ref_ids with commas between. '.$fetched, 'when' => ['what' => 'several']],
                ['name' => 'video', 'label' => 'Video', 'type' => 'template', 'required' => false, 'placeholder' => '{{ node("save").ref_id }}', 'drive' => 'video', 'help' => 'An MP4 or a MOV in the Drive, by its ref_id. '.$fetched, 'when' => ['what' => 'reel']],
                ['name' => 'story', 'label' => 'Picture or video', 'type' => 'template', 'required' => false, 'placeholder' => '{{ node("save").ref_id }}', 'help' => 'A JPEG, an MP4 or a MOV in the Drive, by its ref_id. '.$fetched, 'when' => ['what' => 'story']],
                ['name' => 'caption', 'label' => 'Caption', 'type' => 'template', 'required' => false, 'help' => 'At most 2,200 letters.', 'when' => ['what' => ['picture', 'several', 'reel']]],
            ], sends: true),
            self::kind('instagram.comment', 'Comment on Instagram', 'message-square', 'Writes a comment under one of the account\'s posts, as the account. Gives out the comment\'s id.', [
                $account,
                $post,
                ['name' => 'text', 'label' => 'Comment', 'type' => 'template', 'required' => true],
            ], sends: true),
            self::kind('instagram.reply', 'Answer a comment on Instagram', 'reply', 'Answers a comment under one of the account\'s posts, as the account. Gives out the answer\'s id.', [
                $account,
                $comment,
                ['name' => 'text', 'label' => 'Answer', 'type' => 'template', 'required' => true],
            ], sends: true),
            self::kind('instagram.comment_delete', 'Delete a comment on Instagram', 'trash-2', 'Takes away a comment under one of the account\'s posts: its own, or someone else\'s.', [$account, $comment]),
            self::kind('instagram.posts', 'Instagram: my posts', 'list', 'Asks Instagram for the account\'s newest posts: gives out posts, each with its id, text, kind, at, url, likes and comments, and the newest alone as first.', [
                $account,
                ['name' => 'count', 'label' => 'How many', 'type' => 'number', 'required' => false, 'default' => 10, 'help' => 'The newest first; at most 100.'],
            ]),
            self::kind('instagram.comments', 'Instagram: a post\'s comments', 'scroll-text', 'Asks Instagram for the comments under one of the account\'s posts: gives out comments, each with its id, text, at, from and likes, and how many as count.', [
                $account,
                $post,
                ['name' => 'count', 'label' => 'How many', 'type' => 'number', 'required' => false, 'default' => 25, 'help' => 'At most 100.'],
            ]),
            self::kind('instagram.me', 'Instagram: who I am', 'badge-check', 'Asks Instagram about the account the connection is signed in as. Gives out id, username, name, kind, picture, followers, following and posts.', [$account]),
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
            self::kind('threads.post', 'Post on Threads', 'megaphone', 'Posts on a Threads profile: words (at most 500 letters), alone or with a link, a picture, a video, or several to swipe through -- or, told which post it answers, as a reply under it. Gives out the post\'s id and its address as url.', [
                $profile,
                ['name' => 'text', 'label' => 'Words', 'type' => 'template', 'required' => false, 'help' => 'At most 500 letters.'],
                ['name' => 'with', 'label' => 'With', 'type' => 'choice', 'required' => true, 'default' => 'none', 'options' => ['none' => 'Nothing else', 'link' => 'A link, shown as a card', 'picture' => 'A picture from the Drive', 'video' => 'A video from the Drive', 'several' => 'Several pictures or videos']],
                ['name' => 'link', 'label' => 'Link', 'type' => 'template', 'required' => false, 'placeholder' => 'https://example.com/news/{{ trigger.id }}', 'when' => ['with' => 'link']],
                ['name' => 'picture', 'label' => 'Picture', 'type' => 'template', 'required' => false, 'placeholder' => '{{ node("save").ref_id }}', 'drive' => 'image', 'help' => 'A JPEG or a PNG in the Drive, by its ref_id. '.$fetched, 'when' => ['with' => 'picture']],
                ['name' => 'video', 'label' => 'Video', 'type' => 'template', 'required' => false, 'placeholder' => '{{ node("save").ref_id }}', 'drive' => 'video', 'help' => 'An MP4 or a MOV in the Drive, by its ref_id. '.$fetched, 'when' => ['with' => 'video']],
                ['name' => 'files', 'label' => 'Pictures and videos', 'type' => 'template', 'required' => false, 'placeholder' => '{{ node("find").files }}', 'help' => 'From 2 to 20 in the Drive: a list of them, or their ref_ids with commas between. '.$fetched, 'when' => ['with' => 'several']],
                ['name' => 'reply_to', 'label' => 'In answer to', 'type' => 'template', 'required' => false, 'placeholder' => '{{ node("post").id }}', 'help' => 'A post\'s id: this is then a reply under it. Left empty, a post of its own.'],
            ], sends: true),
            self::kind('threads.repost', 'Repost on Threads', 'repeat', 'Shares a post again on the profile, as it is. Gives out the repost\'s id.', [$profile, $post], sends: true),
            self::kind('threads.delete', 'Delete a post on Threads', 'trash-2', 'Deletes one of the profile\'s own posts or replies for good. Threads lets an app delete 100 a day.', [$profile, $post]),
            self::kind('threads.posts', 'Threads: my posts', 'list', 'Asks Threads for the profile\'s newest posts: gives out posts, each with its id, text, from, at and url, and the newest alone as first.', [
                $profile,
                ['name' => 'count', 'label' => 'How many', 'type' => 'number', 'required' => false, 'default' => 10, 'help' => 'The newest first; at most 100.'],
            ]),
            self::kind('threads.replies', 'Threads: a post\'s replies', 'scroll-text', 'Asks Threads for the replies under one of the profile\'s posts: gives out replies, each with its id, text, from, at and url, and how many as count.', [
                $profile,
                $post,
                ['name' => 'count', 'label' => 'How many', 'type' => 'number', 'required' => false, 'default' => 25, 'help' => 'At most 100.'],
            ]),
            self::kind('threads.me', 'Threads: who I am', 'badge-check', 'Asks Threads about the profile the connection is signed in as. Gives out id, username, name, picture and about.', [$profile]),
        ];
    }

    /**
     * One kind: in its service's group, with one way in and one out. One
     * that sends is not simply made again, and says what happens instead.
     *
     * @param  list<Setting>  $settings
     * @return NodeType
     */
    private static function kind(string $type, string $label, string $icon, string $description, array $settings, bool $sends = false, ?string $unsureHelp = null): array
    {
        return [
            'type' => $type,
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
