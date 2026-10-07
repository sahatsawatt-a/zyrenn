import { ref, computed, watch, nextTick, type CSSProperties } from 'vue';
import type {
    SuggestionKeyDownProps,
    SuggestionProps,
} from '@tiptap/suggestion';
import type { SlashCommandItem } from './useSlashCommands';

export function useMenuRenderer(commandItems: SlashCommandItem[]) {
    const showMenu = ref<boolean>(false);
    const searchQuery = ref<string>('');
    const selectedIndex = ref<number>(0);
    const menuStyle = ref<CSSProperties>({
        top: '0px',
        left: '0px',
        position: 'fixed',
    });
    let currentSuggestionProps: SuggestionProps<SlashCommandItem> | null = null;

    const filteredItems = computed<SlashCommandItem[]>(() => {
        const query = searchQuery.value.toLowerCase();

        return commandItems.filter((item) =>
            [item.title, ...(item.keywords ?? [])].some((term) =>
                term.toLowerCase().includes(query),
            ),
        );
    });

    // Auto-reset selection index when user updates typing string query
    watch(filteredItems, () => {
        selectedIndex.value = 0;
    });

    const executeCommand = (index: number): void => {
        const item = filteredItems.value[index];
        if (currentSuggestionProps && item) {
            currentSuggestionProps.command(item);
        }
    };

    const MENU_WIDTH = 280;
    const MENU_MAX_HEIGHT = 320;
    const GAP = 6;
    const EDGE = 8;

    // Fixed (viewport) positioning so the menu can never stretch the page; it opens
    // above the caret when there's more room there, and shrinks to the space it has
    const updateMenuPosition = (
        props: SuggestionProps<SlashCommandItem> | null = currentSuggestionProps,
    ) => {
        // Nothing waits on the tick; the menu is placed once the DOM has caught up
        void nextTick(() => {
            const rect = props?.clientRect?.();
            if (!rect) return;

            const spaceBelow = window.innerHeight - rect.bottom - GAP - EDGE;
            const spaceAbove = rect.top - GAP - EDGE;
            const openAbove =
                spaceBelow < MENU_MAX_HEIGHT && spaceAbove > spaceBelow;
            const left = Math.max(
                EDGE,
                Math.min(rect.left, window.innerWidth - MENU_WIDTH - EDGE),
            );

            menuStyle.value = {
                position: 'fixed',
                left: `${left}px`,
                maxHeight: `${Math.max(120, Math.min(MENU_MAX_HEIGHT, openAbove ? spaceAbove : spaceBelow))}px`,
                ...(openAbove
                    ? { bottom: `${window.innerHeight - rect.top + GAP}px` }
                    : { top: `${rect.bottom + GAP}px` }),
            };
        });
    };

    // Keep the menu attached to the caret while the page or a container scrolls
    const followCaret = () => {
        if (showMenu.value) updateMenuPosition();
    };
    const listenForScroll = () => {
        window.addEventListener('scroll', followCaret, true);
        window.addEventListener('resize', followCaret);
    };
    const stopListeningForScroll = () => {
        window.removeEventListener('scroll', followCaret, true);
        window.removeEventListener('resize', followCaret);
    };

    // Pure isolated suggestion object framework handlers hook mappings
    const suggestionRenderOptions = () => ({
        onStart: (props: SuggestionProps<SlashCommandItem>) => {
            currentSuggestionProps = props;
            showMenu.value = true;
            selectedIndex.value = 0;
            updateMenuPosition(props);
            listenForScroll();
        },
        onUpdate: (props: SuggestionProps<SlashCommandItem>) => {
            currentSuggestionProps = props;
            updateMenuPosition(props);
        },
        onKeyDown: (props: SuggestionKeyDownProps) => {
            if (!showMenu.value) return false;
            if (filteredItems.value.length === 0) return false;

            let handled = false;

            // 💡 THE FIX: Update index, toggle handled state, and remove the immediate "return true" strings
            if (props.event.key === 'ArrowUp') {
                selectedIndex.value =
                    (selectedIndex.value + filteredItems.value.length - 1) %
                    filteredItems.value.length;
                handled = true;
            }
            if (props.event.key === 'ArrowDown') {
                selectedIndex.value =
                    (selectedIndex.value + 1) % filteredItems.value.length;
                handled = true;
            }
            if (props.event.key === 'Enter') {
                executeCommand(selectedIndex.value);
                return true;
            }
            if (props.event.key === 'Escape') {
                showMenu.value = false;
                return true;
            }

            // 💡 THE FIX: Moves arrow logic to fall smoothly down here for execution tracking
            if (handled) {
                void nextTick(() => {
                    // Find the active highlighted dropdown item in the DOM
                    const activeItem = document.querySelector(
                        '.notion-dropdown .dropdown-item.is-active',
                    );
                    if (activeItem) {
                        activeItem.scrollIntoView({
                            block: 'nearest', // Keeps it visible within the scroll container boundaries
                            behavior: 'auto', // Instant tracking alignment snap
                        });
                    }
                });
                return true;
            }
            return false;
        },
        onExit: () => {
            stopListeningForScroll();
            showMenu.value = false;
            currentSuggestionProps = null;
        },
    });

    return {
        showMenu,
        searchQuery,
        selectedIndex,
        menuStyle,
        filteredItems,
        executeCommand,
        suggestionRenderOptions,
    };
}
