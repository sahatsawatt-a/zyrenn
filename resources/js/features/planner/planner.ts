// The content planner, as a demo: posts planned on a calendar, each with a
// version for every platform it goes to, checked against what that platform
// takes, approved, scheduled, and published through a workflow. Everything
// here lives in the page: nothing is kept, and nothing is posted.

export type Platform = 'facebook' | 'instagram' | 'threads' | 'linkedin';

export type PostStatus =
    | 'draft'
    | 'approved'
    | 'scheduled'
    | 'posted'
    | 'failed';

export type Picture = { name: string; src: string };

export type Version = { on: boolean; text: string };

export type Result = {
    state: 'waiting' | 'sending' | 'posted' | 'failed';
    url?: string;
    why?: string;
    likes?: number;
    comments?: number;
};

export type Post = {
    id: number;
    title: string;
    text: string;
    pictures: Picture[];
    versions: Record<Platform, Version>;
    at: string;
    status: PostStatus;
    workflow: string;
    results: Partial<Record<Platform, Result>>;
};

export const PLATFORMS: Record<
    Platform,
    { label: string; most: number; tone: string; picture: boolean }
> = {
    facebook: {
        label: 'Facebook',
        most: 63206,
        tone: 'bg-sky-600',
        picture: false,
    },
    instagram: {
        label: 'Instagram',
        most: 2200,
        tone: 'bg-pink-500',
        picture: true,
    },
    threads: {
        label: 'Threads',
        most: 500,
        tone: 'bg-neutral-700',
        picture: false,
    },
    linkedin: {
        label: 'LinkedIn',
        most: 3000,
        tone: 'bg-blue-700',
        picture: false,
    },
};

export const STATUSES: Record<PostStatus, { label: string; tone: string }> = {
    draft: { label: 'Draft', tone: 'bg-muted text-muted-foreground' },
    approved: {
        label: 'Approved',
        tone: 'bg-violet-500/15 text-violet-700 dark:text-violet-300',
    },
    scheduled: {
        label: 'Scheduled',
        tone: 'bg-sky-500/15 text-sky-700 dark:text-sky-300',
    },
    posted: {
        label: 'Posted',
        tone: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    },
    failed: {
        label: 'Failed',
        tone: 'bg-red-500/15 text-red-700 dark:text-red-300',
    },
};

// What stands in the way of a version going out, on its platform
export function problemsOf(post: Post, platform: Platform): string[] {
    const version = post.versions[platform];
    const rules = PLATFORMS[platform];
    const text = version.text || post.text;
    const problems: string[] = [];

    if (!version.on) {
        return problems;
    }

    if (text.length > rules.most) {
        problems.push(
            `${text.length - rules.most} letters too long: ${rules.label} takes ${rules.most.toLocaleString()}.`,
        );
    }

    if (rules.picture && post.pictures.length === 0) {
        problems.push(`${rules.label} needs a picture or a video.`);
    }

    if (!text.trim() && post.pictures.length === 0) {
        problems.push('Nothing to post yet.');
    }

    return problems;
}

// A picture drawn here, so the demo needs nothing from outside
const picture = (
    name: string,
    from: string,
    to: string,
    word: string,
): Picture => ({
    name,
    src:
        'data:image/svg+xml;utf8,' +
        encodeURIComponent(
            `<svg xmlns="http://www.w3.org/2000/svg" width="400" height="400"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="${from}"/><stop offset="1" stop-color="${to}"/></linearGradient></defs><rect width="400" height="400" fill="url(#g)"/><text x="200" y="215" font-family="sans-serif" font-size="44" font-weight="700" fill="white" text-anchor="middle">${word}</text></svg>`,
        ),
});

export const SAMPLE_PICTURES: Picture[] = [
    picture('mug.png', '#f59e0b', '#ef4444', 'Mug'),
    picture('tote.png', '#10b981', '#0ea5e9', 'Tote bag'),
    picture('sale.png', '#8b5cf6', '#ec4899', '10% off'),
];

const day = (offset: number, hour: number) => {
    const at = new Date();
    at.setDate(at.getDate() + offset);
    at.setHours(hour, 0, 0, 0);

    return at.toISOString();
};

const versions = (
    text: string,
    off: Platform[] = [],
): Record<Platform, Version> => ({
    facebook: { on: !off.includes('facebook'), text: '' },
    instagram: { on: !off.includes('instagram'), text: '' },
    threads: {
        on: !off.includes('threads'),
        text: text.length > 500 ? text.slice(0, 480) + '…' : '',
    },
    linkedin: { on: !off.includes('linkedin'), text: '' },
});

export function samplePosts(): Post[] {
    const autumn =
        'Autumn is here, and so is our new mug: warm hands, slow mornings. In the shop from Friday.';

    return [
        {
            id: 1,
            title: 'New mug',
            text: autumn,
            pictures: [SAMPLE_PICTURES[0]],
            versions: versions(autumn),
            at: day(-3, 10),
            status: 'posted',
            workflow: 'Publish everywhere',
            results: {
                facebook: { state: 'posted', url: '#', likes: 48, comments: 6 },
                instagram: {
                    state: 'posted',
                    url: '#',
                    likes: 212,
                    comments: 19,
                },
                threads: { state: 'posted', url: '#', likes: 31, comments: 2 },
                linkedin: {
                    state: 'failed',
                    why: 'LinkedIn refused the sign-in: it ran out on Oct 10. Sign in again on the connection.',
                },
            },
        },
        {
            id: 2,
            title: 'Tote bag is back',
            text: 'The tote bag everyone asked for is back in stock, in three colours.',
            pictures: [SAMPLE_PICTURES[1]],
            versions: versions('', ['linkedin']),
            at: day(1, 12),
            status: 'scheduled',
            workflow: 'Publish everywhere',
            results: {},
        },
        {
            id: 3,
            title: 'Weekend sale',
            text: '10% off everything this weekend. No code needed.',
            pictures: [],
            versions: versions(''),
            at: day(4, 9),
            status: 'approved',
            workflow: 'Publish everywhere',
            results: {},
        },
        {
            id: 4,
            title: 'Behind the scenes',
            text: '',
            pictures: [],
            versions: versions('', ['linkedin']),
            at: day(8, 17),
            status: 'draft',
            workflow: 'Publish everywhere',
            results: {},
        },
    ];
}

// What a demo agent writes for each platform: shorter, or more formal
export function written(text: string, platform: Platform): string {
    const base = text.trim() || 'Something new is coming to the shop.';

    return {
        facebook: base,
        instagram: `${base}\n\n#shop #new #autumn`,
        threads: base.length > 200 ? `${base.slice(0, 190)}…` : base,
        linkedin: `${base}\n\nA small shop's news, shared with the people who make it possible.`,
    }[platform];
}
