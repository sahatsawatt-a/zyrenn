<script setup lang="ts">
import { Plus, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import PickOne from '@/features/workflow/components/editor/PickOne.vue';
import { readDoes, textBox, writeDoes } from '@/features/workflow/steps';
import type { Field, Setting } from '@/features/workflow/types';
import { show as driveFile } from '@/routes/drive/files';

// A step's named values: each a name and, under it, its value across the
// whole panel. Buttons say what tapping them does from a list, not by how
// the value is written; and where each has a place on a picture -- a rich
// menu's -- the places are drawn, and the one chosen is the one set up.

const props = defineProps<{
    setting: Setting;
    fields: Field[];
    editable: boolean;
    // The step's other settings: how its picture is laid out
    config: Record<string, unknown>;
    // Its table's columns, where they name the values, and whether it has one
    columns: { value: string; label: string }[];
    table: boolean;
}>();

const emit = defineEmits<{ update: [fields: Field[]] }>();

const change = (index: number, patch: Partial<Field>) =>
    emit(
        'update',
        props.fields.map((field, at) =>
            at === index ? { ...field, ...patch } : field,
        ),
    );

const add = () => emit('update', [...props.fields, { name: '', value: '' }]);

const remove = (index: number) =>
    emit(
        'update',
        props.fields.filter((_, at) => at !== index),
    );

// --- what a button does ---
const does = computed(() => props.setting.does ?? []);

const ways = computed(() =>
    does.value.map((way) => ({ value: way.kind, label: way.label })),
);

const doing = (field: Field) => readDoes(does.value, field.value ?? '');

// Chosen anew, it keeps what was written -- unless that reads as something
// else, as an address does where a message was wanted
function pickWay(index: number, kind: string | null) {
    const way = does.value.find((one) => one.kind === kind);

    if (!way) {
        return;
    }

    const kept = writeDoes(way, doing(props.fields[index]).rest);

    change(index, {
        value:
            readDoes(does.value, kept).way.kind === way.kind
                ? kept
                : writeDoes(way, ''),
    });
}

// --- where each is, on a picture ---
const places = computed(() => {
    const laid = props.setting.places;

    return laid
        ? (laid.layouts[String(props.config[laid.by] ?? '')] ?? null)
        : null;
});

// The picture itself behind them, once it is one in the Drive
const pictureFailed = ref(false);
const picture = computed(() => {
    const named = String(
        props.config[props.setting.places?.picture ?? ''] ?? '',
    ).trim();

    return /^[\w-]+$/.test(named) ? driveFile(named).url : null;
});

watch(picture, () => (pictureFailed.value = false));

// The place being set up
const at = ref(0);

watch(
    () => [places.value?.length ?? 0, props.fields.length],
    ([most, named]) => {
        at.value = Math.max(0, Math.min(at.value, most - 1, named));
    },
);

function choose(place: number) {
    if (place === props.fields.length && props.editable) {
        add();
    }

    at.value = place;
}

// With places: the one chosen, and any the picture has no place for. Without:
// every one
const shown = computed(() =>
    props.fields
        .map((field, index) => ({ field, index }))
        .filter(
            ({ index }) =>
                !places.value ||
                index === at.value ||
                index >= places.value.length,
        ),
);

const spare = computed(() =>
    places.value ? Math.max(0, props.fields.length - places.value.length) : 0,
);
</script>

<template>
    <div class="grid grid-cols-1 gap-2">
        <Label>{{ setting.label }}</Label>

        <div
            v-if="places"
            class="bg-muted/40 relative w-full max-w-sm overflow-hidden rounded-lg border"
            :class="{ 'aspect-[2500/1686]': !picture || pictureFailed }"
            data-places
        >
            <img
                v-if="picture && !pictureFailed"
                :src="picture"
                alt=""
                class="block w-full"
                @error="pictureFailed = true"
            />
            <button
                v-for="([x, y, wide, high], place) in places"
                :key="place"
                type="button"
                class="absolute flex flex-col items-center justify-center gap-0.5 overflow-hidden border px-1 text-center text-[11px] leading-tight font-medium transition-colors"
                :class="[
                    place === at && place < fields.length
                        ? 'border-primary bg-background/85 text-primary ring-primary z-10 ring-2 ring-inset'
                        : 'border-border bg-background/60',
                    place > fields.length ||
                    (place === fields.length && !editable)
                        ? 'text-muted-foreground/50 cursor-default'
                        : 'hover:bg-primary/10',
                ]"
                :style="{
                    left: `${(x / 6) * 100}%`,
                    top: `${(y / 2) * 100}%`,
                    width: `${(wide / 6) * 100}%`,
                    height: `${(high / 2) * 100}%`,
                }"
                :disabled="
                    place > fields.length ||
                    (place === fields.length && !editable)
                "
                :title="
                    place > fields.length
                        ? 'Set up the places before it first'
                        : undefined
                "
                :aria-pressed="place === at && place < fields.length"
                :data-place="place"
                @click="choose(place)"
            >
                <template v-if="place < fields.length">
                    <span class="text-muted-foreground text-[10px]">{{
                        place + 1
                    }}</span>
                    <span class="line-clamp-2 break-words">{{
                        fields[place].name || 'No name yet'
                    }}</span>
                </template>
                <Plus v-else-if="place === fields.length" class="size-4" />
                <span v-else class="text-[10px]">{{ place + 1 }}</span>
            </button>
        </div>
        <p v-if="spare" class="text-destructive text-xs">
            This layout has {{ places?.length }}
            {{ places?.length === 1 ? 'place' : 'places' }}: the
            {{ spare === 1 ? 'button' : `${spare} buttons` }} under here
            {{ spare === 1 ? 'has' : 'have' }} none.
        </p>

        <div
            v-for="{ field, index } in shown"
            :key="index"
            class="bg-card grid grid-cols-1 gap-1.5 rounded-lg border p-1.5"
            data-field-row
        >
            <div class="flex items-center gap-1.5">
                <div v-if="setting.names === 'columns'" class="min-w-0 flex-1">
                    <PickOne
                        :model-value="field.name"
                        :options="columns"
                        placeholder="Column"
                        :disabled="!editable || !table"
                        @update:model-value="
                            change(index, { name: $event ?? '' })
                        "
                    />
                </div>
                <Input
                    v-else
                    :model-value="field.name"
                    :placeholder="
                        setting.ways
                            ? 'way'
                            : setting.labels
                              ? 'what it says'
                              : 'name'
                    "
                    class="h-8 min-w-0 flex-1 text-xs font-medium"
                    :class="{ 'font-mono': !setting.labels }"
                    :disabled="!editable"
                    aria-label="Name"
                    @update:model-value="
                        change(index, { name: String($event) })
                    "
                />
                <Button
                    v-if="editable"
                    size="icon-sm"
                    variant="ghost"
                    aria-label="Remove value"
                    @click="remove(index)"
                >
                    <X />
                </Button>
            </div>
            <div v-if="does.length" data-does>
                <PickOne
                    :model-value="doing(field).way.kind"
                    :options="ways"
                    :disabled="!editable"
                    @update:model-value="pickWay(index, $event)"
                />
            </div>
            <textarea
                :value="does.length ? doing(field).rest : field.value"
                rows="1"
                :placeholder="
                    does.length
                        ? (doing(field).way.placeholder ?? '')
                        : setting.ways
                          ? 'input.total > 100'
                          : setting.labels
                            ? 'what it does'
                            : '{{ input.total }}'
                "
                :class="textBox"
                class="field-sizing-content max-h-40 resize-none"
                :disabled="!editable"
                :aria-label="setting.ways ? 'When' : 'Value'"
                :data-takes-data="setting.ways ? 'formula' : 'template'"
                spellcheck="false"
                @input="
                    change(index, {
                        value: does.length
                            ? writeDoes(
                                  doing(field).way,
                                  ($event.target as HTMLTextAreaElement).value,
                              )
                            : ($event.target as HTMLTextAreaElement).value,
                    })
                "
            />
            <p
                v-if="does.length && doing(field).way.hint"
                class="text-muted-foreground px-1 text-xs leading-relaxed"
            >
                {{ doing(field).way.hint }}
            </p>
        </div>

        <p
            v-if="setting.names === 'columns' && !table"
            class="text-muted-foreground text-xs"
        >
            Choose the table first.
        </p>
        <Button
            v-if="editable && !places"
            variant="outline"
            size="sm"
            class="justify-self-start"
            data-add-field
            @click="add"
        >
            <Plus />
            {{
                setting.ways
                    ? 'Add a way'
                    : setting.labels
                      ? 'Add one'
                      : 'Add a value'
            }}
        </Button>
        <p
            v-if="setting.ways && fields.length"
            class="text-muted-foreground text-xs"
        >
            Then “otherwise”, when none of these is true.
        </p>
    </div>
</template>
