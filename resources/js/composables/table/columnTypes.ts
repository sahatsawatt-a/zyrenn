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
];

const ICONS = new Map(COLUMN_TYPES.map((kind) => [kind.type, kind.icon]));

/** The icon a column's kind is drawn with. */
export const columnIcon = (type: ColumnType): Component =>
    ICONS.get(type) ?? Type;
