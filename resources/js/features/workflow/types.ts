// A workflow as the server keeps it (App\Support\Workflow\Definition), and
// the kinds of step it can have (App\Support\Workflow\NodeTypes).

export type SettingType =
    | 'text'
    | 'template'
    | 'formula'
    | 'json'
    | 'choice'
    | 'fields'
    | 'number'
    | 'cron'
    | 'token'
    | 'connection'
    | 'room'
    | 'table'
    | 'folder'
    | 'column'
    | 'model'
    | 'timezone'
    | 'workflow';

export type Setting = {
    name: string;
    label: string;
    type: SettingType;
    required: boolean;
    default?: unknown;
    help?: string;
    placeholder?: string;
    options?: Record<string, string>;
    // For a connection: the services it may be of ("ai" for any AI one)
    services?: string[];
    // For named values: what names them ("columns": the step's table's)
    names?: 'columns';
    // Shown only while another setting is so ("true": set to anything but none)
    when?: Record<string, string | boolean>;
    // A room: "any chat linked to …" too; a connection: "the chat's own" too
    linked?: boolean;
    // A Switch's ways: each named value a formula, each name a way out
    ways?: boolean;
    // A folder: left empty, every folder rather than the top of the Drive
    anywhere?: boolean;
};

// What a workflow's steps may choose from: its owner's connections, chats
// and tables (names only), and where its webhooks are reached from outside
export type Options = {
    connections: {
        ref_id: string;
        name: string;
        service: string;
        label: string;
        ai: boolean;
    }[];
    rooms: { ref_id: string; title: string }[];
    tables: {
        ref_id: string;
        title: string;
        columns: { name: string; label: string; type: string }[];
    }[];
    // Its owner's Drive folders, each by its path: "Receipts/2026"
    folders: string[];
    hooks: string | null;
    timezone: string;
    // Its owner's workflows another may run ("When run by another workflow")
    workflows: { ref_id: string; title: string }[];
};

export type NodeType = {
    type: string;
    version: number;
    label: string;
    group: string;
    icon: string;
    description: string;
    trigger: boolean;
    inputs: string[];
    outputs: string[];
    config: Setting[];
    repeatable?: boolean;
};

export type Field = { name: string; value: string };

export type StepNode = {
    id: string;
    key: string;
    type: string;
    nodeVersion: number;
    label: string;
    position: { x: number; y: number };
    config: Record<string, unknown>;
    onError: 'stop' | 'continue';
};

export type StepEdge = {
    id: string;
    source: string;
    sourceHandle: string;
    target: string;
    targetHandle: string;
};

export type Definition = {
    schemaVersion: number;
    nodes: StepNode[];
    edges: StepEdge[];
    settings: Record<string, unknown>;
};

export type Problem = { message: string; node?: string; edge?: string };

export type Publishing = {
    published: { number: number; at: string; by: string | null } | null;
    unpublished_changes: boolean;
};

export type SaveStatus = 'saved' | 'saving' | 'unsaved' | 'error' | 'conflict';

export type RunStatus =
    | 'queued'
    | 'running'
    | 'waiting'
    | 'succeeded'
    | 'failed'
    | 'cancelled';

export type StepStatus =
    | 'pending'
    | 'queued'
    | 'running'
    | 'waiting'
    | 'retry_wait'
    | 'succeeded'
    | 'failed'
    | 'skipped'
    | 'cancelled';

export type RunSummary = {
    ref_id: string;
    status: RunStatus;
    reason: string | null;
    error: string | null;
    trigger: string;
    version: { number: number; kind: 'published' | 'test' };
    started_by: string | null;
    created_at: string;
    started_at: string | null;
    finished_at: string | null;
    duration_ms: number | null;
};

export type RunStep = {
    node_id: string;
    node_key: string;
    node_type: string;
    status: StepStatus;
    handle: string | null;
    attempt: number;
    input: { input: unknown; settings: Record<string, unknown> } | null;
    output: unknown;
    error: { message: string; retryable?: boolean } | null;
    started_at: string | null;
    finished_at: string | null;
    duration_ms: number | null;
    waiting_for: 'delay' | 'approval' | 'resend' | 'children' | null;
    resume_at: string | null;
    wait: {
        message?: string;
        answer?: { approved: boolean; by: string; note: string | null };
        // A "For each": the items it runs for
        items?: unknown[];
    } | null;
};

export type RunLog = {
    level: 'info' | 'warning' | 'error';
    message: string;
    created_at: string;
    node_id: string | null;
};

export type Run = RunSummary & {
    can: { act: boolean };
    steps: RunStep[];
    logs: RunLog[];
};
