import {
    ArrowLeft,
    AtSign,
    Camera,
    EyeOff,
    Eye,
    Globe,
    Keyboard,
    ListChecks,
    MoveDown,
    MousePointer2,
    MousePointerClick,
    Paperclip,
    TextCursorInput,
} from '@lucide/vue';
import type { Component } from 'vue';
import type { StepKind } from './steps';

// A picture for each kind of step, the same wherever a step is shown: in a
// check's steps, in a run, while building one by clicking.
export const KIND_ICONS: Record<StepKind, Component> = {
    open: Globe,
    click: MousePointerClick,
    hover: MousePointer2,
    type: TextCursorInput,
    choose: ListChecks,
    upload: Paperclip,
    press: Keyboard,
    back: ArrowLeft,
    scroll: MoveDown,
    see: Eye,
    gone: EyeOff,
    address: AtSign,
    shot: Camera,
};

// Checking steps stand out from doing ones
export const KIND_TONE: Record<StepKind, string> = {
    open: 'text-sky-600 bg-sky-500/10',
    click: 'text-violet-600 bg-violet-500/10',
    hover: 'text-violet-600 bg-violet-500/10',
    type: 'text-violet-600 bg-violet-500/10',
    choose: 'text-violet-600 bg-violet-500/10',
    upload: 'text-violet-600 bg-violet-500/10',
    press: 'text-violet-600 bg-violet-500/10',
    back: 'text-violet-600 bg-violet-500/10',
    scroll: 'text-violet-600 bg-violet-500/10',
    see: 'text-emerald-600 bg-emerald-500/10',
    gone: 'text-emerald-600 bg-emerald-500/10',
    address: 'text-emerald-600 bg-emerald-500/10',
    shot: 'text-amber-600 bg-amber-500/10',
};
