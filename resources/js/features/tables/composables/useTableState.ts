import { ref } from 'vue';
import type {
    ColumnMeta,
    RowData,
    TableFilter,
    TableSort,
    TableDensity,
    TableParameter,
} from '@/types';

// Pinned global module-level references ensuring single-source-of-truth reactivity
export const activeTable = ref<string>('');
export const columns = ref<ColumnMeta[]>([]);
export const rows = ref<RowData[]>([]);
export const isLoading = ref<boolean>(false);
export const searchQuery = ref<string>('');
export const filters = ref<TableFilter[]>([]);
export const sort = ref<TableSort | null>(null);
export const density = ref<TableDensity>('normal');
export const selectedRowIds = ref<number[]>([]);
// Who a "user" column can name: the project's members, or the table's owner
export const people = ref<string[]>([]);
// Named values the table's formulas share (rate = 5), as the server keeps them
export const parameters = ref<TableParameter[]>([]);

// 🌟 TRANSACTIONAL HISTORY STACKS MAPPED FROM BOARD CORE PATTERNS
export interface TableHistorySnapshot {
    columns: ColumnMeta[];
    rows: RowData[];
}
export const pastStack = ref<TableHistorySnapshot[]>([]);
export const futureStack = ref<TableHistorySnapshot[]>([]);
