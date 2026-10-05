export * from './auth';
export * from './navigation';
export * from './ui';

export interface ColumnOption {
    id: string;
    value: string;
    color: string; // A tone's name, like "amber" -- see composables/table/tones.ts
}

export type ColumnType =
    | 'varchar'
    | 'text'
    | 'integer'
    | 'numeric'
    | 'boolean'
    | 'select'
    | 'multi_select'
    | 'date'
    | 'email'
    | 'url'
    | 'phone'
    | 'currency'
    | 'percent'
    | 'rating'
    | 'user'
    | 'location';

/** A place in a location cell: WGS84, and its address or name. */
export interface LocationValue {
    lat: number;
    lng: number;
    label: string;
}

export interface ColumnMeta {
    name: string;
    label: string;
    type: ColumnType;
    isPrimary: boolean;
    width?: number;
    options?: ColumnOption[]; // Target data options array for select elements
    hidden?: boolean;
    currencySymbol?: string;
    maxRating?: number;
    defaultValue?: any;
}

export interface RowData {
    id: number;
    [columnName: string]: any;
}

export type FilterOperator =
    | 'contains'
    | 'not_contains'
    | 'equals'
    | 'not_equals'
    | 'is_empty'
    | 'is_not_empty'
    | 'gt'
    | 'lt';

export interface TableFilter {
    id: string;
    column: string;
    operator: FilterOperator;
    value: string;
}

export interface TableSort {
    column: string;
    direction: 'asc' | 'desc';
}

export type TableDensity = 'compact' | 'normal' | 'spacious';
