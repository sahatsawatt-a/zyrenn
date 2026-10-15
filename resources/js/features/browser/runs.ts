// A run of the app's own browser suites, as the runner (tests/e2e/run.mjs)
// writes it down, and the runner that is waiting for one.

export type Listener = {
    since: number;
    busy: string | null;
    suites: string[];
    alone: string[];
    timings: Record<string, number>;
};
export type RunSummary = {
    id: string;
    from: 'app' | 'cli';
    began: number;
    ended: number | null;
    suites: number;
    passed: number;
    failed: number;
    stopped: boolean;
};
export type SuiteState =
    | 'waiting'
    | 'running'
    | 'passed'
    | 'failed'
    | 'stopped';
export type Suite = {
    name: string;
    state: SuiteState;
    lane: number | null;
    took: number | null;
    began?: number;
    expected: number | null;
    problems: string | null;
    lines: string[];
    shots: string[];
};
export type Run = Omit<RunSummary, 'suites' | 'passed' | 'failed'> & {
    lanes: number;
    watched: boolean;
    suites: Suite[];
};
