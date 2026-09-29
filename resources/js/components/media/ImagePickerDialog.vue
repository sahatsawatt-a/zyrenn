<script setup lang="ts">
import {
    Code,
    Workflow,
    HardDrive,
    ImageUp,
    Link2,
    LoaderCircle,
    Search,
} from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    IMAGE_MIMES,
    isImageFile,
    listDriveImages,
    uploadToDrive,
} from '@/lib/drive';
import type { DriveFile } from '@/lib/drive';
import { cn } from '@/lib/utils';

export type PickedImage = { src: string; alt: string };

const open = defineModel<boolean>('open', { required: true });

const props = withDefaults(
    defineProps<{
        // Where the picked image is going, said plainly in the dialog
        destination?: string;
        // A board can take SVG markup as a drawing, and a Mermaid diagram as
        // shapes it can then edit; a note writes Mermaid inline already
        allowMarkup?: boolean;
    }>(),
    { destination: 'the note', allowMarkup: false },
);

const emit = defineEmits<{
    (e: 'insert', images: PickedImage[]): void;
    (e: 'markup', svg: string): void;
    (e: 'mermaid', source: string): void;
}>();

type Tab = 'upload' | 'drive' | 'link' | 'markup' | 'mermaid';

const tabs = computed(() =>
    [
        { id: 'upload' as const, label: 'Upload', icon: ImageUp },
        { id: 'drive' as const, label: 'From Drive', icon: HardDrive },
        { id: 'link' as const, label: 'Link', icon: Link2 },
        ...(props.allowMarkup
            ? [
                  { id: 'markup' as const, label: 'SVG', icon: Code },
                  //   { id: 'mermaid' as const, label: 'Mermaid', icon: Workflow },
              ]
            : []),
    ].filter(Boolean),
);

const tab = ref<Tab>('upload');

// ------------------------------------------------------------- SVG markup
const markup = ref('');

const MARKUP_EXAMPLE =
    '<svg viewBox="0 0 100 100"><circle cx="50" cy="50" r="40" fill="#6366f1" /></svg>';

const markupIsValid = computed(() => /<svg[\s>]/i.test(markup.value));

// The hint lives here rather than in the template: a literal tag inside a
// template expression reads as markup to the parser
const markupHint = computed(() =>
    markup.value.trim() && !markupIsValid.value
        ? 'That does not look like SVG: it needs an <svg> tag.'
        : 'Paste SVG markup and it is drawn as a picture you can resize.',
);

// ---------------------------------------------------------------- Mermaid
const diagram = ref('');

const DIAGRAM_EXAMPLE = `flowchart TD
    A[Order placed] --> B{In stock?}
    B -- yes --> C[(Warehouse)]
    B -- no --> D[/Back-order/]`;

const insertDiagram = () => {
    if (!diagram.value.trim()) {
        return;
    }

    emit('mermaid', diagram.value);
    open.value = false;
};

const insertMarkup = () => {
    if (!markupIsValid.value) {
        return;
    }

    emit('markup', markup.value);
    open.value = false;
};

const finish = (images: PickedImage[]) => {
    if (images.length) {
        emit('insert', images);
    }

    open.value = false;
};

// ------------------------------------------------------------------ Upload
const fileInput = ref<HTMLInputElement | null>(null);
const uploading = ref(0);
const dragging = ref(false);

const upload = async (files: File[]) => {
    const images = files.filter(isImageFile);

    if (images.length < files.length) {
        toast.error(
            'Only JPG, PNG, GIF, WebP, AVIF and SVG images can be added.',
        );
    }

    if (!images.length) {
        return;
    }

    uploading.value = images.length;
    const picked: PickedImage[] = [];

    // Sequential, so the images land in the order they were chosen
    for (const file of images) {
        try {
            const stored = await uploadToDrive(file);
            picked.push({ src: stored.url, alt: stored.name });
        } catch (error) {
            toast.error((error as Error).message);
        } finally {
            uploading.value--;
        }
    }

    finish(picked);
};

const onFilesChosen = (event: Event) => {
    const input = event.target as HTMLInputElement;
    const files = Array.from(input.files ?? []);
    input.value = '';
    void upload(files);
};

const onDrop = (event: DragEvent) => {
    dragging.value = false;
    void upload(Array.from(event.dataTransfer?.files ?? []));
};

// ------------------------------------------------------------------- Drive
const driveFiles = ref<DriveFile[]>([]);
const loadingDrive = ref(false);
const query = ref('');
const selected = ref<string[]>([]);

const loadDrive = async () => {
    loadingDrive.value = true;

    try {
        driveFiles.value = await listDriveImages(query.value.trim());
    } catch (error) {
        toast.error((error as Error).message);
    } finally {
        loadingDrive.value = false;
    }
};

const debouncedLoad = useDebounceFn(loadDrive, 250);
watch(query, () => void debouncedLoad());

const toggle = (file: DriveFile) => {
    selected.value = selected.value.includes(file.ref_id)
        ? selected.value.filter((id) => id !== file.ref_id)
        : [...selected.value, file.ref_id];
};

const insertSelected = () => {
    // Keep the order in which they were clicked
    const byId = new Map(driveFiles.value.map((file) => [file.ref_id, file]));

    finish(
        selected.value
            .map((id) => byId.get(id))
            .filter((file): file is DriveFile => !!file)
            .map((file) => ({ src: file.url, alt: file.name })),
    );
};

// -------------------------------------------------------------------- Link
const linkUrl = ref('');
const linkBroken = ref(false);

const linkIsValid = computed(() => {
    try {
        return ['http:', 'https:'].includes(
            new URL(linkUrl.value.trim()).protocol,
        );
    } catch {
        return false;
    }
});

watch(linkUrl, () => (linkBroken.value = false));

const insertLink = () => {
    if (!linkIsValid.value) {
        return;
    }

    const url = linkUrl.value.trim();
    const name = decodeURIComponent(
        new URL(url).pathname.split('/').pop() ?? '',
    );

    finish([{ src: url, alt: name }]);
};

// Start fresh each time the dialog opens
watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    tab.value = 'upload';
    selected.value = [];
    query.value = '';
    linkUrl.value = '';
    markup.value = '';
    diagram.value = '';
    driveFiles.value = [];
});

watch(tab, (current) => {
    if (current === 'drive' && !driveFiles.value.length) {
        void loadDrive();
    }
});
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>Add image</DialogTitle>
                <DialogDescription>
                    Uploads are saved to your Drive. You can also paste or drop
                    images straight into {{ destination }}.
                </DialogDescription>
            </DialogHeader>

            <div class="bg-muted inline-flex w-full gap-1 rounded-lg p-1">
                <button
                    v-for="item in tabs"
                    :key="item.id"
                    type="button"
                    :class="
                        cn(
                            'flex flex-1 items-center justify-center gap-2 rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
                            tab === item.id
                                ? 'bg-background text-foreground shadow-sm'
                                : 'text-muted-foreground hover:text-foreground',
                        )
                    "
                    @click="tab = item.id"
                >
                    <component :is="item.icon" class="size-4" />
                    {{ item.label }}
                </button>
            </div>

            <!-- Upload from this device -->
            <div
                v-if="tab === 'upload'"
                :class="
                    cn(
                        'flex h-64 flex-col items-center justify-center gap-3 rounded-lg border-2 border-dashed text-center transition-colors',
                        dragging
                            ? 'border-primary bg-primary/5'
                            : 'border-border',
                    )
                "
                @dragover.prevent="dragging = true"
                @dragleave="dragging = false"
                @drop.prevent="onDrop"
            >
                <template v-if="uploading">
                    <LoaderCircle
                        class="text-muted-foreground size-8 animate-spin"
                    />
                    <p class="text-sm">Uploading {{ uploading }}…</p>
                </template>
                <template v-else>
                    <ImageUp class="text-muted-foreground size-8" />
                    <p class="text-sm font-medium">Drop images here</p>
                    <Button
                        type="button"
                        variant="outline"
                        @click="fileInput?.click()"
                    >
                        Choose from computer
                    </Button>
                    <p class="text-muted-foreground text-xs">
                        JPG, PNG, GIF, WebP, AVIF or SVG · up to 50 MB each
                    </p>
                </template>

                <input
                    ref="fileInput"
                    type="file"
                    :accept="IMAGE_MIMES.join(',')"
                    multiple
                    hidden
                    @change="onFilesChosen"
                />
            </div>

            <!-- Pick from Drive -->
            <div v-else-if="tab === 'drive'" class="flex flex-col gap-3">
                <div class="relative">
                    <Search
                        class="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2"
                    />
                    <Input
                        v-model="query"
                        placeholder="Search your images"
                        class="pl-9"
                    />
                </div>

                <div class="h-72 overflow-y-auto">
                    <div
                        v-if="loadingDrive && !driveFiles.length"
                        class="flex h-full items-center justify-center"
                    >
                        <LoaderCircle
                            class="text-muted-foreground size-6 animate-spin"
                        />
                    </div>

                    <div
                        v-else-if="!driveFiles.length"
                        class="text-muted-foreground flex h-full flex-col items-center justify-center gap-2 text-sm"
                    >
                        <HardDrive class="size-8" />
                        {{
                            query
                                ? 'No images match.'
                                : 'No images in your Drive yet.'
                        }}
                    </div>

                    <div v-else class="grid grid-cols-3 gap-2 sm:grid-cols-4">
                        <button
                            v-for="file in driveFiles"
                            :key="file.ref_id"
                            type="button"
                            :title="file.name"
                            :class="
                                cn(
                                    'bg-muted group ring-offset-background relative aspect-square overflow-hidden rounded-md ring-2 ring-offset-2 transition',
                                    selected.includes(file.ref_id)
                                        ? 'ring-primary'
                                        : 'hover:ring-border ring-transparent',
                                )
                            "
                            @click="toggle(file)"
                            @dblclick="
                                finish([{ src: file.url, alt: file.name }])
                            "
                        >
                            <img
                                :src="file.url"
                                :alt="file.name"
                                loading="lazy"
                                class="size-full object-cover"
                            />
                            <span
                                v-if="selected.includes(file.ref_id)"
                                class="bg-primary text-primary-foreground absolute top-1.5 right-1.5 flex size-5 items-center justify-center rounded-full text-xs font-semibold"
                            >
                                {{ selected.indexOf(file.ref_id) + 1 }}
                            </span>
                            <span
                                class="absolute inset-x-0 bottom-0 truncate bg-black/55 px-1.5 py-0.5 text-left text-[11px] text-white opacity-0 transition-opacity group-hover:opacity-100"
                            >
                                {{ file.name }}
                            </span>
                        </button>
                    </div>
                </div>

                <div class="flex justify-end">
                    <Button
                        type="button"
                        :disabled="!selected.length"
                        @click="insertSelected"
                    >
                        Insert{{
                            selected.length > 1
                                ? ` ${selected.length} images`
                                : ''
                        }}
                    </Button>
                </div>
            </div>

            <!-- Image from a link -->
            <form
                v-else-if="tab === 'link'"
                class="flex flex-col gap-3"
                @submit.prevent="insertLink"
            >
                <Input
                    v-model="linkUrl"
                    type="url"
                    placeholder="https://example.com/picture.png"
                    autofocus
                />

                <div
                    class="bg-muted flex h-52 items-center justify-center overflow-hidden rounded-lg"
                >
                    <img
                        v-if="linkIsValid && !linkBroken"
                        :src="linkUrl.trim()"
                        alt=""
                        class="max-h-full max-w-full object-contain"
                        @error="linkBroken = true"
                    />
                    <p
                        v-else
                        class="text-muted-foreground px-6 text-center text-sm"
                    >
                        {{
                            linkBroken
                                ? 'That link doesn’t load as an image.'
                                : 'Paste an image link to preview it.'
                        }}
                    </p>
                </div>

                <p class="text-muted-foreground text-xs">
                    Linked images stay on the other site and aren’t copied to
                    your Drive.
                </p>

                <div class="flex justify-end">
                    <Button type="submit" :disabled="!linkIsValid || linkBroken"
                        >Insert</Button
                    >
                </div>
            </form>

            <!-- A Mermaid diagram, which comes in as shapes and connectors -->
            <form
                v-else-if="tab === 'mermaid'"
                class="flex flex-col gap-3"
                @submit.prevent="insertDiagram"
            >
                <textarea
                    v-model="diagram"
                    class="border-border bg-background h-48 w-full resize-none rounded-lg border p-3 font-mono text-xs"
                    spellcheck="false"
                    :placeholder="DIAGRAM_EXAMPLE"
                    data-test="mermaid-source"
                />

                <p class="text-muted-foreground text-xs">
                    A flowchart arrives as shapes and connectors you can move
                    and restyle. Any other diagram is added as a picture.
                </p>

                <div class="flex justify-end">
                    <Button
                        type="submit"
                        :disabled="!diagram.trim()"
                        data-test="mermaid-add"
                        >Insert</Button
                    >
                </div>
            </form>

            <!-- SVG markup, for somewhere that can hold a drawing -->
            <form
                v-else
                class="flex flex-col gap-3"
                @submit.prevent="insertMarkup"
            >
                <textarea
                    v-model="markup"
                    class="border-border bg-background h-48 w-full resize-none rounded-lg border p-3 font-mono text-xs"
                    spellcheck="false"
                    :placeholder="MARKUP_EXAMPLE"
                    data-test="svg-markup"
                />

                <p
                    class="text-muted-foreground text-xs"
                    data-test="svg-problem"
                >
                    {{ markupHint }}
                </p>

                <div class="flex justify-end">
                    <Button
                        type="submit"
                        :disabled="!markupIsValid"
                        data-test="svg-add"
                        >Insert</Button
                    >
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
