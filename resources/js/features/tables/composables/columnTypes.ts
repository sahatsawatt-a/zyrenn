import {
    Binary,
    Calendar,
    CheckSquare,
    DollarSign,
    FileText,
    Hash,
    Link2,
    Mail,
    MapPin,
    Percent,
    Phone,
    Sigma,
    Star,
    Tag,
    Tags,
    Type,
    User,
} from '@lucide/vue';
import type { Component } from 'vue';
import type { ColumnType } from '@/types';

// Every kind of column there is, as the header, the field list and the column
// dialog all show it.
export const COLUMN_TYPES: {
    type: ColumnType;
    label: string;
    icon: Component;
}[] = [
    { type: 'varchar', label: 'Text', icon: Type },
    { type: 'text', label: 'Long text', icon: FileText },
    { type: 'integer', label: 'Number', icon: Hash },
    { type: 'numeric', label: 'Decimal', icon: Binary },
    { type: 'boolean', label: 'Checkbox', icon: CheckSquare },
    { type: 'select', label: 'Select', icon: Tag },
    { type: 'multi_select', label: 'Multi-select', icon: Tags },
    { type: 'date', label: 'Date', icon: Calendar },
    { type: 'email', label: 'Email', icon: Mail },
    { type: 'url', label: 'Link', icon: Link2 },
    { type: 'phone', label: 'Phone', icon: Phone },
    { type: 'currency', label: 'Currency', icon: DollarSign },
    { type: 'percent', label: 'Percent', icon: Percent },
    { type: 'rating', label: 'Rating', icon: Star },
    { type: 'user', label: 'Person', icon: User },
    { type: 'location', label: 'Location', icon: MapPin },
    { type: 'formula', label: 'Formula', icon: Sigma },
];

/** Kinds whose values can be added up in the footer. */
export const SUMMABLE: ColumnType[] = [
    'integer',
    'numeric',
    'currency',
    'percent',
    'rating',
    'formula',
];

// How each kind is kept, as TableStorage::STORAGE has it on the server. A
// column can only become a kind kept the same way: anything else would mean
// converting every value in it, which the server refuses.
const STORAGE: Record<ColumnType, string> = {
    varchar: 'string',
    email: 'string',
    url: 'string',
    phone: 'string',
    select: 'string',
    user: 'string',
    text: 'text',
    integer: 'integer',
    percent: 'integer',
    rating: 'integer',
    numeric: 'decimal',
    currency: 'decimal',
    boolean: 'boolean',
    date: 'date',
    multi_select: 'json',
    location: 'location',
    formula: 'computed',
};

/** Whether a column of one kind can be switched to another as it stands. */
export const canBecome = (from: ColumnType, to: ColumnType): boolean =>
    STORAGE[from] === STORAGE[to];

const ICONS = new Map(COLUMN_TYPES.map((kind) => [kind.type, kind.icon]));

/** The icon a column's kind is drawn with. */
export const columnIcon = (type: ColumnType): Component =>
    ICONS.get(type) ?? Type;
