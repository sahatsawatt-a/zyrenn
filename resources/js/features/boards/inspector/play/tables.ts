// A project's tables as a prototype's editor knows them: what a row card can
// repeat and an action save to, with their columns by label (the page's
// `tables`, from BoardController).
export type ProjectTable = {
    ref_id: string;
    title: string;
    columns: { label: string; type: string }[];
};

/** A table by its ref_id, among those there are. */
export const tableOf = (tables: ProjectTable[], ref: string | undefined) =>
    tables.find((table) => table.ref_id === ref) ?? null;
