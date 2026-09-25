// resources/js/config/mermaidTemplates.ts

export interface MermaidTemplate {
    id: string;
    label: string;
    group: 'Diagrams' | 'Charts';
    // Header keywords that identify this type on the first line of the source
    keywords: string[];
    source: string;
}

export const mermaidTemplates: MermaidTemplate[] = [
    // ---------------------------------------------------------------- Diagrams
    {
        id: 'flowchart',
        label: 'Flowchart',
        group: 'Diagrams',
        keywords: ['flowchart', 'graph'],
        source: `flowchart TD
  A[Start] --> B{Is it working?}
  B -- Yes --> C[Ship it]
  B -- No --> D[Debug]
  D --> B`,
    },
    {
        id: 'sequence',
        label: 'Sequence',
        group: 'Diagrams',
        keywords: ['sequenceDiagram'],
        source: `sequenceDiagram
  participant U as User
  participant A as App
  participant DB as Database
  U->>A: Save note
  A->>DB: UPDATE notes
  DB-->>A: OK
  A-->>U: Saved`,
    },
    {
        id: 'class',
        label: 'Class',
        group: 'Diagrams',
        keywords: ['classDiagram', 'classDiagram-v2'],
        source: `classDiagram
  class User {
    +int id
    +string name
    +notes() HasMany
  }
  class Note {
    +int id
    +string title
    +user() BelongsTo
  }
  User "1" --> "*" Note : owns`,
    },
    {
        id: 'state',
        label: 'State',
        group: 'Diagrams',
        keywords: ['stateDiagram', 'stateDiagram-v2'],
        source: `stateDiagram-v2
  [*] --> Draft
  Draft --> Review : submit
  Review --> Draft : request changes
  Review --> Published : approve
  Published --> [*]`,
    },
    {
        id: 'er',
        label: 'Entity relationship',
        group: 'Diagrams',
        keywords: ['erDiagram'],
        source: `erDiagram
  USER ||--o{ NOTE : writes
  USER {
    int id PK
    string email
  }
  NOTE {
    int id PK
    int user_id FK
    string title
  }`,
    },
    {
        id: 'requirement',
        label: 'Requirement',
        group: 'Diagrams',
        keywords: ['requirementDiagram'],
        source: `requirementDiagram
  requirement autosave {
    id: 1
    text: Notes save automatically
    risk: medium
    verifymethod: test
  }
  element note_page {
    type: page
  }
  note_page - satisfies -> autosave`,
    },
    {
        id: 'c4',
        label: 'C4 context',
        group: 'Diagrams',
        keywords: [
            'C4Context',
            'C4Container',
            'C4Component',
            'C4Dynamic',
            'C4Deployment',
        ],
        source: `C4Context
  title System context
  Person(user, "User", "Writes notes")
  System(app, "Notes app", "Laravel + Inertia")
  SystemDb(db, "Database", "PostgreSQL")
  Rel(user, app, "Uses")
  Rel(app, db, "Reads / writes")`,
    },
    {
        id: 'architecture',
        label: 'Architecture',
        group: 'Diagrams',
        keywords: ['architecture', 'architecture-beta'],
        source: `architecture-beta
  group cloud(cloud)[Cloud]
  service web(internet)[Web] in cloud
  service app(server)[App] in cloud
  service db(database)[Database] in cloud
  web:R -- L:app
  app:R -- L:db`,
    },
    {
        id: 'block',
        label: 'Block',
        group: 'Diagrams',
        keywords: ['block', 'block-beta'],
        source: `block-beta
  columns 3
  Browser space Server
  space:3
  Cache space Database
  Browser --> Server
  Server --> Database
  Server --> Cache`,
    },
    {
        id: 'git',
        label: 'Git graph',
        group: 'Diagrams',
        keywords: ['gitGraph'],
        source: `gitGraph
  commit
  branch feature
  checkout feature
  commit
  commit
  checkout main
  merge feature
  commit`,
    },
    {
        id: 'mindmap',
        label: 'Mindmap',
        group: 'Diagrams',
        keywords: ['mindmap'],
        source: `mindmap
  root((Notes))
    Editor
      Callouts
      Code blocks
    Diagrams
      Flowcharts
      Charts`,
    },
    {
        id: 'timeline',
        label: 'Timeline',
        group: 'Diagrams',
        keywords: ['timeline'],
        source: `timeline
  title Release history
  2024 : Scaffold app
  2025 : Notes : Tiptap editor
  2026 : Diagrams`,
    },
    {
        id: 'journey',
        label: 'User journey',
        group: 'Diagrams',
        keywords: ['journey'],
        source: `journey
  title Writing a note
  section Create
    Open notes: 5: User
    Click new note: 4: User
  section Write
    Type content: 5: User
    Add a diagram: 3: User`,
    },
    {
        id: 'kanban',
        label: 'Kanban',
        group: 'Diagrams',
        keywords: ['kanban'],
        source: `kanban
  todo[To do]
    t1[Write docs]
  doing[In progress]
    t2[Mermaid support]
  done[Done]
    t3[Notes page]`,
    },

    // ------------------------------------------------------------------ Charts
    {
        id: 'pie',
        label: 'Pie chart',
        group: 'Charts',
        keywords: ['pie'],
        source: `pie title Time spent
  "Writing" : 45
  "Editing" : 30
  "Research" : 25`,
    },
    {
        id: 'xychart',
        label: 'Bar / line chart',
        group: 'Charts',
        keywords: ['xychart', 'xychart-beta'],
        source: `xychart-beta
  title "Notes per month"
  x-axis [Jan, Feb, Mar, Apr, May, Jun]
  y-axis "Notes" 0 --> 50
  bar [12, 18, 25, 22, 34, 41]
  line [12, 18, 25, 22, 34, 41]`,
    },
    {
        id: 'gantt',
        label: 'Gantt chart',
        group: 'Charts',
        keywords: ['gantt'],
        source: `gantt
  title Project plan
  dateFormat YYYY-MM-DD
  section Build
    Notes backend :done, a1, 2026-09-01, 5d
    Editor page   :active, a2, after a1, 7d
  section Ship
    Testing       :a3, after a2, 4d
    Release       :milestone, after a3, 0d`,
    },
    {
        id: 'quadrant',
        label: 'Quadrant chart',
        group: 'Charts',
        keywords: ['quadrantChart'],
        source: `quadrantChart
  title Effort vs impact
  x-axis Low effort --> High effort
  y-axis Low impact --> High impact
  quadrant-1 Plan carefully
  quadrant-2 Do first
  quadrant-3 Maybe later
  quadrant-4 Avoid
  Autosave: [0.3, 0.9]
  Diagrams: [0.6, 0.7]
  Themes: [0.2, 0.3]`,
    },
    {
        id: 'sankey',
        label: 'Sankey',
        group: 'Charts',
        keywords: ['sankey', 'sankey-beta'],
        source: `sankey-beta
Visitors,Sign ups,40
Visitors,Bounced,60
Sign ups,Active,30
Sign ups,Churned,10`,
    },
    {
        id: 'radar',
        label: 'Radar chart',
        group: 'Charts',
        keywords: ['radar', 'radar-beta'],
        source: `radar-beta
  title Editor features
  axis speed["Speed"], ux["UX"], blocks["Blocks"], export["Export"], search["Search"]
  curve now["Now"]{4, 3, 4, 1, 2}
  curve goal["Goal"]{5, 5, 5, 4, 4}
  max 5`,
    },
    {
        id: 'treemap',
        label: 'Treemap',
        group: 'Charts',
        keywords: ['treemap', 'treemap-beta'],
        source: `treemap-beta
"Storage"
  "Notes": 60
  "Images": 30
  "Other": 10`,
    },
    {
        id: 'packet',
        label: 'Packet',
        group: 'Charts',
        keywords: ['packet', 'packet-beta'],
        source: `packet-beta
  0-15: "Source port"
  16-31: "Destination port"
  32-63: "Sequence number"`,
    },
];

export const defaultMermaidTemplate = mermaidTemplates[0];

/**
 * Detects the template matching a diagram's header keyword,
 * skipping blank lines, %% comments and --- front matter.
 */
export function detectMermaidTemplate(
    source: string,
): MermaidTemplate | undefined {
    const lines = source.split('\n');
    let inFrontMatter = false;

    for (const raw of lines) {
        const line = raw.trim();

        if (line === '---') {
            inFrontMatter = !inFrontMatter;
            continue;
        }

        if (inFrontMatter || line === '' || line.startsWith('%%')) {
            continue;
        }

        const keyword = line.split(/\s/)[0];

        return mermaidTemplates.find((template) =>
            template.keywords.includes(keyword),
        );
    }

    return undefined;
}
