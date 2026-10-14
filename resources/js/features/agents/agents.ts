import type { AnswerAction } from '@/features/chat/components/AnswerActions.vue';
import type { AnswerSource } from '@/features/chat/components/AnswerSources.vue';

// A tool it may use: on Zyrenn ("zyrenn") or an MCP server, by its name
export type AgentTool = { server: string; name: string };

// What its owner's servers offer (AgentController::tools)
export type ToolServer = {
    server: string;
    name: string;
    problem: string | null;
    tools: { name: string; description: string; reads: boolean }[];
};

// An agent as its page is given it (AgentController::shown)
export type AgentVersion = {
    number: number;
    model: string | null;
    instructions: string | null;
    note: string | null;
    connection: string | null;
    knowledge: string | null;
    tools: string[];
    by: string | null;
    at: string | null;
};

export type Agent = {
    ref_id: string;
    name: string;
    model: string | null;
    instructions: string | null;
    version: number;
    connection: string | null;
    knowledge: string | null;
    tools: AgentTool[];
    versions: AgentVersion[];
};

export type AgentChoices = {
    connections: {
        ref_id: string;
        name: string;
        label: string;
        default_model: string | null;
    }[];
    knowledge: { ref_id: string; title: string }[];
    presets: { key: string; name: string; description: string }[];
};

// What a try gave back (AgentRunner::ask)
export type AgentAnswer = {
    text: string;
    json: unknown;
    version: number;
    model: string;
    found: number;
    knows: boolean;
    sources: AnswerSource[];
    actions: AnswerAction[];
};
