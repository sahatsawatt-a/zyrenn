<script setup lang="ts">
import { ref, watch, onMounted, nextTick } from 'vue';
import mermaid from 'mermaid';

mermaid.initialize({
    startOnLoad: false,
    securityLevel: 'loose',
    flowchart: { htmlLabels: false },
});

const props = defineProps<{ code: string; x: number; y: number }>();
const emit = defineEmits(['update:code']);

const isEditing = ref(false);
const editorText = ref('');
const editorStyle = ref<any>({});
const editingNodeId = ref<string | null>(null);

interface DynamicNode {
    id: string;
    label: string;
    x: number;
    y: number;
    w: number;
    h: number;
    originalX: number;
    originalY: number;
}
interface DynamicLine {
    id: string;
    points: number[];
}

const canvasNodes = ref<DynamicNode[]>([]);
const canvasLines = ref<DynamicLine[]>([]);

async function parseAndBuildGraph() {
    if (!props.code.trim()) return;
    const pad = document.createElement('div');
    pad.style.cssText = 'position: absolute; opacity: 0; pointer-events: none;';
    document.body.appendChild(pad);

    try {
        const { svg } = await mermaid.render(
            'mermaid-extractor',
            props.code,
            pad,
        );
        const parser = new DOMParser();
        const doc = parser.parseFromString(svg, 'image/svg+xml');

        // 1. Gather all individual layout node properties
        const nodes: DynamicNode[] = [];
        doc.querySelectorAll('g.node').forEach((el: any) => {
            const segs = (el.id || '').split('-');
            const id = segs.length > 1 ? segs[segs.length - 2] : el.id;
            const transformAttr = el.getAttribute('transform') || '';
            const match = transformAttr.match(
                /translate\(([-\d.]+),\s*([-\d.]+)\)/,
            );
            const r = el.querySelector('rect, polygon, circle');
            const w = r ? parseFloat(r.getAttribute('width') || '120') : 120;
            const h = r ? parseFloat(r.getAttribute('height') || '40') : 40;

            if (match && match[1] && match[2]) {
                const origX = parseFloat(match[1]) - w / 2;
                const origY = parseFloat(match[2]) - h / 2;

                nodes.push({
                    id,
                    label:
                        el
                            .querySelector('.nodeLabel, text')
                            ?.textContent?.trim() || id,
                    x: props.x + origX,
                    y: props.y + origY,
                    originalX: origX,
                    originalY: origY,
                    w,
                    h,
                });
            }
        });
        canvasNodes.value = nodes;

        // 2. FIXED PATH PARSER: Cleans and isolates coordinate strings, ignoring command flags (M, L, C, S)
        const lines: DynamicLine[] = [];
        doc.querySelectorAll('g.edgePath path.path').forEach(
            (pathEl: any, index: number) => {
                const dAttr = pathEl.getAttribute('d') || '';
                const flatPoints: number[] = [];

                // Matches any numeric sequence (including negatives/decimals) separated by commas or spaces
                const numbers = dAttr.match(/[-+]?[0-9]*\.?[0-9]+/g);
                if (numbers) {
                    // Read coordinate strings in pairs (x, y)
                    for (let i = 0; i < numbers.length; i += 2) {
                        if (numbers[i] && numbers[i + 1]) {
                            flatPoints.push(props.x + parseFloat(numbers[i]));
                            flatPoints.push(
                                props.y + parseFloat(numbers[i + 1]),
                            );
                        }
                    }
                }

                if (flatPoints.length >= 4) {
                    lines.push({ id: `line-${index}`, points: flatPoints });
                }
            },
        );
        canvasLines.value = lines;

        pad.remove();
    } catch {
        pad.remove();
    }
}

function handleNodeDragMove(index: number, e: any) {
    const target = e.target;
    const node = canvasNodes.value[index];

    const deltaX = target.x() - node.x;
    const deltaY = target.y() - node.y;

    node.x = target.x();
    node.y = target.y();

    canvasLines.value.forEach((line) => {
        for (let i = 0; i < line.points.length; i += 2) {
            const ptX = line.points[i];
            const ptY = line.points[i + 1];

            const closeToNodeX =
                Math.abs(ptX - (node.x - deltaX + node.w / 2)) <
                node.w / 2 + 15;
            const closeToNodeY =
                Math.abs(ptY - (node.y - deltaY + node.h / 2)) <
                node.h / 2 + 15;

            if (closeToNodeX && closeToNodeY) {
                line.points[i] += deltaX;
                line.points[i + 1] += deltaY;
            }
        }
    });
}

function startEditing(node: DynamicNode, e: any) {
    editingNodeId.value = node.id;
    editorText.value = node.label;

    const stage = e.target.getStage();
    const box = stage.container().getBoundingClientRect();

    editorStyle.value = {
        position: 'absolute',
        top: `${box.top + window.scrollY + node.y}px`,
        left: `${box.left + window.scrollX + node.x}px`,
        width: `${node.w}px`,
        height: `${node.h}px`,
        background: '#1e293b',
        color: '#34d399',
        border: '1px solid #6366f1',
        outline: 'none',
        textAlign: 'center',
        resize: 'none',
        zIndex: 9999,
    };
    isEditing.value = true;
}

function save() {
    if (!isEditing.value || !editingNodeId.value) return;
    const r = new RegExp(
        `(${editingNodeId.value})\\s*(?:\\[.*?\\]|\\(.*?\\)|\\{.*?\\})`,
        'g',
    );
    emit('update:code', props.code.replace(r, `$1[${editorText.value}]`));
    isEditing.value = false;
}

watch(
    () => props.code,
    () => nextTick(parseAndBuildGraph),
);
onMounted(parseAndBuildGraph);
</script>

<template>
    <!-- FIX: Wrapping all items in a single root element isolates Konva context inheritance and removes warnings -->
    <v-group>
        <v-arrow
            v-for="line in canvasLines"
            :key="line.id"
            :config="{
                points: line.points,
                pointerLength: 10,
                pointerWidth: 8,
                fill: '#818cf8',
                stroke: '#475569',
                strokeWidth: 2.5,
                lineCap: 'round',
                lineJoin: 'round',
                tension: 0.2,
            }"
        />

        <v-group
            v-for="(node, index) in canvasNodes"
            :key="node.id"
            :config="{ x: node.x, y: node.y, draggable: true }"
            @dragmove="(e: any) => handleNodeDragMove(index, e)"
            @dblclick="(e: any) => startEditing(node, e)"
        >
            <v-rect
                :config="{
                    width: node.w,
                    height: node.h,
                    fill: '#1e1b4b',
                    stroke: '#6366f1',
                    strokeWidth: 2,
                    cornerRadius: 6,
                }"
            />
            <v-text
                :config="{
                    text: node.label,
                    width: node.w,
                    height: node.h,
                    fill: '#f8fafc',
                    align: 'center',
                    verticalAlign: 'middle',
                    fontSize: 13,
                    fontStyle: 'bold',
                }"
            />
        </v-group>
    </v-group>

    <Teleport to="body">
        <textarea
            v-if="isEditing"
            v-model="editorText"
            :style="editorStyle"
            @blur="save"
            @keydown.enter.prevent="save"
        />
    </Teleport>
</template>
