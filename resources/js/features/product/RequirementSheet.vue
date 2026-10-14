<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Check, GitBranchPlus, Plus, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/common/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import {
    KINDS,
    REQUIREMENT_STATUSES,
    UNDERSTANDING,
    options,
} from '@/features/product/product';
import type {
    Element,
    Requirement,
    TicketRef,
} from '@/features/product/product';
import TicketPick from '@/features/tickets/components/TicketPick.vue';
import { update } from '@/routes/product/requirements';
import { store as storeRequirement } from '@/routes/projects/product/requirements';

// One requirement, opened beside the list: what it must do and the need it
// serves; how well it is understood, and what is still to be asked or is
// only assumed; the criteria it is checked against -- verified only once
// every one is met -- and the parts of the product and the work it is about.

const open = defineModel<boolean>('open', { required: true });

const props = defineProps<{
    requirement: Requirement;
    elements: Element[];
    tickets: TicketRef[];
    people: { id: number; name: string }[];
    project: string;
    canChange: boolean;
}>();

const emit = defineEmits<{
    element: [ref: string];
    requirement: [ref: string];
}>();

const RELOADED = ['elements', 'requirements', 'coverage', 'requests'];
const errors = ref<Record<string, string>>({});

// One change after another, never one cancelling the last; a change made of
// what is there is worked out when its turn comes, from what is there then
let saving = Promise.resolve();

const change = (
    data: Record<string, unknown> | (() => Record<string, unknown>),
) => {
    const ref_id = props.requirement.ref_id;

    saving = saving.then(
        () =>
            new Promise<void>((done) => {
                errors.value = {};
                router.patch(
                    update(ref_id).url,
                    (typeof data === 'function' ? data() : data) as never,
                    {
                        preserveScroll: true,
                        preserveState: true,
                        only: RELOADED,
                        onError: (problems) => (errors.value = problems),
                        onFinish: () => done(),
                    },
                );
            }),
    );
};

const title = ref('');
const need = ref('');
const body = ref('');

watch(
    () => props.requirement,
    (now) => {
        title.value = now.title;
        need.value = now.need ?? '';
        body.value = now.body ?? '';
    },
    { immediate: true },
);

// ------------------------------------------------- Lists: asked, assumed, checked
const adding = ref({ questions: '', assumptions: '', criteria: '' });

function addTo(list: 'questions' | 'assumptions') {
    const text = adding.value[list].trim();

    if (text) {
        change(() => ({ [list]: [...props.requirement[list], text] }));
        adding.value[list] = '';
    }
}

const dropFrom = (list: 'questions' | 'assumptions', at: number) =>
    change(() => ({
        [list]: props.requirement[list].filter((_, i) => i !== at),
    }));

function addCriterion() {
    const text = adding.value.criteria.trim();

    if (text) {
        change(() => ({
            criteria: [...props.requirement.criteria, { text, met: false }],
        }));
        adding.value.criteria = '';
    }
}

const tick = (at: number) =>
    change(() => ({
        criteria: props.requirement.criteria.map((one, i) =>
            i === at ? { ...one, met: !one.met } : one,
        ),
    }));

const dropCriterion = (at: number) =>
    change(() => ({
        criteria: props.requirement.criteria.filter((_, i) => i !== at),
    }));

const met = computed(
    () => props.requirement.criteria.filter((one) => one.met).length,
);

// ---------------------------------------------------------- Parts and work
const linkedElements = computed(() =>
    props.requirement.elements.map((e) => e.ref_id),
);

const elementChoices = computed(() =>
    props.elements
        .filter((e) => !linkedElements.value.includes(e.ref_id))
        .map((e) => ({ value: e.ref_id, label: `${e.code} ${e.name}` })),
);

const ticketChoices = computed(() =>
    props.tickets
        .filter(
            (t) =>
                !props.requirement.tickets.some(
                    (linked) => linked.number === t.number,
                ),
        )
        .map((t) => ({
            value: String(t.number),
            label: `#${t.number} ${t.title}`,
        })),
);

const NOBODY = '__nobody__';

const owners = computed(() => [
    { value: NOBODY, label: 'Nobody yet' },
    ...props.people.map((p) => ({ value: String(p.id), label: p.name })),
]);

// A change to it: a requirement of its own, naming this one, which stays as it was
const changing = () =>
    router.post(
        storeRequirement(props.project).url,
        {
            title: `Change: ${props.requirement.title}`,
            changes: props.requirement.ref_id,
        },
        { preserveScroll: true, only: RELOADED },
    );
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent
            class="flex w-full flex-col gap-0 overflow-y-auto p-0 sm:max-w-xl"
            data-test="requirement-sheet"
        >
            <SheetHeader class="border-b px-5 py-4 text-left">
                <SheetTitle class="flex items-center gap-2">
                    <span class="text-muted-foreground font-mono text-xs">{{
                        requirement.code
                    }}</span>
                    <span class="truncate">{{ requirement.title }}</span>
                </SheetTitle>
                <SheetDescription>
                    <template v-if="requirement.request"
                        >From request {{ requirement.request.code }}.
                    </template>
                    <template v-if="requirement.changes">
                        A change to
                        <button
                            type="button"
                            class="underline"
                            @click="
                                emit('requirement', requirement.changes.ref_id)
                            "
                        >
                            {{ requirement.changes.code }}</button
                        >, which is kept as it was.
                    </template>
                </SheetDescription>
            </SheetHeader>

            <fieldset :disabled="!canChange" class="space-y-6 px-5 py-5">
                <section class="grid grid-cols-1 gap-3">
                    <div class="grid grid-cols-1 gap-1.5">
                        <Label for="req-title">What it must do</Label>
                        <Input
                            id="req-title"
                            v-model="title"
                            maxlength="200"
                            @change="title.trim() && change({ title })"
                        />
                    </div>
                    <div class="grid grid-cols-1 gap-1.5">
                        <Label for="req-need">The need it serves</Label>
                        <textarea
                            id="req-need"
                            v-model="need"
                            rows="2"
                            placeholder="The problem behind the request"
                            class="border-input focus-visible:ring-ring/50 rounded-md border bg-transparent px-3 py-2 text-sm outline-none focus-visible:ring-[3px]"
                            @change="change({ need })"
                        />
                    </div>
                    <div class="grid grid-cols-1 gap-1.5">
                        <Label for="req-body">More about it</Label>
                        <textarea
                            id="req-body"
                            v-model="body"
                            rows="3"
                            class="border-input focus-visible:ring-ring/50 rounded-md border bg-transparent px-3 py-2 text-sm outline-none focus-visible:ring-[3px]"
                            @change="change({ body })"
                        />
                    </div>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div class="grid grid-cols-1 gap-1.5">
                            <Label>Status</Label>
                            <TicketPick
                                :model-value="requirement.status"
                                :options="options(REQUIREMENT_STATUSES)"
                                label="Status"
                                data-test="req-status"
                                @update:model-value="change({ status: $event })"
                            />
                            <InputError :message="errors.status" />
                        </div>
                        <div class="grid grid-cols-1 gap-1.5">
                            <Label>Understood</Label>
                            <TicketPick
                                :model-value="requirement.understanding"
                                :options="options(UNDERSTANDING)"
                                label="Understood"
                                @update:model-value="
                                    change({ understanding: $event })
                                "
                            />
                        </div>
                        <div class="grid grid-cols-1 gap-1.5">
                            <Label>Kind</Label>
                            <TicketPick
                                :model-value="requirement.kind"
                                :options="options(KINDS)"
                                label="Kind"
                                @update:model-value="change({ kind: $event })"
                            />
                        </div>
                        <div class="grid grid-cols-1 gap-1.5">
                            <Label>Owner</Label>
                            <TicketPick
                                :model-value="
                                    requirement.owner
                                        ? String(requirement.owner.id)
                                        : NOBODY
                                "
                                :options="owners"
                                label="Owner"
                                @update:model-value="
                                    change({
                                        owner:
                                            $event === NOBODY
                                                ? null
                                                : Number($event),
                                    })
                                "
                            />
                        </div>
                    </div>
                </section>

                <!-- What it is checked against -->
                <section
                    class="grid grid-cols-1 gap-2"
                    data-test="req-criteria"
                >
                    <Label>
                        Checked against
                        <span class="text-muted-foreground font-normal"
                            >· {{ met }} of
                            {{ requirement.criteria.length }} met</span
                        >
                    </Label>
                    <ul class="grid grid-cols-1 gap-1">
                        <li
                            v-for="(one, at) in requirement.criteria"
                            :key="at"
                            class="flex items-start gap-2 text-sm"
                        >
                            <button
                                type="button"
                                class="mt-0.5 flex size-4 shrink-0 items-center justify-center rounded border"
                                :class="
                                    one.met
                                        ? 'border-emerald-500 bg-emerald-500 text-white'
                                        : ''
                                "
                                :aria-pressed="one.met"
                                :aria-label="
                                    one.met
                                        ? `Not met: ${one.text}`
                                        : `Met: ${one.text}`
                                "
                                data-test="req-criterion"
                                @click="tick(at)"
                            >
                                <Check v-if="one.met" class="size-3" />
                            </button>
                            <span
                                class="min-w-0 flex-1"
                                :class="{ 'text-muted-foreground': one.met }"
                                >{{ one.text }}</span
                            >
                            <button
                                type="button"
                                class="text-muted-foreground"
                                :aria-label="`Take away: ${one.text}`"
                                @click="dropCriterion(at)"
                            >
                                <X class="size-3.5" />
                            </button>
                        </li>
                    </ul>
                    <form class="flex gap-2" @submit.prevent="addCriterion">
                        <Input
                            v-model="adding.criteria"
                            class="h-8 text-sm"
                            placeholder="e.g. A colour can be typed as #RRGGBB"
                            data-test="req-criterion-input"
                        />
                        <Button size="sm" variant="outline" type="submit"
                            ><Plus
                        /></Button>
                    </form>
                </section>

                <!-- Still to be asked, and only assumed -->
                <section
                    v-for="list in ['questions', 'assumptions'] as const"
                    :key="list"
                    class="grid grid-cols-1 gap-2"
                >
                    <Label>{{
                        list === 'questions'
                            ? 'Still to be asked'
                            : 'Assumed, not confirmed'
                    }}</Label>
                    <ul class="grid grid-cols-1 gap-1">
                        <li
                            v-for="(text, at) in requirement[list]"
                            :key="at"
                            class="flex items-start gap-2 text-sm"
                        >
                            <span class="text-muted-foreground">{{
                                list === 'questions' ? '?' : '~'
                            }}</span>
                            <span class="min-w-0 flex-1">{{ text }}</span>
                            <button
                                type="button"
                                class="text-muted-foreground"
                                aria-label="Take it away"
                                @click="dropFrom(list, at)"
                            >
                                <X class="size-3.5" />
                            </button>
                        </li>
                    </ul>
                    <form class="flex gap-2" @submit.prevent="addTo(list)">
                        <Input
                            v-model="adding[list]"
                            class="h-8 text-sm"
                            :placeholder="
                                list === 'questions'
                                    ? 'e.g. RGB too, or HEX only?'
                                    : 'e.g. The colour picker stays shared'
                            "
                        />
                        <Button size="sm" variant="outline" type="submit"
                            ><Plus
                        /></Button>
                    </form>
                </section>

                <!-- The parts of the product it is about -->
                <section class="grid grid-cols-1 gap-2">
                    <Label>About these parts</Label>
                    <div class="flex flex-wrap gap-1.5">
                        <span
                            v-for="one in requirement.elements"
                            :key="one.ref_id"
                            class="flex h-7 items-center gap-1 rounded-full border pr-1 pl-2.5 text-xs"
                            data-test="req-element"
                        >
                            <button
                                type="button"
                                class="hover:underline"
                                @click="emit('element', one.ref_id)"
                            >
                                {{ one.code }} {{ one.name }}
                            </button>
                            <button
                                type="button"
                                class="text-muted-foreground"
                                :aria-label="`Not about ${one.name}`"
                                @click="
                                    change(() => ({
                                        elements: linkedElements.filter(
                                            (r) => r !== one.ref_id,
                                        ),
                                    }))
                                "
                            >
                                <X class="size-3" />
                            </button>
                        </span>
                    </div>
                    <TicketPick
                        v-if="elementChoices.length"
                        model-value=""
                        :options="elementChoices"
                        label="Another part"
                        placeholder="Add a part"
                        compact
                        data-test="req-element-add"
                        @update:model-value="
                            change(() => ({
                                elements: [...linkedElements, $event],
                            }))
                        "
                    />
                </section>

                <!-- The work done for it -->
                <section class="grid grid-cols-1 gap-2">
                    <Label>Work for it</Label>
                    <ul
                        v-if="requirement.tickets.length"
                        class="divide-y rounded-lg border"
                    >
                        <li
                            v-for="one in requirement.tickets"
                            :key="one.number"
                            class="flex items-center gap-2 px-3 py-1.5 text-sm"
                        >
                            <span
                                class="text-muted-foreground font-mono text-[11px]"
                                >#{{ one.number }}</span
                            >
                            <span class="min-w-0 flex-1 truncate">{{
                                one.title
                            }}</span>
                            <span class="text-muted-foreground text-[11px]">{{
                                one.status.replace('_', ' ')
                            }}</span>
                            <button
                                type="button"
                                class="text-muted-foreground"
                                aria-label="Not for this"
                                @click="
                                    change(() => ({
                                        tickets: requirement.tickets
                                            .filter(
                                                (t) => t.number !== one.number,
                                            )
                                            .map((t) => t.number),
                                    }))
                                "
                            >
                                <X class="size-3.5" />
                            </button>
                        </li>
                    </ul>
                    <TicketPick
                        v-if="ticketChoices.length"
                        model-value=""
                        :options="ticketChoices"
                        label="A ticket for it"
                        placeholder="Link a ticket"
                        compact
                        @update:model-value="
                            change(() => ({
                                tickets: [
                                    ...requirement.tickets.map((t) => t.number),
                                    Number($event),
                                ],
                            }))
                        "
                    />
                </section>

                <Button
                    variant="outline"
                    size="sm"
                    data-test="req-change"
                    @click="changing"
                >
                    <GitBranchPlus /> A change to it
                </Button>
            </fieldset>
        </SheetContent>
    </Sheet>
</template>
