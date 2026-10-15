import type { Tour, TourStep } from './tour';

// The tours there are, each offered on a topic of the Guide. Each finds
// things by what they say, as a person would, so a tour stays right as long
// as the screen does.

const MENU = '[data-sidebar="menu-button"]';
const BOARD = '[data-board-canvas] canvas, .konvajs-content canvas';

// On a phone the menu is folded behind a button: opened first
const openMenu: TourStep = {
    say: 'The menu is folded away behind this button.',
    do: 'click',
    at: '[data-sidebar="trigger"]',
    narrow: true,
};

export const TOURS: Tour[] = [
    {
        id: 'around',
        topic: 'getting-started',
        title: 'Find your way around',
        about: 'What each part of the app is for, one by one.',
        steps: [
            openMenu,
            {
                say: 'Notes: pages you write, with tables, boards and pictures in them.',
                at: MENU,
                text: 'Notes',
            },
            {
                say: 'Boards: an endless whiteboard for shapes, sticky notes and diagrams.',
                at: MENU,
                text: 'Boards',
            },
            {
                say: 'Tables: rows and columns, with formulas and views of their own.',
                at: MENU,
                text: 'Tables',
            },
            {
                say: 'Drive: your files and pictures, for any page to use.',
                at: MENU,
                text: 'Drive',
            },
            {
                say: 'Workflows: steps that run by themselves, on a timetable or when a message comes in.',
                at: MENU,
                text: 'Workflows',
            },
            {
                say: 'And the Guide, for how all of it works whenever you need it.',
                at: MENU,
                text: 'Guide',
            },
        ],
    },
    {
        id: 'note',
        topic: 'notes',
        title: 'Write a note',
        about: 'Make a note, give it a title, and write in it.',
        makes: 'a note called "A note from the tour", in your Notes',
        steps: [
            openMenu,
            {
                say: 'Notes are kept here.',
                do: 'click',
                at: MENU,
                text: 'Notes',
            },
            {
                say: 'Start a new one.',
                do: 'click',
                at: 'button',
                text: 'New note',
            },
            {
                say: 'Give it a title.',
                do: 'type',
                at: '[aria-label="Note title"]',
                value: 'A note from the tour',
            },
            {
                say: 'Enter takes you down into the page.',
                do: 'press',
                at: '[aria-label="Note title"]',
                key: 'Enter',
            },
            {
                say: 'Write as you would anywhere. Type / for a table, a board, a picture and more.',
                do: 'type',
                at: '.tiptap',
                value: 'Notes keep themselves as you write. Type / for a table, a board, a picture and more.',
            },
            {
                say: 'It is saved for you as you go: there is no Save button.',
                text: 'Saved',
            },
        ],
    },
    {
        id: 'board',
        topic: 'getting-started',
        title: 'Make a board',
        about: 'Start a whiteboard and put a sticky note on it.',
        makes: 'a board called "A board from the tour", in your Boards',
        steps: [
            openMenu,
            {
                say: 'Boards are kept here.',
                do: 'click',
                at: MENU,
                text: 'Boards',
            },
            {
                say: 'Start a new one.',
                do: 'click',
                at: 'button',
                text: 'New board',
            },
            {
                say: 'Give it a name.',
                do: 'type',
                at: '[placeholder="Untitled board"]',
                value: 'A board from the tour',
            },
            {
                say: 'What can go on a board is in this bar. This is a sticky note.',
                do: 'click',
                at: '[data-test="tool-sticky"]',
            },
            {
                say: 'Press and drag where it should go.',
                do: 'drag',
                at: '[data-board-canvas] canvas, .konvajs-content canvas',
                from: [0.4, 0.35],
                to: [0.55, 0.55],
            },
            {
                say: 'Double-click anything on the board to write on it. That is a board: there is no Save button here either.',
                at: '[data-board-canvas] canvas, .konvajs-content canvas',
            },
        ],
    },
    {
        id: 'shapes',
        topic: 'boards',
        title: 'Join two shapes',
        about: 'Put a shape and a sticky note on a board, and join them.',
        makes: 'a board called "Shapes from the tour", in your Boards',
        steps: [
            openMenu,
            {
                say: 'Boards are kept here.',
                do: 'click',
                at: MENU,
                text: 'Boards',
            },
            {
                say: 'Start a new one.',
                do: 'click',
                at: 'button',
                text: 'New board',
            },
            {
                say: 'Give it a name.',
                do: 'type',
                at: '[placeholder="Untitled board"]',
                value: 'Shapes from the tour',
            },
            {
                say: 'Every shape there is, with a search, is behind Shapes.',
                do: 'click',
                at: '[data-test="tool-shapes"]',
            },
            {
                say: 'A rectangle, for a step.',
                do: 'click',
                at: '[data-test="tool-rect"]',
            },
            {
                say: 'Press and drag where it should go.',
                do: 'drag',
                at: BOARD,
                from: [0.25, 0.35],
                to: [0.4, 0.5],
            },
            {
                say: 'And a sticky note beside it.',
                do: 'click',
                at: '[data-test="tool-sticky"]',
            },
            {
                say: 'Drag it out too.',
                do: 'drag',
                at: BOARD,
                from: [0.6, 0.3],
                to: [0.75, 0.55],
            },
            {
                say: 'The connector joins one thing to another.',
                do: 'click',
                at: '[data-test="tool-arrow"]',
            },
            {
                say: 'Drag from the rectangle to the sticky note. Move either one later, and the line stays joined.',
                do: 'drag',
                at: BOARD,
                from: [0.325, 0.425],
                to: [0.675, 0.425],
            },
            {
                say: 'Present plays the board’s frames one screen at a time, like slides.',
                at: '[data-test="present"]',
            },
            {
                say: 'Export saves it as a PDF, or each frame as a picture.',
                at: '[title="Export as a PDF or a picture"]',
            },
        ],
    },
    {
        id: 'table',
        topic: 'tables',
        title: 'Start a table',
        about: 'Make a table, and see where its fields, filters and sorting are.',
        makes: 'a table called "A table from the tour", in your Tables',
        steps: [
            openMenu,
            {
                say: 'Tables are kept here.',
                do: 'click',
                at: MENU,
                text: 'Tables',
            },
            {
                say: 'Start a new one.',
                do: 'click',
                at: 'button',
                text: 'New table',
            },
            {
                say: 'Give it a name.',
                do: 'type',
                at: '[placeholder="Untitled table"]',
                value: 'A table from the tour',
            },
            {
                say: 'Add a field here: give it a name, and say what kind of value it holds.',
                at: '[title="Add a field"]',
            },
            {
                say: 'Search looks through every field at once.',
                at: '[placeholder="Search rows"]',
            },
            {
                say: 'Filter shows only the rows that match.',
                at: 'button',
                text: 'Filter',
            },
            {
                say: 'Sort puts them in order, by any field.',
                at: 'button',
                text: 'Sort',
            },
            {
                say: 'And Export downloads the rows on show as a CSV file.',
                at: '[title="Download the rows shown as a CSV file"]',
            },
        ],
    },
    {
        id: 'places',
        topic: 'maps-and-trips',
        title: 'Maps and trips',
        about: 'Where places are found and kept, and where trips are planned.',
        steps: [
            openMenu,
            {
                say: 'Places you keep are in Maps.',
                do: 'click',
                at: MENU,
                text: 'Maps',
            },
            {
                say: 'Search for a place here, or click anywhere on the map.',
                at: '[placeholder="Search places"]',
            },
            {
                say: 'Saved places go in lists of your own, here.',
                text: 'Your places',
            },
            {
                say: 'Click a place on the map for its card: its address, its opening hours, and Directions to it.',
                at: '.maplibregl-canvas',
            },
            openMenu,
            {
                say: 'Trips are planned in Trips.',
                do: 'click',
                at: MENU,
                text: 'Trips',
            },
            {
                say: 'A new trip has days, hotels and flights, and each day its stops in order.',
                at: 'button',
                text: 'New trip',
            },
        ],
    },
    {
        id: 'drive',
        topic: 'drive',
        title: 'The Drive',
        about: 'Where files go in, and how they are found again.',
        steps: [
            openMenu,
            {
                say: 'Your files are in the Drive.',
                do: 'click',
                at: MENU,
                text: 'Drive',
            },
            {
                say: 'Upload files here, or drop them on the page.',
                at: 'button',
                text: 'Upload',
            },
            {
                say: 'Folders keep them in order: drag a file onto one to move it. A picture’s own menu has Edit photo.',
                at: 'button',
                text: 'New folder',
            },
        ],
    },
    {
        id: 'chat',
        topic: 'chat',
        title: 'Find your chats',
        about: 'Your own rooms, messages with people, and project groups.',
        steps: [
            openMenu,
            { say: 'Chat is here.', do: 'click', at: MENU, text: 'Chat' },
            {
                say: 'A chat room is yours, for you and your workflows to write in.',
                at: 'button',
                text: 'New Chat Room',
            },
            {
                say: 'New message writes to anyone you share a project with.',
                at: 'button',
                text: 'New message',
            },
            {
                say: 'One to one conversations are listed here.',
                text: 'Direct messages',
            },
            {
                say: 'And a project’s groups here.',
                at: 'h2, h3, p, span',
                text: 'Groups',
            },
        ],
    },
    {
        id: 'projects',
        topic: 'projects',
        title: 'Switch to a project',
        about: 'Where projects are started and switched between.',
        steps: [
            openMenu,
            {
                say: 'This says whose space you are in: your own, or a project’s.',
                do: 'click',
                at: '[data-test="project-switcher"]',
            },
            {
                say: 'Start a project here, then add people to it from its settings.',
                at: '[data-test="new-project"]',
            },
            {
                say: 'Close the list again.',
                do: 'press',
                key: 'Escape',
                at: 'body',
            },
            // On a phone the menu is still open: a press beside it closes it
            {
                say: 'And the menu folds away again.',
                do: 'click',
                at: '[data-sidebar="trigger"]',
                narrow: true,
            },
        ],
    },
    {
        id: 'workflow',
        topic: 'workflows',
        title: 'Start a workflow',
        about: 'Where workflows are made, and the templates to start from.',
        steps: [
            openMenu,
            {
                say: 'Workflows are kept here.',
                do: 'click',
                at: MENU,
                text: 'Workflows',
            },
            {
                say: 'New workflow starts an empty one, from what sets it off.',
                at: 'button',
                text: 'New workflow',
            },
            {
                say: 'Or start from a template, already joined up.',
                do: 'click',
                at: '[data-from-template]',
            },
            {
                say: 'Each says what it does. Choosing one makes it, ready for your connections.',
                at: '[role="dialog"]',
            },
            { say: 'Close it again.', do: 'press', key: 'Escape', at: 'body' },
        ],
    },
    {
        id: 'settings',
        topic: 'settings',
        title: 'Connect an AI client',
        about: 'Where the token an AI client needs is made.',
        steps: [
            openMenu,
            {
                say: 'Your settings are under your name.',
                do: 'click',
                at: '[data-test="sidebar-menu-button"]',
            },
            {
                say: 'Open Settings.',
                do: 'click',
                at: '[role="menuitem"]',
                text: 'Settings',
            },
            {
                say: 'MCP is for AI clients.',
                do: 'click',
                // By where it goes: the Guide has "MCP" in its own links
                at: 'a[href$="/settings/mcp"]',
            },
            {
                say: 'Name a token after the client it is for, then press Create token and copy it.',
                at: '[placeholder="e.g. Claude Code on laptop"]',
            },
            {
                say: 'Then choose your client, and paste what it shows into it.',
                text: 'Connect a client',
            },
        ],
    },
];

/** The tours offered on one topic of the Guide. */
export const toursFor = (topic: string) =>
    TOURS.filter((tour) => tour.topic === topic);
