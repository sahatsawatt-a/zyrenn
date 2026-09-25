<template>
    <Teleport to="body">
        <!-- Transition wrap enables fluid canvas entry slide hooks -->
        <Transition name="slide-fade">
            <!-- 💡 THE FIX: Extracted inline logic into the closeSlider method to prevent template compilation parsing issues -->
            <div v-if="isOpen" class="slider-overlay" @click.self="closeSlider">
                <!-- Main Drawer Sliding Surface Wrapper Container -->
                <div
                    class="slider-panel"
                    :class="[`side-${side}`, sizeClass]"
                    role="dialog"
                    aria-modal="true"
                >
                    <!-- Header Control Ribbon Container -->
                    <div class="slider-header">
                        <slot name="header">
                            <h3 class="slider-title">{{ title }}</h3>
                        </slot>
                        <button
                            class="close-btn"
                            aria-label="Close panel"
                            @click="closeSlider"
                        >
                            ✕
                        </button>
                    </div>

                    <!-- Primary Main Display Slot Body area -->
                    <div class="slider-body">
                        <slot />
                    </div>

                    <!-- Footer Actions Ribbon Container (Optional Slot) -->
                    <div v-if="hasFooter" class="slider-footer">
                        <slot name="footer" />
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<script setup lang="ts">
import { computed, useSlots, onMounted, onUnmounted, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        isOpen: boolean;
        title?: string;
        side?: 'left' | 'right';
        size?: 'sm' | 'md' | 'lg' | 'xl' | 'full';
    }>(),
    {
        title: '',
        side: 'right',
        size: 'md',
    },
);

const emit = defineEmits<{
    (e: 'close'): void;
}>();

const slots = useSlots();

// 💡 THE FIX: Compute slot visibility in standard TS variables rather than parsing inside HTML attributes
const hasFooter = computed(() => !!slots.footer);

// Dynamic size maps built against universal grid parameters
const sizeClass = computed(() => `size-${props.size}`);

// Isolated method handler ensures template parser engine stays clean
const closeSlider = () => {
    emit('close');
};

// Prevent background window document scrolling while slider overlay is focused
watch(
    () => props.isOpen,
    (newVal) => {
        if (newVal) {
            document.body.style.overflow = 'hidden';
        } else {
            document.body.style.overflow = '';
        }
    },
);

// Listen globally for Escape key hits to dim layout components out safely
const handleKeyDown = (event: KeyboardEvent) => {
    if (event.key === 'Escape' && props.isOpen) {
        closeSlider();
    }
};

onMounted(() => window.addEventListener('keydown', handleKeyDown));
onUnmounted(() => {
    window.removeEventListener('keydown', handleKeyDown);
    document.body.style.overflow = ''; // Safety clean-up reset handler
});
</script>

<style scoped>
/* Backdrop Overlay Layout styling using Tailwind v4 custom layout variables */
.slider-overlay {
    position: fixed;
    inset: 0;
    background-color: rgba(0, 0, 0, 0.4);
    backdrop-filter: blur(4px);
    z-index: 10000;
    display: flex;
}

.slider-panel {
    position: absolute;
    height: 100%;
    background-color: var(--card);
    border-color: var(--border);
    color: var(--foreground);
    display: flex;
    flex-direction: column;
    box-shadow: var(--shadow-xl, 0 20px 25px -5px rgba(0, 0, 0, 0.1));
}

/* Side Orientation Alignment assignments mapping rules */
.side-right {
    right: 0;
    border-left: 1px solid var(--border);
}

.side-left {
    left: 0;
    border-right: 1px solid var(--border);
}

/* Panel Width Configurations */
.size-sm {
    width: 320px;
    max-width: 100vw;
}
.size-md {
    width: 460px;
    max-width: 100vw;
}
.size-lg {
    width: 640px;
    max-width: 100vw;
}
.size-xl {
    width: 800px;
    max-width: 100vw;
}
.size-full {
    width: 100vw;
}

/* Structural Content Component Ribbons grid layouts */
.slider-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--border);
    background-color: var(--card);
}

.slider-title {
    font-size: 1.125rem;
    font-weight: 600;
    color: var(--foreground);
    margin: 0;
}

.close-btn {
    background: transparent;
    border: none;
    font-size: 1.125rem;
    color: var(--muted-foreground);
    cursor: pointer;
    padding: 4px;
    border-radius: var(--radius-sm);
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 150ms ease;
}
.close-btn:hover {
    background-color: var(--muted);
    color: var(--foreground);
}

.slider-body {
    flex: 1;
    overflow-y: auto;
    padding: 1.5rem;
}

.slider-footer {
    padding: 1.25rem 1.5rem;
    border-top: 1px solid var(--border);
    background-color: var(--muted);
}

/* ==========================================================================
   VUE MULTI-FRAME TRANSITION ANIMATIONS (Smooth Slide Effects)
   ========================================================================== */
.slide-fade-enter-active,
.slide-fade-leave-active {
    transition: opacity 250ms ease;
}

.slide-fade-enter-active .slider-panel,
.slide-fade-leave-active .slider-panel {
    transition: transform 250ms cubic-bezier(0.16, 1, 0.3, 1);
}

.slide-fade-enter-from,
.slide-fade-leave-to {
    opacity: 0;
}

/* Slide direction logic matching dynamic configurations */
.slide-fade-enter-from .side-right,
.slide-fade-leave-to .side-right {
    transform: translateX(100%);
}

.slide-fade-enter-from .side-left,
.slide-fade-leave-to .side-left {
    transform: translateX(-100%);
}
</style>
