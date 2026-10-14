// A project's product, tracked (ProductController::index): its tree of
// parts, what was asked of it, what it must do, and the work behind them.

export type ElementStatus =
    | 'planned'
    | 'building'
    | 'partial'
    | 'verifying'
    | 'live'
    | 'issues'
    | 'blocked'
    | 'retired';

export type Element = {
    ref_id: string;
    parent: string | null;
    kind: 'component' | 'capability';
    code: string;
    name: string;
    summary: string | null;
    status: ElementStatus;
    shared: boolean;
    // A component's capabilities, anywhere under it, by how they stand
    counts: Partial<Record<ElementStatus, number>> | null;
    requirements: number;
    work: number;
    open_bugs: number;
};

export type Link = { id: number; from: string; to: string; kind: LinkKind };

export type LinkKind =
    | 'uses'
    | 'depends_on'
    | 'extends'
    | 'replaces'
    | 'related';

export type TicketRef = {
    number: number;
    title: string;
    type: 'bug' | 'feature';
    status: string;
};

export type ImpactPart = {
    ref_id: string;
    code: string;
    name: string;
    under: string | null;
};

export type ProductRequest = {
    ref_id: string;
    code: string;
    original: string;
    source: 'typed' | 'chat' | 'ticket';
    from_name: string | null;
    status: RequestStatus;
    requirements: number;
    chat: string | null;
    ticket: number | null;
    created_at: string;
};

export type RequestStatus =
    | 'new'
    | 'analysing'
    | 'clarifying'
    | 'split'
    | 'rejected'
    | 'done';

export type Requirement = {
    ref_id: string;
    code: string;
    title: string;
    need: string | null;
    body: string | null;
    kind: 'new' | 'additional' | 'change' | 'constraint' | 'quality';
    status: RequirementStatus;
    understanding: 'unknown' | 'partial' | 'confirmed';
    priority: 'low' | 'normal' | 'high';
    questions: string[];
    assumptions: string[];
    criteria: { text: string; met: boolean }[];
    owner: { id: number; name: string } | null;
    request: { ref_id: string; code: string } | null;
    changes: { ref_id: string; code: string } | null;
    elements: { ref_id: string; code: string; name: string }[];
    tickets: TicketRef[];
    updated_at: string;
};

export type RequirementStatus =
    | 'draft'
    | 'clarifying'
    | 'ready'
    | 'building'
    | 'verifying'
    | 'verified'
    | 'released'
    | 'dropped';

export type Coverage = {
    rows: {
        ref_id: string;
        code: string;
        name: string;
        status: ElementStatus;
        required: boolean;
        worked: boolean;
        verified: boolean;
        live: boolean;
        open_bugs: number;
    }[];
    gaps: {
        kind: string;
        code: string;
        name: string;
        ref_id: string;
        about: string;
    }[];
};

// One part in full (ProductController::showElement)
export type ElementDetail = {
    links: {
        id: number;
        kind: LinkKind;
        ref_id: string;
        code: string;
        name: string;
    }[];
    linked_from: {
        id: number;
        kind: LinkKind;
        ref_id: string;
        code: string;
        name: string;
    }[];
    requirements: {
        ref_id: string;
        code: string;
        title: string;
        status: RequirementStatus;
    }[];
    tickets: TicketRef[];
    history: {
        what: string;
        from: string | null;
        to: string | null;
        by: string | null;
        at: string;
    }[];
    impact: {
        // Each with the part it is under: several may share a name
        direct: ImpactPart[];
        further: ImpactPart[];
        requirements: {
            ref_id: string;
            code: string;
            title: string;
            status: string;
        }[];
        open_tickets: TicketRef[];
    };
};

export const ELEMENT_STATUSES: Record<
    ElementStatus,
    { label: string; dot: string }
> = {
    planned: { label: 'Planned', dot: 'bg-muted-foreground/40' },
    building: { label: 'Building', dot: 'bg-sky-500' },
    partial: { label: 'Partly built', dot: 'bg-sky-300' },
    verifying: { label: 'To verify', dot: 'bg-violet-500' },
    live: { label: 'Live', dot: 'bg-emerald-500' },
    issues: { label: 'Live, with issues', dot: 'bg-amber-500' },
    blocked: { label: 'Blocked', dot: 'bg-red-500' },
    retired: { label: 'Retired', dot: 'bg-neutral-400' },
};

export const LINK_KINDS: Record<LinkKind, string> = {
    uses: 'Uses',
    depends_on: 'Depends on',
    extends: 'Extends',
    replaces: 'Replaces',
    related: 'Related to',
};

export const REQUEST_STATUSES: Record<RequestStatus, string> = {
    new: 'New',
    analysing: 'Being worked out',
    clarifying: 'Needs clarifying',
    split: 'Split into requirements',
    rejected: 'Not taken up',
    done: 'Done',
};

export const REQUIREMENT_STATUSES: Record<
    RequirementStatus,
    { label: string; tone: string }
> = {
    draft: { label: 'Draft', tone: 'bg-muted text-muted-foreground' },
    clarifying: {
        label: 'Clarifying',
        tone: 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
    },
    ready: {
        label: 'Ready',
        tone: 'bg-violet-500/15 text-violet-700 dark:text-violet-300',
    },
    building: {
        label: 'Building',
        tone: 'bg-sky-500/15 text-sky-700 dark:text-sky-300',
    },
    verifying: {
        label: 'Verifying',
        tone: 'bg-indigo-500/15 text-indigo-700 dark:text-indigo-300',
    },
    verified: {
        label: 'Verified',
        tone: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    },
    released: {
        label: 'Released',
        tone: 'bg-emerald-600/20 text-emerald-800 dark:text-emerald-200',
    },
    dropped: {
        label: 'Dropped',
        tone: 'bg-muted text-muted-foreground line-through',
    },
};

export const KINDS: Record<Requirement['kind'], string> = {
    new: 'New',
    additional: 'Additional',
    change: 'Change',
    constraint: 'Constraint',
    quality: 'Quality (non-functional)',
};

export const UNDERSTANDING: Record<Requirement['understanding'], string> = {
    unknown: 'Not understood yet',
    partial: 'Partly understood',
    confirmed: 'Confirmed',
};

export const options = <T extends string>(
    labels: Record<T, string | { label: string }>,
) =>
    (Object.keys(labels) as T[]).map((value) => {
        const label = labels[value];

        return {
            value,
            label: typeof label === 'string' ? label : label.label,
        };
    });
