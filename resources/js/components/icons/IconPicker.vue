<script setup lang="ts">
import { Palette } from '@lucide/vue';
import { computed, ref } from 'vue';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import SearchField from '@/components/common/SearchField.vue';
import { DEFAULT_ICON, ICON_COLORS, ICON_GROUPS, iconNamed } from '@/lib/icons';
import { cn } from '@/lib/utils';
import IconByName from './IconByName.vue';
import Tip from '@/components/common/Tip.vue';

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

// Another colour than the swatches, typed as its code: #3b82f6 or 3b82f6
const choosingOther = ref(false);
const other = ref('');
const otherHex = computed(() => {
    const plain = other.value.trim().replace(/^#/, '');

    return `#${plain.length === 3 ? [...plain].map((c) => c + c).join('') : plain}`.toLowerCase();
});
const otherValid = computed(() => /^#[0-9a-f]{6}$/.test(otherHex.value));

function useOther() {
    if (otherValid.value) {
        color.value = otherHex.value;
        choosingOther.value = false;
    }
}
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
                <Tip
                    v-for="each in ICON_COLORS"
                    :key="each"
                    :text="`Colour ${each}`"
                >
                    <button
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
                </Tip>
                <!-- Any colour, by its code: the app's own, not the system's window -->
                <div class="relative ml-auto">
                    <Tip text="Another colour">
                        <button
                            type="button"
                            class="hover:bg-accent flex size-6 items-center justify-center rounded"
                            aria-label="Another colour"
                            :aria-expanded="choosingOther"
                            data-test="color-other"
                            @click="choosingOther = !choosingOther"
                        >
                            <Palette class="text-muted-foreground size-4" />
                        </button>
                    </Tip>
                </div>
            </div>
            <form
                v-if="choosingOther"
                class="mb-2 flex items-center gap-2"
                data-test="color-other-form"
                @submit.prevent="useOther"
            >
                <span
                    class="size-7 shrink-0 rounded border"
                    :style="{
                        background: otherValid ? otherHex : 'transparent',
                    }"
                    aria-hidden="true"
                />
                <input
                    v-model="other"
                    class="bg-background dark:bg-input/30 h-8 min-w-0 flex-1 rounded-md border px-2 font-mono text-sm"
                    placeholder="#3b82f6"
                    aria-label="A colour's code"
                    maxlength="7"
                    autocomplete="off"
                    spellcheck="false"
                    data-test="color-other-hex"
                />
                <button
                    type="submit"
                    class="bg-primary text-primary-foreground h-8 rounded-md px-3 text-sm disabled:opacity-50"
                    :disabled="!otherValid"
                >
                    Use
                </button>
            </form>
            <SearchField
                v-model="term"
                placeholder="Search icons"
                compact
                class="mb-2"
            />
            <div class="max-h-72 overflow-y-auto">
                <section v-for="group in groups" :key="group.label">
                    <h3
                        class="text-muted-foreground px-1 pt-1 pb-0.5 text-xs font-medium"
                    >
                        {{ group.label }}
                    </h3>
                    <div class="grid grid-cols-8 gap-0.5">
                        <Tip
                            v-for="each in group.icons"
                            :key="each.name"
                            :text="each.label"
                        >
                            <button
                                type="button"
                                class="hover:bg-accent flex aspect-square items-center justify-center rounded"
                                :class="
                                    each.name === current &&
                                    'bg-accent ring-ring ring-1'
                                "
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
                        </Tip>
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
