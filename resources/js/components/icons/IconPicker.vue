<script setup lang="ts">
import { Palette } from '@lucide/vue';
import { computed, ref } from 'vue';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { DEFAULT_ICON, ICON_COLORS, ICON_GROUPS, iconNamed } from '@/lib/icons';
import { cn } from '@/lib/utils';
import IconByName from './IconByName.vue';

// The icon a thing shows, as a small button that opens the set to choose
// another from -- searched by name -- and, if colorable, the colour it is
// drawn in. Disabled, it is only the icon.

const props = defineProps<{
    /** What the icon is for, as a screen reader says it: "Food's icon". */
    label: string;
    /** Offer colours to choose from, as well as icons. */
    colorable?: boolean;
    disabled?: boolean;
    class?: string;
}>();

const name = defineModel<string | null>({ default: DEFAULT_ICON });
/** The colour it is drawn in; the text colour if not given. */
const color = defineModel<string | null>('color');

const open = ref(false);
const term = ref('');

const groups = computed(() => {
    const wanted = term.value.trim().toLowerCase();

    return wanted
        ? ICON_GROUPS.map((group) => ({
              ...group,
              icons: group.icons.filter(
                  (each) =>
                      each.label.toLowerCase().includes(wanted) ||
                      each.name.includes(wanted),
              ),
          })).filter((group) => group.icons.length)
        : ICON_GROUPS;
});

const current = computed(() => iconNamed(name.value).name);

const pick = (picked: string) => {
    name.value = picked;
    open.value = false;
    term.value = '';
};
</script>

<template>
    <IconByName
        v-if="disabled"
        :name="name"
        :class="cn('size-4 shrink-0', props.class)"
        :style="{ color: color ?? undefined }"
    />
    <Popover v-else v-model:open="open">
        <PopoverTrigger as-child>
            <button
                type="button"
                class="hover:bg-accent -m-1 shrink-0 rounded p-1"
                :aria-label="`${label}: ${iconNamed(name).label}. Change`"
                data-test="icon-picker"
                @click.stop
            >
                <IconByName
                    :name="name"
                    :class="cn('size-4', props.class)"
                    :style="{ color: color ?? undefined }"
                />
            </button>
        </PopoverTrigger>
        <PopoverContent align="start" class="w-72 p-2" @click.stop>
            <div
                v-if="colorable"
                class="mb-2 flex flex-wrap items-center gap-1"
                data-test="icon-colors"
            >
                <button
                    v-for="each in ICON_COLORS"
                    :key="each"
                    type="button"
                    class="size-5 rounded-full"
                    :class="
                        each === color?.toLowerCase() &&
                        'ring-ring ring-offset-background ring-2 ring-offset-1'
                    "
                    :style="{ background: each }"
                    :aria-label="`Colour ${each}`"
                    :aria-pressed="each === color?.toLowerCase()"
                    :data-test="`color-${each}`"
                    @click="color = each"
                />
                <label
                    class="hover:bg-accent relative ml-auto flex size-6 cursor-pointer items-center justify-center rounded"
                    title="Another colour"
                >
                    <Palette class="text-muted-foreground size-4" />
                    <input
                        type="color"
                        class="absolute inset-0 cursor-pointer opacity-0"
                        aria-label="Another colour"
                        :value="color ?? '#3b82f6'"
                        @change="
                            color = ($event.target as HTMLInputElement).value
                        "
                    />
                </label>
            </div>
            <input
                v-model="term"
                placeholder="Search icons"
                aria-label="Search icons"
                class="bg-background dark:bg-input/30 mb-2 h-8 w-full rounded-md border px-2 text-sm"
            />
            <div class="max-h-72 overflow-y-auto">
                <section v-for="group in groups" :key="group.label">
                    <h3
                        class="text-muted-foreground px-1 pt-1 pb-0.5 text-xs font-medium"
                    >
                        {{ group.label }}
                    </h3>
                    <div class="grid grid-cols-8 gap-0.5">
                        <button
                            v-for="each in group.icons"
                            :key="each.name"
                            type="button"
                            class="hover:bg-accent flex aspect-square items-center justify-center rounded"
                            :class="
                                each.name === current &&
                                'bg-accent ring-ring ring-1'
                            "
                            :title="each.label"
                            :aria-label="each.label"
                            :aria-pressed="each.name === current"
                            :data-test="`icon-${each.name}`"
                            @click="pick(each.name)"
                        >
                            <component
                                :is="each.icon"
                                class="size-4"
                                :style="{ color: color ?? undefined }"
                            />
                        </button>
                    </div>
                </section>
                <p
                    v-if="!groups.length"
                    class="text-muted-foreground px-1 py-2 text-xs"
                >
                    No icon called that.
                </p>
            </div>
        </PopoverContent>
    </Popover>
</template>
