<script setup lang="ts" generic="Style extends string">
import { Download, HardDrive, RefreshCw } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { uploadToDrive } from '@/lib/drive';
import { deliver } from '@/lib/exporting';

/**
 * The way a PDF is had: printed by the server, shown in the browser's own PDF
 * viewer, and the very file shown is what is downloaded or saved -- printed
 * once, seen, then handed over, with nothing kept anywhere to go stale. A
 * picture (a board as a PNG) is had the same way, shown as itself -- and
 * several pictures (a board's frames) side by side, each to be had alone or
 * all together. What has been drawn is kept while the dialog is open, so
 * going back to a style shows it again rather than drawing it again.
 */
const props = withDefaults(
    defineProps<{
        // Prints the PDF in the style chosen -- or draws the pictures
        print: () => Promise<File | File[]>;
        styles?: { id: Style; label: string; hint?: string }[];
        title?: string;
        // What the style buttons choose between, for a screen reader
        stylesLabel?: string;
        // Said while the file is being made
        working?: string;
        // Draw nothing until a style is picked: for files that are slow or
        // many to make, where a guess at the wrong one is wasted
        waitForChoice?: boolean;
    }>(),
    {
        styles: undefined,
        title: 'PDF preview',
        stylesLabel: 'PDF style',
        working: 'Printing…',
        waitForChoice: false,
    },
);

const open = defineModel<boolean>('open', { required: true });
// Not "style": Vue keeps that name for the attribute
const chosen = defineModel<Style>('chosen');

type Shown = { file: File; url: string };

const shown = ref<Shown[]>([]);
const many = computed(() => shown.value.length > 1);
const isPicture = computed(
    () => !!shown.value[0]?.file.type.startsWith('image/'),
);
const failure = ref<string | null>(null);
const printing = ref(false);
const delivering = ref(false);

// Each style as it was drawn, for as long as the dialog stays open
const drawn = new Map<Style | undefined, Shown[]>();

// Whether a style has been picked since the dialog opened, when it waits
const picked = ref(false);
const choosing = computed(() => props.waitForChoice && !picked.value);

// A newer print wins over one still on its way
let latest = 0;

const forget = () => {
    drawn.forEach((files) =>
        files.forEach(({ url }) => URL.revokeObjectURL(url)),
    );
    drawn.clear();
    shown.value = [];
};

async function reprint(): Promise<void> {
    const mine = ++latest;
    const style = chosen.value;
    printing.value = true;
    failure.value = null;

    try {
        const printed = await props.print();
        if (mine !== latest || !open.value) return;

        drawn.get(style)?.forEach(({ url }) => URL.revokeObjectURL(url));
        const files = [printed].flat().map((file) => ({
            file,
            url: URL.createObjectURL(file),
        }));
        drawn.set(style, files);
        shown.value = files;
    } catch (error) {
        if (mine === latest) failure.value = (error as Error).message;
    } finally {
        if (mine === latest) printing.value = false;
    }
}

/** The chosen style: as already drawn, or drawn now. */
function showChosen(): void {
    const kept = drawn.get(chosen.value);

    if (kept) {
        latest++;
        printing.value = false;
        failure.value = null;
        shown.value = kept;

        return;
    }

    void reprint();
}

function choose(style: Style): void {
    picked.value = true;

    if (chosen.value === style) {
        showChosen();
    } else {
        // the watcher below shows it
        chosen.value = style;
    }
}

watch(open, (isOpen) => {
    if (isOpen) {
        picked.value = false;

        if (!choosing.value) {
            void reprint();
        }
    } else {
        latest++;
        printing.value = false;
        failure.value = null;
        forget();
    }
});

watch(chosen, () => {
    if (open.value) {
        picked.value = true;
        showChosen();
    }
});

onBeforeUnmount(forget);

/** What a picture is called under it: its name, less the type. */
const caption = (file: File) => file.name.replace(/\.[a-z]+$/i, '');

async function hand(
    to: 'download' | 'drive',
    files = shown.value.map(({ file }) => file),
): Promise<void> {
    if (!files.length) return;

    delivering.value = true;

    try {
        if (files.length === 1) {
            await deliver(files[0], to);
        } else if (to === 'download') {
            // One after another: a browser lets a page start several
            // downloads only when they are not all at once
            for (const file of files) {
                await deliver(file, 'download');
                await new Promise((resolve) => setTimeout(resolve, 250));
            }
        } else {
            for (const file of files) {
                await uploadToDrive(file);
            }

            toast.success(`Saved ${files.length} pictures to your Drive`);
        }
    } catch (error) {
        toast.error((error as Error).message);
    } finally {
        delivering.value = false;
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="flex h-[92vh] max-w-[min(1100px,96vw)] flex-col gap-3 p-4 sm:max-w-[min(1100px,96vw)]"
            data-test="pdf-preview"
        >
            <div class="flex flex-wrap items-center gap-3 pr-8">
                <div class="min-w-0">
                    <DialogTitle>{{ title }}</DialogTitle>
                    <DialogDescription class="text-xs">
                        Check it, then download it or save it to your Drive.
                    </DialogDescription>
                </div>

                <div
                    v-if="styles?.length && !choosing"
                    class="bg-muted flex rounded-lg p-0.5"
                    role="radiogroup"
                    :aria-label="stylesLabel"
                >
                    <button
                        v-for="option in styles"
                        :key="option.id"
                        type="button"
                        role="radio"
                        :aria-checked="chosen === option.id"
                        :title="option.hint"
                        :data-test="`pdf-preview-style-${option.id}`"
                        class="rounded-md px-3 py-1 text-sm transition-colors"
                        :class="
                            chosen === option.id
                                ? 'bg-background shadow-sm'
                                : 'text-muted-foreground hover:text-foreground'
                        "
                        @click="choose(option.id)"
                    >
                        {{ option.label }}
                    </button>
                </div>

                <div class="ml-auto flex gap-2">
                    <Button
                        v-if="!choosing"
                        variant="ghost"
                        size="sm"
                        :disabled="printing"
                        title="Print it again with the latest changes"
                        @click="reprint"
                    >
                        <RefreshCw />
                        Refresh
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="!shown.length || printing || delivering"
                        data-test="pdf-preview-drive"
                        @click="hand('drive')"
                    >
                        <HardDrive />
                        {{ many ? 'Save all to Drive' : 'Save to Drive' }}
                    </Button>
                    <Button
                        size="sm"
                        :disabled="!shown.length || printing || delivering"
                        data-test="pdf-preview-download"
                        @click="hand('download')"
                    >
                        <Download />
                        {{ many ? `Download all ${shown.length}` : 'Download' }}
                    </Button>
                </div>
            </div>

            <div
                class="bg-muted relative min-h-0 flex-1 overflow-hidden rounded-lg border"
            >
                <!-- What to make, asked before anything is made -->
                <div
                    v-if="choosing"
                    class="flex size-full flex-wrap content-center items-center justify-center gap-4 overflow-auto p-6"
                    data-test="pdf-preview-choice"
                >
                    <button
                        v-for="option in styles"
                        :key="option.id"
                        type="button"
                        class="bg-background hover:border-primary flex w-64 flex-col gap-1 rounded-xl border p-5 text-left shadow-sm transition-colors"
                        :data-test="`pdf-preview-choose-${option.id}`"
                        @click="choose(option.id)"
                    >
                        <span class="font-semibold">{{ option.label }}</span>
                        <span class="text-muted-foreground text-sm">
                            {{ option.hint }}
                        </span>
                    </button>
                </div>

                <!-- Several pictures: each with its name, and its own way out -->
                <div
                    v-else-if="many"
                    class="grid size-full auto-rows-min grid-cols-1 items-start gap-4 overflow-auto p-4 md:grid-cols-2"
                    data-test="pdf-preview-gallery"
                >
                    <figure
                        v-for="{ file, url } in shown"
                        :key="url"
                        class="bg-background flex flex-col overflow-hidden rounded-lg border shadow-sm"
                    >
                        <img
                            :src="url"
                            :alt="caption(file)"
                            class="w-full border-b bg-white"
                            data-test="pdf-preview-image"
                        />
                        <figcaption class="flex items-center gap-1 px-3 py-1.5">
                            <span class="min-w-0 flex-1 truncate text-sm">
                                {{ caption(file) }}
                            </span>
                            <Button
                                variant="ghost"
                                size="icon-sm"
                                :disabled="delivering"
                                :title="`Save “${caption(file)}” to your Drive`"
                                @click="hand('drive', [file])"
                            >
                                <HardDrive />
                            </Button>
                            <Button
                                variant="ghost"
                                size="icon-sm"
                                :disabled="delivering"
                                :title="`Download “${caption(file)}”`"
                                data-test="pdf-preview-download-one"
                                @click="hand('download', [file])"
                            >
                                <Download />
                            </Button>
                        </figcaption>
                    </figure>
                </div>
                <div
                    v-else-if="shown[0] && isPicture"
                    class="flex size-full items-center justify-center overflow-auto p-6"
                >
                    <img
                        :src="shown[0].url"
                        :alt="title"
                        class="max-h-full max-w-full rounded border bg-white shadow-sm"
                        data-test="pdf-preview-image"
                    />
                </div>
                <iframe
                    v-else-if="shown[0]"
                    :src="shown[0].url"
                    :title="title"
                    class="size-full"
                    data-test="pdf-preview-frame"
                />

                <div
                    v-if="printing"
                    class="bg-muted/80 absolute inset-0 flex flex-col items-center justify-center gap-3 text-sm"
                >
                    <Spinner class="size-6" />
                    {{ working }}
                </div>

                <div
                    v-else-if="failure"
                    class="absolute inset-0 flex flex-col items-center justify-center gap-3 p-6 text-center text-sm"
                >
                    <p>{{ failure }}</p>
                    <Button variant="outline" size="sm" @click="reprint">
                        Try again
                    </Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
