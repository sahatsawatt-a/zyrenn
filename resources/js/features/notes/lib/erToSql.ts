// Turns a Mermaid erDiagram into CREATE TABLE statements. The diagram stays the
// source of truth; the SQL is a view of it, regenerated whenever it is asked for.
//
// What the diagram does not say is worked out the way a person reading it would:
// a foreign key's target comes from the relationship lines (or its name, as in
// user_id -> USER), cardinality decides which side holds it and whether it may
// be null, and a many-to-many relationship gets a join table. Anything added
// that was not drawn is marked with a comment in the output.

export type SqlDialect = 'postgres' | 'mysql' | 'sqlite';

export const sqlDialects: { id: SqlDialect; label: string }[] = [
    { id: 'postgres', label: 'PostgreSQL' },
    { id: 'mysql', label: 'MySQL' },
    { id: 'sqlite', label: 'SQLite' },
];

// The first line of every generated script, so a block made from a diagram can
// be found again and regenerated in place
export const SQL_HEADER = '-- Generated from a Mermaid erDiagram';

// ------------------------------------------------------------------- Parsing
type Cardinality = { min: 0 | 1; many: boolean };

export interface ErAttribute {
    type: string;
    name: string;
    keys: Set<'PK' | 'FK' | 'UK'>;
    comment?: string;
}

export interface ErEntity {
    id: string;
    label: string;
    attributes: ErAttribute[];
}

export interface ErRelationship {
    left: string;
    right: string;
    leftCard: Cardinality;
    rightCard: Cardinality;
    label: string;
}

export interface ErDiagram {
    entities: Map<string, ErEntity>;
    relationships: ErRelationship[];
}

const ZERO_ONE: Cardinality = { min: 0, many: false };
const ONE: Cardinality = { min: 1, many: false };
const ZERO_MANY: Cardinality = { min: 0, many: true };
const ONE_MANY: Cardinality = { min: 1, many: true };

// Crow's-foot symbols as Mermaid writes them on each side of the line, and the
// word forms it accepts in their place
const LEFT_CARDS: Record<string, Cardinality> = {
    '|o': ZERO_ONE,
    '||': ONE,
    '}o': ZERO_MANY,
    '}|': ONE_MANY,
};
const RIGHT_CARDS: Record<string, Cardinality> = {
    'o|': ZERO_ONE,
    '||': ONE,
    'o{': ZERO_MANY,
    '|{': ONE_MANY,
};
const WORD_CARDS: Record<string, Cardinality> = {
    'zero or one': ZERO_ONE,
    'one or zero': ZERO_ONE,
    'only one': ONE,
    '1': ONE,
    'zero or more': ZERO_MANY,
    'zero or many': ZERO_MANY,
    'many(0)': ZERO_MANY,
    '0+': ZERO_MANY,
    'one or more': ONE_MANY,
    'one or many': ONE_MANY,
    'many(1)': ONE_MANY,
    '1+': ONE_MANY,
};

const escape = (text: string) => text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
const words = Object.keys(WORD_CARDS).map(escape).join('|');

// An entity: CUSTOMER, "Line Item", or an alias like p[Person] / p["A Person"]
const ENTITY = String.raw`(?:"[^"]+"|[A-Za-z_][\w-]*)(?:\[(?:"[^"]*"|[^\]]*)\])?`;
const CLASSES = String.raw`(?::::[\w,-]+)?`;

const RELATIONSHIP = new RegExp(
    String.raw`^(${ENTITY})${CLASSES}\s*` +
        String.raw`(\|o|\|\||\}o|\}\||${words})\s*` +
        String.raw`(--|\.\.|optionally to|to)\s*` +
        String.raw`(o\||\|\||o\{|\|\{|${words})\s*` +
        String.raw`(${ENTITY})${CLASSES}\s*:\s*(.*)$`,
    'i',
);
const ENTITY_LINE = new RegExp(String.raw`^(${ENTITY})${CLASSES}\s*(\{.*)?$`);
const ATTRIBUTE =
    /^([A-Za-z_][\w\-[\]()]*)\s+(\*?[A-Za-z_][\w-]*)(?:\s+((?:PK|FK|UK)(?:\s*,\s*(?:PK|FK|UK))*))?(?:\s+"([^"]*)")?\s*$/;

const unquote = (text: string) => text.replace(/^"(.*)"$/, '$1').trim();

function parseEntity(text: string): { id: string; label: string } {
    const alias = text.match(/^(.+?)\[(.*)\]$/);
    if (alias) {
        return { id: unquote(alias[1]), label: unquote(alias[2]) };
    }

    const name = unquote(text);
    return { id: name, label: name };
}

export function isErDiagram(source: string): boolean {
    for (const raw of source.split('\n')) {
        const line = raw.trim();
        if (line === '' || line.startsWith('%%')) continue;
        if (line === '---') return /^\s*erDiagram\b/m.test(source);
        return /^erDiagram\b/.test(line);
    }

    return false;
}

export function parseErDiagram(source: string): ErDiagram {
    const entities = new Map<string, ErEntity>();
    const relationships: ErRelationship[] = [];

    const entity = (text: string): ErEntity => {
        const { id, label } = parseEntity(text);
        let found = entities.get(id);
        if (!found) {
            found = { id, label, attributes: [] };
            entities.set(id, found);
        } else if (label !== id) {
            found.label = label;
        }
        return found;
    };

    const addAttribute = (into: ErEntity, line: string) => {
        const match = line.match(ATTRIBUTE);
        if (!match) return;

        const keys = new Set(
            (match[3] ?? '')
                .split(',')
                .map((key) => key.trim().toUpperCase())
                .filter(Boolean) as ('PK' | 'FK' | 'UK')[],
        );
        // A leading * is the crow's-foot way of marking a key
        if (match[2].startsWith('*')) keys.add('PK');

        into.attributes.push({
            type: match[1],
            name: match[2].replace(/^\*/, ''),
            keys,
            comment: match[4]?.trim() || undefined,
        });
    };

    const lines = source.split('\n');
    let open: ErEntity | null = null;
    let inFrontMatter = false;
    let seenHeader = false;

    for (const raw of lines) {
        let line = raw.replace(/%%.*$/, '').trim();

        if (line === '---' && !seenHeader) {
            inFrontMatter = !inFrontMatter;
            continue;
        }
        if (inFrontMatter || line === '') continue;

        if (!seenHeader) {
            if (/^erDiagram\b/.test(line)) seenHeader = true;
            continue;
        }

        if (open) {
            const closes = line.endsWith('}');
            if (closes) line = line.slice(0, -1).trim();
            if (line) addAttribute(open, line);
            if (closes) open = null;
            continue;
        }

        if (
            /^(direction|title|accTitle|accDescr|style|classDef|class)\b/.test(
                line,
            )
        ) {
            continue;
        }

        const relationship = line.match(RELATIONSHIP);
        if (relationship) {
            const leftCard =
                LEFT_CARDS[relationship[2]] ??
                WORD_CARDS[relationship[2].toLowerCase()];
            const rightCard =
                RIGHT_CARDS[relationship[4]] ??
                WORD_CARDS[relationship[4].toLowerCase()];

            if (leftCard && rightCard) {
                relationships.push({
                    left: entity(relationship[1]).id,
                    right: entity(relationship[5]).id,
                    leftCard,
                    rightCard,
                    label: unquote(relationship[6]),
                });
            }
            continue;
        }

        const declared = line.match(ENTITY_LINE);
        if (declared) {
            const found = entity(declared[1]);
            const body = declared[2];
            if (body) {
                const inner = body.slice(1).trim();
                if (inner.endsWith('}')) {
                    const rest = inner.slice(0, -1).trim();
                    if (rest) addAttribute(found, rest);
                } else {
                    if (inner) addAttribute(found, inner);
                    open = found;
                }
            }
        }
    }

    return { entities, relationships };
}

// ------------------------------------------------------------------- Schema
interface Column {
    name: string;
    type: string;
    pk: boolean;
    unique: boolean;
    fkMarked: boolean;
    notNull?: boolean;
    explicitNull: boolean;
    defaultValue?: string;
    references?: { entity: string; column?: string };
    comment?: string;
}

interface ForeignKey {
    columns: string[];
    table: Table;
    refColumns: string[];
    note?: string;
    deferred?: boolean;
}

interface Table {
    name: string;
    entity?: ErEntity;
    columns: Column[];
    foreignKeys: ForeignKey[];
    note?: string;
}

const normalize = (text: string) =>
    text.toLowerCase().replace(/[^a-z0-9]/g, '');

// USER -> user, LineItem -> line_item, "Line Item" -> line_item
const tableName = (text: string) =>
    text
        .trim()
        .replace(/([a-z0-9])([A-Z])/g, '$1_$2')
        .replace(/[^A-Za-z0-9_]+/g, '_')
        .replace(/^_+|_+$/g, '')
        .toLowerCase() || 'table';

// A comment may carry column settings ("not null, default 'draft'"); they
// become SQL, and whatever else it says stays a comment
function readComment(comment: string | undefined, column: Column) {
    if (!comment) return;

    let rest = comment;
    const take = (pattern: RegExp) => {
        const match = rest.match(pattern);
        if (match) rest = rest.replace(pattern, '');
        return match;
    };

    if (take(/\bnot\s+null\b/i)) {
        column.notNull = true;
        column.explicitNull = true;
    } else if (take(/\b(nullable|optional)\b/i)) {
        column.notNull = false;
        column.explicitNull = true;
    }

    if (take(/\bunique\b/i)) column.unique = true;

    const fallback = take(/\bdefault\s+('(?:[^']|'')*'|[^\s,;]+)/i);
    if (fallback) column.defaultValue = fallback[1];

    const target = take(
        /\b(?:references|refs?)\s+"?([A-Za-z_][\w-]*)"?(?:\s*[.(]\s*([A-Za-z_]\w*)\s*\)?)?/i,
    );
    if (target) {
        column.references = { entity: target[1], column: target[2] };
    }

    rest = rest
        .split(/[,;]/)
        .map((part) => part.trim())
        .filter(Boolean)
        .join(', ');
    if (rest) column.comment = rest;
}

function buildSchema(diagram: ErDiagram): Table[] {
    const tables = new Map<string, Table>();

    for (const entity of diagram.entities.values()) {
        const table: Table = {
            name: tableName(entity.label),
            entity,
            columns: [],
            foreignKeys: [],
        };

        for (const attribute of entity.attributes) {
            const column: Column = {
                name: attribute.name,
                type: attribute.type,
                pk: attribute.keys.has('PK'),
                unique: attribute.keys.has('UK'),
                fkMarked: attribute.keys.has('FK'),
                explicitNull: false,
            };
            readComment(attribute.comment, column);
            table.columns.push(column);
        }

        tables.set(entity.id, table);
    }

    const findEntity = (name: string) =>
        [...tables.values()].find(
            (table) =>
                table.entity &&
                (normalize(table.entity.id) === normalize(name) ||
                    normalize(table.name) === normalize(name)),
        );

    // A table every reference can point at: one drawn without a key gets an id
    const primaryKey = (table: Table): Column[] => {
        const keys = table.columns.filter((column) => column.pk);
        if (keys.length) return keys;

        const id: Column = {
            name: 'id',
            type: 'int',
            pk: true,
            unique: false,
            fkMarked: false,
            explicitNull: false,
            comment: 'added: no key in the diagram',
        };
        table.columns.unshift(id);
        return [id];
    };

    // The spellings a column might use for a table: user, users, USER...
    const stems = (table: Table) =>
        [table.entity?.id ?? table.name, table.name]
            .map(normalize)
            .flatMap((stem) => [stem, `${stem}s`, stem.replace(/e?s$/, '')]);

    // Does user_id (or userId, users_id...) name this table's key?
    const namesTable = (column: string, parent: Table, key: Column) => {
        const name = normalize(column);
        const keyName = normalize(key.name);

        if (keyName !== 'id' && name === keyName) return true;

        return stems(parent).some(
            (stem) => name === `${stem}${keyName}` || name === `${stem}id`,
        );
    };

    // A column named for a table it has no relationship line to
    const findEntityByName = (column: string): Table | undefined => {
        const name = normalize(column);
        if (!name.endsWith('id') || name === 'id') return undefined;
        return [...tables.values()].find(
            (table) =>
                table.entity &&
                stems(table).some((stem) => name === `${stem}id`),
        );
    };

    const claimed = new Map<Table, Set<Column>>();
    const claimedIn = (table: Table) => {
        let set = claimed.get(table);
        if (!set) claimed.set(table, (set = new Set()));
        return set;
    };

    // Finds (or adds) the columns of child that point at parent's key
    const link = (child: Table, parent: Table, note?: string): Column[] => {
        const keys = primaryKey(parent);
        const taken = claimedIn(child);
        const free = child.columns.filter(
            (column) => !taken.has(column) && !(child === parent && column.pk),
        );

        const pick = (key: Column): Column | undefined =>
            free.find(
                (column) =>
                    column.references &&
                    findEntity(column.references.entity) === parent,
            ) ??
            free.find(
                (column) =>
                    column.fkMarked && namesTable(column.name, parent, key),
            ) ??
            free.find((column) => namesTable(column.name, parent, key));

        const columns = keys.map((key) => {
            let column = pick(key);

            // One key and one loose FK column left: that must be it
            if (!column && keys.length === 1) {
                const loose = free.filter(
                    (candidate) =>
                        candidate.fkMarked &&
                        !candidate.references &&
                        !findEntityByName(candidate.name),
                );
                if (loose.length === 1) column = loose[0];
            }

            if (!column) {
                const base =
                    child === parent ? `parent_${parent.name}` : parent.name;
                column = {
                    name: `${base}_${key.name}`,
                    type: key.type,
                    pk: false,
                    unique: false,
                    fkMarked: true,
                    explicitNull: false,
                    comment: `added: ${note ?? `references ${parent.name}`}`,
                };
                child.columns.push(column);
            }

            taken.add(column);
            return column;
        });

        child.foreignKeys.push({
            columns: columns.map((column) => column.name),
            table: parent,
            refColumns: keys.map((key) => key.name),
            note,
        });

        return columns;
    };

    for (const relationship of diagram.relationships) {
        const left = tables.get(relationship.left)!;
        const right = tables.get(relationship.right)!;
        const leftName = left.entity!.label;
        const rightName = right.entity!.label;
        const note = `${leftName} ${relationship.label} ${rightName}`.trim();

        const { leftCard, rightCard } = relationship;

        if (leftCard.many && rightCard.many) {
            // Many-to-many: neither side can hold the key, so a table does
            const join: Table = {
                name:
                    left === right
                        ? `${left.name}_link`
                        : `${left.name}_${right.name}`,
                columns: [],
                foreignKeys: [],
                note: `added: many-to-many, ${note}`,
            };
            const leftKeys = primaryKey(left);
            const rightKeys = primaryKey(right);
            const add = (prefix: string, keys: Column[]) =>
                keys.map((key) => {
                    const column: Column = {
                        name: `${prefix}_${key.name}`,
                        type: key.type,
                        pk: true,
                        unique: false,
                        fkMarked: true,
                        explicitNull: false,
                    };
                    join.columns.push(column);
                    return column.name;
                });

            const leftColumns = add(left.name, leftKeys);
            const rightColumns = add(
                left === right ? `related_${right.name}` : right.name,
                rightKeys,
            );
            join.foreignKeys.push(
                {
                    columns: leftColumns,
                    table: left,
                    refColumns: leftKeys.map((key) => key.name),
                },
                {
                    columns: rightColumns,
                    table: right,
                    refColumns: rightKeys.map((key) => key.name),
                },
            );
            tables.set(`\0${join.name}`, join);
            continue;
        }

        // The key lives on the "many" side; for one-to-one, on whichever side
        // already names the other, else the right
        let child: Table;
        let parent: Table;
        let parentCard: Cardinality;

        if (rightCard.many) {
            [child, parent, parentCard] = [right, left, leftCard];
        } else if (leftCard.many) {
            [child, parent, parentCard] = [left, right, rightCard];
        } else {
            const leftNamesRight = left.columns.some(
                (column) =>
                    column.fkMarked &&
                    (findEntity(column.references?.entity ?? '') === right ||
                        findEntityByName(column.name) === right),
            );
            [child, parent, parentCard] = leftNamesRight
                ? [left, right, rightCard]
                : [right, left, leftCard];
        }

        const columns = link(child, parent, note);

        for (const column of columns) {
            if (!column.explicitNull && !column.pk) {
                column.notNull = parentCard.min === 1;
            }
            // One-to-one: each parent row has at most one of these
            if (!leftCard.many && !rightCard.many && columns.length === 1) {
                column.unique = true;
            }
        }
    }

    // FK columns no relationship line claimed: follow their name or comment
    for (const table of tables.values()) {
        if (!table.entity) continue;
        const taken = claimedIn(table);

        for (const column of table.columns) {
            if (!column.fkMarked || taken.has(column)) continue;

            const target = column.references
                ? findEntity(column.references.entity)
                : findEntityByName(column.name);

            if (!target) {
                column.comment = [
                    column.comment,
                    'FK: target not in the diagram',
                ]
                    .filter(Boolean)
                    .join('; ');
                continue;
            }

            const keys = primaryKey(target);
            const key =
                keys.find(
                    (candidate) => candidate.name === column.references?.column,
                ) ?? keys[0];
            taken.add(column);
            table.foreignKeys.push({
                columns: [column.name],
                table: target,
                refColumns: [key.name],
            });
        }
    }

    return [...tables.values()];
}

// ------------------------------------------------------------------- Output
const RESERVED = new Set(
    `all and any as asc between both by case cast check collate column constraint
    create cross current_date current_time current_timestamp current_user default
    delete desc distinct do drop else end except exists false fetch for foreign from
    full grant group having in index inner insert intersect interval into is join
    key left like limit natural not null offset on only or order outer primary
    references right select session_user set some table then to true union unique
    update user using values when where window with`.split(/\s+/),
);

const quote = (name: string, dialect: SqlDialect) => {
    if (/^[a-z_][a-z0-9_]*$/.test(name) && !RESERVED.has(name)) return name;
    return dialect === 'mysql'
        ? `\`${name.replace(/`/g, '``')}\``
        : `"${name.replace(/"/g, '""')}"`;
};

const INTEGER_TYPES = new Set([
    'int',
    'integer',
    'int4',
    'bigint',
    'int8',
    'long',
    'smallint',
    'int2',
    'short',
    'tinyint',
    'serial',
    'bigserial',
]);

const baseOf = (type: string) => type.replace(/[([].*$/, '').toLowerCase();

export function sqlType(raw: string, dialect: SqlDialect): string {
    const match = raw.match(/^([A-Za-z_][\w-]*)(\([^)]*\))?(\[\])?$/);
    if (!match) return raw.toUpperCase();

    const base = match[1].toLowerCase();
    const size = match[2]?.replace(/\s+/g, '');
    const pick = (postgres: string, mysql: string, sqlite: string) =>
        ({ postgres, mysql, sqlite })[dialect];

    let type: string;
    switch (base) {
        case 'int':
        case 'integer':
        case 'int4':
            type = pick('INTEGER', 'INT', 'INTEGER');
            break;
        case 'bigint':
        case 'int8':
        case 'long':
            type = pick('BIGINT', 'BIGINT', 'INTEGER');
            break;
        case 'smallint':
        case 'int2':
        case 'short':
            type = pick('SMALLINT', 'SMALLINT', 'INTEGER');
            break;
        case 'tinyint':
            type = pick('SMALLINT', 'TINYINT', 'INTEGER');
            break;
        case 'serial':
            type = pick('INTEGER', 'INT', 'INTEGER');
            break;
        case 'bigserial':
            type = pick('BIGINT', 'BIGINT', 'INTEGER');
            break;
        case 'string':
        case 'varchar':
            type = pick(
                `VARCHAR${size ?? '(255)'}`,
                `VARCHAR${size ?? '(255)'}`,
                'TEXT',
            );
            break;
        case 'char':
        case 'character':
            type = pick(`CHAR${size ?? '(1)'}`, `CHAR${size ?? '(1)'}`, 'TEXT');
            break;
        case 'text':
        case 'longtext':
        case 'clob':
            type = 'TEXT';
            break;
        case 'bool':
        case 'boolean':
            type = pick('BOOLEAN', 'BOOLEAN', 'INTEGER');
            break;
        case 'float':
        case 'double':
        case 'real':
            type = pick('DOUBLE PRECISION', 'DOUBLE', 'REAL');
            break;
        case 'decimal':
        case 'numeric':
            type = pick(
                `NUMERIC${size ?? ''}`,
                `DECIMAL${size ?? ''}`,
                'NUMERIC',
            );
            break;
        case 'money':
        case 'currency':
            type = pick('NUMERIC(12,2)', 'DECIMAL(12,2)', 'NUMERIC');
            break;
        case 'date':
            type = pick('DATE', 'DATE', 'TEXT');
            break;
        case 'datetime':
        case 'timestamp':
            type = pick(
                'TIMESTAMP',
                base === 'timestamp' ? 'TIMESTAMP' : 'DATETIME',
                'TEXT',
            );
            break;
        case 'timestamptz':
            type = pick('TIMESTAMPTZ', 'TIMESTAMP', 'TEXT');
            break;
        case 'time':
            type = pick('TIME', 'TIME', 'TEXT');
            break;
        case 'uuid':
        case 'guid':
            type = pick('UUID', 'CHAR(36)', 'TEXT');
            break;
        case 'json':
        case 'jsonb':
        case 'object':
        case 'map':
            type = pick('JSONB', 'JSON', 'TEXT');
            break;
        case 'blob':
        case 'bytes':
        case 'bytea':
        case 'binary':
            type = pick('BYTEA', 'BLOB', 'BLOB');
            break;
        case 'enum':
            type = pick('TEXT', 'VARCHAR(255)', 'TEXT');
            break;
        default:
            type = `${match[1].toUpperCase()}${size ?? ''}`;
    }

    if (match[3]) {
        return pick(`${type}[]`, 'JSON', 'TEXT');
    }

    return type;
}

// Referenced tables come first; a cycle's closing key is added afterwards with
// ALTER TABLE (SQLite needs no such care: it checks keys only when writing rows)
function order(tables: Table[], dialect: SqlDialect): Table[] {
    const state = new Map<Table, 'visiting' | 'done'>();
    const sorted: Table[] = [];

    const visit = (table: Table) => {
        state.set(table, 'visiting');
        for (const key of table.foreignKeys) {
            if (key.table === table) continue;
            const seen = state.get(key.table);
            if (seen === 'visiting') {
                if (dialect !== 'sqlite') key.deferred = true;
            } else if (!seen) {
                visit(key.table);
            }
        }
        state.set(table, 'done');
        sorted.push(table);
    };

    for (const table of tables) {
        if (!state.has(table)) visit(table);
    }

    return sorted;
}

function columnSql(column: Column, single: boolean, dialect: SqlDialect) {
    const parts = [quote(column.name, dialect)];
    const identity =
        single &&
        column.pk &&
        INTEGER_TYPES.has(baseOf(column.type)) &&
        !column.fkMarked;

    if (identity && dialect === 'sqlite') {
        // Only exactly INTEGER PRIMARY KEY becomes SQLite's auto-numbered row id
        parts.push('INTEGER PRIMARY KEY');
    } else {
        parts.push(sqlType(column.type, dialect));
        if (identity && dialect === 'postgres') {
            parts.push('GENERATED BY DEFAULT AS IDENTITY');
        }
        if (identity && dialect === 'mysql') parts.push('AUTO_INCREMENT');
        if (single && column.pk) parts.push('PRIMARY KEY');
    }

    if (!column.pk && column.notNull) parts.push('NOT NULL');
    if (column.unique && !(single && column.pk)) parts.push('UNIQUE');
    if (column.defaultValue) parts.push(`DEFAULT ${column.defaultValue}`);

    return parts.join(' ');
}

function foreignKeySql(key: ForeignKey, dialect: SqlDialect) {
    const list = (names: string[]) =>
        names.map((name) => quote(name, dialect)).join(', ');
    return `FOREIGN KEY (${list(key.columns)}) REFERENCES ${quote(key.table.name, dialect)} (${list(key.refColumns)})`;
}

function tableSql(table: Table, dialect: SqlDialect): string {
    const keys = table.columns.filter((column) => column.pk);
    const single = keys.length === 1;

    const lines: { sql: string; comment?: string; before?: string }[] =
        table.columns.map((column) => ({
            sql: columnSql(column, single, dialect),
            comment: column.comment,
        }));

    if (keys.length > 1) {
        lines.push({
            sql: `PRIMARY KEY (${keys.map((key) => quote(key.name, dialect)).join(', ')})`,
        });
    }

    for (const key of table.foreignKeys) {
        if (key.deferred) continue;
        lines.push({ sql: foreignKeySql(key, dialect), before: key.note });
    }

    const body = lines.flatMap((line, index) => {
        const comma = index < lines.length - 1 ? ',' : '';
        const trailing = line.comment ? ` -- ${line.comment}` : '';
        return [
            ...(line.before ? [`    -- ${line.before}`] : []),
            `    ${line.sql}${comma}${trailing}`,
        ];
    });

    return [
        ...(table.note ? [`-- ${table.note}`] : []),
        `CREATE TABLE ${quote(table.name, dialect)} (`,
        ...body,
        ');',
    ].join('\n');
}

/**
 * The CREATE TABLE statements for a Mermaid erDiagram. Throws when the source
 * is not an erDiagram or draws no entities.
 */
export function erDiagramToSql(
    source: string,
    dialect: SqlDialect = 'postgres',
): string {
    if (!isErDiagram(source)) {
        throw new Error('Only an erDiagram can be turned into SQL.');
    }

    const diagram = parseErDiagram(source);
    if (!diagram.entities.size) {
        throw new Error('This diagram has no entities to turn into tables.');
    }

    const tables = order(buildSchema(diagram), dialect);
    const label = sqlDialects.find((item) => item.id === dialect)!.label;

    const statements = tables.map((table) => tableSql(table, dialect));

    const deferred = tables.flatMap((table) =>
        table.foreignKeys
            .filter((key) => key.deferred)
            .map(
                (key) =>
                    `${key.note ? `-- ${key.note}\n` : ''}ALTER TABLE ${quote(table.name, dialect)} ADD ${foreignKeySql(key, dialect)};`,
            ),
    );

    return (
        [`${SQL_HEADER} (${label})`, ...statements, ...deferred].join('\n\n') +
        '\n'
    );
}
