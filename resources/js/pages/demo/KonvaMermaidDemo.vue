<script setup lang="ts">
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import MermaidNode from '@/components/Board/demo/MermaidNode.vue';

const mermaidCode = ref(`graph LR
    A[Laravel 13] --> B(Konva Canvases)
`);
const nodePos = ref({ x: 100, y: 100 });
</script>

<template>
    <Head title="Dynamic Graph Sandbox" />
    <div
        class="flex h-screen w-screen gap-4 bg-slate-900 p-4 font-sans text-white"
    >
        <!-- Editor Viewport Panel -->
        <div class="flex w-1/3 flex-col">
            <textarea
                v-model="mermaidCode"
                class="w-full flex-1 resize-none rounded border border-slate-800 bg-slate-950 p-4 font-mono text-sm text-emerald-400 focus:outline-none"
            />
        </div>
        <!-- Infinite Board Workspace Frame -->
        <div
            class="relative w-2/3 overflow-hidden rounded border border-slate-800 bg-slate-950"
        >
            <v-stage :config="{ width: 1000, height: 800 }">
                <v-layer>
                    <!-- Clean attributes passing loop matching your stack context -->
                    <MermaidNode
                        v-model:code="mermaidCode"
                        :x="nodePos.x"
                        :y="nodePos.y"
                    />
                </v-layer>
            </v-stage>
        </div>
    </div>
</template>
