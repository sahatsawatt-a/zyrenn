<script setup lang="ts">
import { Smile } from '@lucide/vue';
import { useLocalStorage } from '@vueuse/core';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import Tip from '@/components/common/Tip.vue';

// Emoji to put in a message: the ones used lately first, then the usual ones
// by kind. It stays open while several are chosen; what is chosen goes where
// the message's cursor was.

defineProps<{ disabled?: boolean }>();

const emit = defineEmits<{ pick: [emoji: string]; closed: [] }>();

// Each kind under one of its own as its tab
const KINDS: { tab: string; name: string; emoji: string }[] = [
    {
        tab: '😀',
        name: 'Faces',
        emoji: '😀 😃 😄 😁 😆 😅 🤣 😂 🙂 🙃 😉 😊 😇 🥰 😍 🤩 😘 😗 😚 😋 😛 😜 🤪 😝 🤑 🤗 🤭 🤫 🤔 🤐 🤨 😐 😑 😶 😏 😒 🙄 😬 😌 😔 😪 😴 😷 🤒 🤕 🤢 🤮 🥵 🥶 🥴 😵 🤯 🤠 🥳 😎 🤓 🧐 😕 😟 🙁 😮 😯 😲 😳 🥺 😦 😧 😨 😰 😥 😢 😭 😱 😖 😣 😞 😓 😩 😫 🥱 😤 😡 😠 🤬 😈 👿 💀 💩 🤡 👻 👽 🤖',
    },
    {
        tab: '👋',
        name: 'People',
        emoji: '👋 🤚 🖐️ ✋ 🖖 👌 🤌 🤏 ✌️ 🤞 🤟 🤘 🤙 👈 👉 👆 👇 ☝️ 👍 👎 ✊ 👊 🤛 🤜 👏 🙌 👐 🤲 🤝 🙏 ✍️ 💪 👀 👂 👃 🧠 👶 🧒 👦 👧 🧑 👨 👩 🧓 👴 👵 🙅 🙆 💁 🙋 🙇 🤦 🤷 🏃 💃 🕺 🧘 👪',
    },
    {
        tab: '❤️',
        name: 'Hearts and signs',
        emoji: '❤️ 🧡 💛 💚 💙 💜 🖤 🤍 🤎 💔 ❣️ 💕 💞 💓 💗 💖 💘 💝 💯 💢 💥 💫 💦 💨 💬 💤 ✨ ⭐ 🌟 🔥 ✅ ❌ ❓ ❗ ⚠️ 🚫 ♻️ ➕ ➖ ✔️ 🔴 🟠 🟡 🟢 🔵 🟣 ⚫ ⚪',
    },
    {
        tab: '🐶',
        name: 'Animals and nature',
        emoji: '🐶 🐱 🐭 🐹 🐰 🦊 🐻 🐼 🐨 🐯 🦁 🐮 🐷 🐸 🐵 🙈 🙉 🙊 🐔 🐧 🐦 🐤 🦆 🦉 🐴 🦄 🐝 🦋 🐌 🐞 🐢 🐍 🐙 🐠 🐬 🐳 🐘 🐾 🌵 🌲 🌴 🌱 🍀 🍁 🍂 🌸 🌹 🌻 🌼 🌷 🌞 🌙 ☁️ ⛅ 🌧️ ⛈️ ❄️ ☃️ 🌈 🌊 ☔',
    },
    {
        tab: '🍜',
        name: 'Food and drink',
        emoji: '🍎 🍊 🍋 🍌 🍉 🍇 🍓 🍒 🍑 🥭 🍍 🥥 🥝 🍅 🥑 🥦 🥕 🌽 🌶️ 🥔 🍞 🥐 🧀 🥚 🍳 🥓 🍔 🍟 🍕 🌭 🥪 🌮 🌯 🍝 🍜 🍲 🍛 🍣 🍱 🥟 🍤 🍙 🍚 🍡 🍦 🍩 🍪 🎂 🍰 🧁 🍫 🍬 🍭 ☕ 🍵 🧋 🥤 🍺 🍻 🥂 🍷 🍸 🍹',
    },
    {
        tab: '⚽',
        name: 'Play and travel',
        emoji: '⚽ 🏀 🏈 ⚾ 🎾 🏐 🏓 🏸 🥊 ⛳ 🏊 🚴 🏆 🥇 🥈 🥉 🎯 🎮 🎲 🎸 🎹 🎤 🎧 🎬 🎨 🎁 🎉 🎊 🎈 🚗 🚕 🚌 🚆 🚇 ✈️ 🚀 🚲 🛵 ⛵ 🚢 🗺️ 🏠 🏢 🏥 🏫 🏖️ 🏝️ ⛰️ 🗼 🗽 🌏',
    },
    {
        tab: '💡',
        name: 'Things',
        emoji: '💡 📱 💻 ⌨️ 🖥️ 🖨️ 📷 🎥 📞 ☎️ 📺 ⏰ ⌛ 🔋 🔌 💰 💵 💳 🧾 ✉️ 📧 📦 📝 📄 📊 📈 📉 📅 📌 📍 📎 🔗 ✂️ 🔒 🔓 🔑 🔨 🔧 ⚙️ 🧰 🧪 💊 🩹 🔍 🔔 🔕 📣 🎵 🎶 🏷️ 🛒 🗑️',
    },
];

const RECENT = 'Lately';
const MOST_RECENT = 24;

// The ones chosen lately, the last first
const recent = useLocalStorage<string[]>('chat.emoji.recent', []);

const kind = ref(recent.value.length ? RECENT : KINDS[0].name);

const tabs = computed(() => [
    ...(recent.value.length ? [{ tab: '🕘', name: RECENT }] : []),
    ...KINDS,
]);

const shown = computed(() =>
    kind.value === RECENT
        ? recent.value
        : (
              KINDS.find((one) => one.name === kind.value) ?? KINDS[0]
          ).emoji.split(' '),
);

function pick(emoji: string) {
    recent.value = [
        emoji,
        ...recent.value.filter((one) => one !== emoji),
    ].slice(0, MOST_RECENT);
    emit('pick', emoji);
}

function opened(open: boolean) {
    if (open) {
        kind.value = recent.value.length ? RECENT : KINDS[0].name;
    } else {
        emit('closed');
    }
}
</script>

<template>
    <Tip text="Add an emoji">
        <span class="inline-flex">
            <Popover @update:open="opened">
                <PopoverTrigger as-child>
                    <Button
                        type="button"
                        size="icon-sm"
                        variant="ghost"
                        aria-label="Add an emoji"
                        :disabled="disabled"
                        data-chat-emoji
                    >
                        <Smile />
                    </Button>
                </PopoverTrigger>
                <PopoverContent
                    align="start"
                    side="top"
                    class="w-[min(19rem,calc(100vw-2rem))] p-2"
                    data-chat-emoji-picker
                >
                    <div
                        class="mb-1.5 flex gap-0.5 border-b pb-1.5"
                        role="tablist"
                    >
                        <Tip
                            v-for="one in tabs"
                            :key="one.name"
                            :text="one.name"
                        >
                            <button
                                type="button"
                                role="tab"
                                class="flex h-8 min-w-0 flex-1 items-center justify-center rounded-md text-lg leading-none transition-colors"
                                :class="
                                    kind === one.name
                                        ? 'bg-muted'
                                        : 'opacity-60 hover:opacity-100'
                                "
                                :aria-selected="kind === one.name"
                                :aria-label="one.name"
                                @click="kind = one.name"
                            >
                                {{ one.tab }}
                            </button>
                        </Tip>
                    </div>
                    <div
                        class="grid max-h-52 grid-cols-8 gap-0.5 overflow-y-auto"
                        role="tabpanel"
                        :aria-label="kind"
                    >
                        <button
                            v-for="emoji in shown"
                            :key="emoji"
                            type="button"
                            class="hover:bg-muted flex aspect-square items-center justify-center rounded-md text-xl leading-none"
                            :data-emoji="emoji"
                            @click="pick(emoji)"
                        >
                            {{ emoji }}
                        </button>
                    </div>
                </PopoverContent>
            </Popover>
        </span>
    </Tip>
</template>
