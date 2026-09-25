<template>
    <div class="demo-page-wrapper">
        <header class="demo-header">
            <div class="header-content">
                <span class="badge">Workspace</span>
                <h1>Interactive Document Canvas</h1>
                <p>
                    Type <code>/</code> to trigger commands. Drag text
                    selections to test components.
                </p>

                <!-- Action Trigger Button to mount Side Panel -->
                <!-- <button class="settings-trigger-btn" @click="isDrawerOpen = true">
          ⚙️ Open Workspace Settings
        </button> -->
            </div>
        </header>

        <main class="demo-body">
            <TiptapEditor :content="demoContent" />
        </main>

        <!-- Global Slider Blueprint Configured Cleanly -->
        <!-- <SideSlider 
      :is-open="isDrawerOpen" 
      title="Document Settings" 
      side="right" 
      size="md"
      @close="isDrawerOpen = false"
    >
      <div class="settings-panel-content">
        <h4>Editor Adjustments</h4>
        <p>Configure typography line adjustments, backup variables tracking parameters, or trigger synchronization loops natively.</p>
      </div>
      
      <template #footer>
        <button class="save-btn" @click="isDrawerOpen = false">Close Panel</button>
      </template>
    </SideSlider> -->
    </div>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import TiptapEditor from '../../components/Editor/TiptapEditor.vue';

const isDrawerOpen = ref<boolean>(false);

const demoContent = `
  <h1>Notion-style Custom Editor Workspace</h1>
  <p>This workspace is powered by a fully decoupled architecture. Press <strong>Enter</strong> to open up fresh line streams.</p>
  <div data-type="callout" class="callout-block">
    <span class="callout-icon" contenteditable="false">💡</span>
    <div class="callout-content">
      <p>Type <code>/</code> at any point to pull up the strictly typed command items menu selector grid panels instantly!</p>
    </div>
  </div>
`;
</script>

<style scoped>
.demo-page-wrapper {
    min-height: 100vh;
    background-color: var(--background);
    color: var(--foreground);
    transition:
        background-color 200ms ease,
        color 200ms ease;
}

.demo-header {
    background-color: var(--card);
    border-bottom: 1px solid var(--border);
    padding: 2.5rem 1.5rem;
    display: flex;
    justify-content: center;
}

.header-content {
    width: 100%;
    max-width: 720px;
}

.settings-trigger-btn {
    margin-top: 1rem;
    background-color: var(--muted);
    border: 1px solid var(--border);
    color: var(--foreground);
    padding: 6px 14px;
    border-radius: var(--radius-md);
    font-size: 13px;
    cursor: pointer;
    font-weight: 500;
    transition: all 150ms ease;
}
.settings-trigger-btn:hover {
    background-color: var(--accent);
}

.demo-body {
    padding: 2rem 0;
}

.badge {
    display: inline-block;
    background-color: var(--accent);
    color: var(--accent-foreground);
    font-size: 0.75rem;
    font-weight: 600;
    padding: 0.25rem 0.6rem;
    border-radius: var(--radius-sm);
    margin-bottom: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.demo-header h1 {
    font-size: 1.875rem;
    font-weight: 700;
    margin: 0 0 0.5rem 0;
    letter-spacing: -0.025em;
}

.demo-header p {
    color: var(--muted-foreground);
    font-size: 0.95rem;
    margin: 0;
    line-height: 1.5;
}

/* Extra slider content formatting */
.settings-panel-content h4 {
    font-size: 15px;
    font-weight: 600;
    margin-bottom: 0.5rem;
}
.settings-panel-content p {
    font-size: 13px;
    color: var(--muted-foreground);
    line-height: 1.5;
}
.save-btn {
    width: 100%;
    background-color: var(--primary);
    color: var(--primary-foreground);
    border: none;
    padding: 8px;
    border-radius: var(--radius-sm);
    cursor: pointer;
    font-size: 13px;
    font-weight: 500;
}
</style>
