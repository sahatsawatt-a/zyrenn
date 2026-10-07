<script setup lang="ts">
import { Check, Search } from '@lucide/vue';
import { computed, ref } from 'vue';
import { getInitials } from '@/composables/useInitials';
import { colourFor } from '@/lib/live';

export type Person = { id: number; name: string; email: string };

// People to choose from, searched by name or email: one to message, or several for a group
const props = defineProps<{
    people: Person[];
    // Several can be chosen; otherwise a click is the choice
    multiple?: boolean;
    selected?: number[];
}>();

const emit = defineEmits<{
    pick: [person: Person];
    'update:selected': [ids: number[]];
}>();

const query = ref('');

const shown = computed(() => {
    const terms = query.value.toLowerCase().split(/\s+/).filter(Boolean);

    return props.people.filter((person) => {
        const text = `${person.name} ${person.email}`.toLowerCase();

        return terms.every((term) => text.includes(term));
    });
});

const isChosen = (id: number) => props.selected?.includes(id) ?? false;

function choose(person: Person) {
    if (!props.multiple) {
        emit('pick', person);

        return;
    }

    const now = props.selected ?? [];

    emit(
        'update:selected',
        isChosen(person.id)
            ? now.filter((id) => id !== person.id)
            : [...now, person.id],
    );
}
</script>

<template>
    <div class="rounded-md border">
        <div class="flex items-center gap-2 border-b px-3">
            <Search class="text-muted-foreground size-4 shrink-0" />
            <input
                v-model="query"
                class="placeholder:text-muted-foreground h-10 w-full bg-transparent text-sm outline-none"
                placeholder="Search by name or email"
                aria-label="Search people"
                autocomplete="off"
            />
        </div>
        <div class="max-h-64 overflow-y-auto p-1" role="listbox">
            <button
                v-for="person in shown"
                :key="person.id"
                type="button"
                role="option"
                :aria-selected="isChosen(person.id)"
                class="hover:bg-accent flex w-full items-center gap-3 rounded-sm px-2 py-1.5 text-left"
                data-person
                @click="choose(person)"
            >
                <span
                    class="flex size-7 shrink-0 items-center justify-center rounded-full text-[11px] font-semibold text-white"
                    :style="{ backgroundColor: colourFor(person.id) }"
                >
                    {{ getInitials(person.name) }}
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm">{{
                        person.name
                    }}</span>
                    <span class="text-muted-foreground block truncate text-xs">
                        {{ person.email }}
                    </span>
                </span>
                <Check
                    v-if="multiple"
                    class="size-4 shrink-0"
                    :class="isChosen(person.id) ? 'opacity-100' : 'opacity-0'"
                />
            </button>
            <p
                v-if="!shown.length"
                class="text-muted-foreground px-2 py-3 text-xs"
            >
                {{
                    people.length
                        ? 'Nobody matches.'
                        : 'Nobody to choose from yet.'
                }}
            </p>
        </div>
    </div>
</template>
