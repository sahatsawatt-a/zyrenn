// What the dashboard is given of a project (App\Support\Dashboard\Glance)

export type TicketCounts = {
    open: number;
    in_progress: number;
    on_hold: number;
};

export type Running = {
    workflows_on: number;
    runs_failed: number;
    suites: number;
    suites_failing: number;
    requests_new: number;
};

export type BuildingPart = { code: string; name: string; status: string };

export type Happened = {
    who: string | null;
    text: string;
    at: string;
    href: string | null;
};

// A card for each of one's projects, on one's own dashboard
export type Glance = {
    ref_id: string;
    name: string;
    role: string;
    tickets: TicketCounts;
    building: BuildingPart[];
    running: Running;
    last: Happened | null;
};

export type TicketLine = {
    number: number;
    title: string;
    assignee: string | null;
    reason: string | null;
    href: string;
};

// A project's own dashboard, in full
export type ProjectGlance = {
    tickets: {
        counts: TicketCounts;
        in_progress: TicketLine[];
        on_hold: TicketLine[];
    };
    building: BuildingPart[];
    running: Running;
    activity: Happened[];
};
