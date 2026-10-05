<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { IconAlertTriangle, IconCheck, IconPlus } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';

import TelegramConnectDialog from '@/components/accounts/TelegramConnectDialog.vue';
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import { useOAuthPopup } from '@/composables/useOAuthPopup';
import { Platform } from '@/types/platform';

import { disconnect, toggle } from '@/routes/app/accounts';

export interface AvailablePlatform {
    value: string;
    label: string;
    color: string;
    network: string;
}

export interface ConnectedAccount {
    id: string;
    platform: string;
    network: string;
    username: string;
    display_name: string;
    avatar_url: string | null;
    status: 'connected' | 'disconnected' | 'token_expired' | null;
    is_active: boolean;
}

const props = withDefaults(
    defineProps<{
        platforms: AvailablePlatform[];
        connectedAccounts?: ConnectedAccount[];
        gridClass?: string;
    }>(),
    {
        connectedAccounts: () => [],
        gridClass: 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-5',
    },
);

const getPlatformDescription = (platform: string): string =>
    trans(`accounts.descriptions.${platform}`);

// Mirrors `NetworksGrid.vue` from the marketing site — pastel tile bg
// + ink 2px border + slight rotation per platform, real PNG logo inside.
// `instagram-facebook` falls back to the base brand image and same color
// since it's a variant of the same network.
const platformTheme: Record<
    string,
    { bg: string; rotate: string; image: string }
> = {
    instagram: {
        bg: 'bg-pink-200',
        rotate: '-rotate-2',
        image: '/images/accounts/instagram.png',
    },
    'instagram-facebook': {
        bg: 'bg-pink-200',
        rotate: '-rotate-2',
        image: '/images/accounts/instagram.png',
    },
    facebook: {
        bg: 'bg-sky-200',
        rotate: 'rotate-1',
        image: '/images/accounts/facebook.png',
    },
    linkedin: {
        bg: 'bg-blue-200',
        rotate: '-rotate-1',
        image: '/images/accounts/linkedin.png',
    },
    x: {
        bg: 'bg-amber-200',
        rotate: 'rotate-2',
        image: '/images/accounts/x.png',
    },
    tiktok: {
        bg: 'bg-fuchsia-200',
        rotate: '-rotate-1',
        image: '/images/accounts/tiktok.png',
    },
    youtube: {
        bg: 'bg-red-200',
        rotate: 'rotate-1',
        image: '/images/accounts/youtube.png',
    },
    pinterest: {
        bg: 'bg-rose-200',
        rotate: '-rotate-2',
        image: '/images/accounts/pinterest.png',
    },
    threads: {
        bg: 'bg-emerald-200',
        rotate: 'rotate-2',
        image: '/images/accounts/threads.png',
    },
    bluesky: {
        bg: 'bg-cyan-200',
        rotate: '-rotate-1',
        image: '/images/accounts/bluesky.png',
    },
    mastodon: {
        bg: 'bg-violet-200',
        rotate: 'rotate-1',
        image: '/images/accounts/mastodon.png',
    },
    telegram: {
        bg: 'bg-sky-200',
        rotate: '-rotate-2',
        image: '/images/accounts/telegram.png',
    },
    discord: {
        bg: 'bg-indigo-200',
        rotate: 'rotate-1',
        image: '/images/accounts/discord.png',
    },
};

const themeFor = (value: string) =>
    platformTheme[value] ?? { bg: 'bg-muted', rotate: '', image: '' };

const accountsByPlatform = computed((): Record<string, ConnectedAccount[]> => {
    const accounts: Record<string, ConnectedAccount[]> = {};

    for (const platform of props.platforms) {
        accounts[platform.value] = props.connectedAccounts.filter(
            (account) =>
                account.platform === platform.value ||
                (platform.value === Platform.LinkedIn &&
                    account.platform === Platform.LinkedInPage),
        );
    }

    return accounts;
});

const telegramOpen = ref(false);
const disconnectModal = ref<InstanceType<typeof ConfirmDeleteModal> | null>(
    null,
);

const { openOAuthPopup } = useOAuthPopup((result) => {
    if (result.success) {
        toast.success(result.message);
        router.reload();
        return;
    }

    toast.error(result.message);
});

const disconnectAccount = (account: ConnectedAccount) => {
    disconnectModal.value?.open({
        url: disconnect.url(account.id),
        confirmText: account.username || account.display_name,
    });
};

const needsReconnect = (account: ConnectedAccount): boolean =>
    account.status === 'disconnected' || account.status === 'token_expired';

const toggleAccount = (account: ConnectedAccount) => {
    router.put(toggle.url(account.id), {}, { preserveScroll: true });
};

const connectEntryFor = (platformValue: string): string =>
    platformValue === Platform.LinkedInPage ? Platform.LinkedIn : platformValue;

const openConnect = (platformValue: string) => {
    if (platformValue === Platform.Telegram) {
        telegramOpen.value = true;
        return;
    }

    openOAuthPopup(platformValue);
};

const reconnectAccount = (account: ConnectedAccount) => {
    openConnect(connectEntryFor(account.platform));
};

const CardState = {
    Connect: 'connect',
    Connected: 'connected',
    Reconnect: 'reconnect',
    Inactive: 'inactive',
} as const;

type CardStateValue = (typeof CardState)[keyof typeof CardState];

const cardState = computed((): Record<string, CardStateValue> => {
    const map: Record<string, CardStateValue> = {};

    for (const platform of props.platforms) {
        const accounts = accountsByPlatform.value[platform.value];
        map[platform.value] =
            accounts.length === 0
                ? CardState.Connect
                : accounts.some(
                        (account) =>
                            account.is_active && !needsReconnect(account),
                    )
                  ? CardState.Connected
                  : accounts.every(needsReconnect)
                    ? CardState.Reconnect
                    : CardState.Inactive;
    }

    return map;
});
</script>

<template>
    <div>
        <div :class="['grid gap-4', gridClass]">
            <div
                v-for="platform in platforms"
                :key="platform.value"
                :class="[
                    'group relative flex flex-col items-center gap-3 rounded-xl border-2 border-foreground p-4 text-center shadow-xs transition-shadow',
                    cardState[platform.value] === CardState.Connected
                        ? 'bg-emerald-50'
                        : cardState[platform.value] === CardState.Reconnect ||
                            cardState[platform.value] === CardState.Inactive
                          ? 'bg-amber-50'
                          : 'bg-card hover:shadow-md',
                ]"
            >
                <span
                    v-if="cardState[platform.value] === CardState.Connected"
                    class="absolute -top-2 -right-2 inline-flex size-6 items-center justify-center rounded-full border-2 border-foreground bg-emerald-200 text-emerald-700 shadow-2xs"
                    aria-hidden="true"
                >
                    <IconCheck class="size-3.5" stroke-width="3" />
                </span>
                <span
                    v-else-if="
                        cardState[platform.value] === CardState.Reconnect
                    "
                    class="absolute -top-2 -right-2 inline-flex size-6 items-center justify-center rounded-full border-2 border-foreground bg-amber-200 text-amber-700 shadow-2xs"
                    aria-hidden="true"
                >
                    <IconAlertTriangle class="size-3.5" stroke-width="2.5" />
                </span>
                <span
                    v-else-if="cardState[platform.value] === CardState.Connect"
                    class="pointer-events-none absolute -top-2 -right-2 inline-flex size-6 items-center justify-center rounded-full border-2 border-foreground bg-violet-200 text-foreground opacity-0 shadow-2xs transition-all group-hover:scale-110 group-hover:rotate-90 group-hover:opacity-100"
                    aria-hidden="true"
                >
                    <IconPlus class="size-3.5" stroke-width="3" />
                </span>

                <div
                    :class="[
                        themeFor(platform.value).bg,
                        themeFor(platform.value).rotate,
                        'inline-flex size-16 items-center justify-center rounded-2xl border-2 border-foreground shadow-sm transition-transform group-hover:!rotate-0',
                    ]"
                >
                    <img
                        :src="themeFor(platform.value).image"
                        :alt="platform.label"
                        class="size-9 rounded-lg"
                        loading="lazy"
                    />
                </div>

                <div class="w-full min-w-0 flex-1">
                    <span
                        class="block truncate text-sm font-semibold text-foreground"
                    >
                        <template v-if="platform.label.includes('(')">
                            {{ platform.label.split('(')[0].trim() }}
                        </template>
                        <template v-else>{{ platform.label }}</template>
                    </span>
                    <p
                        v-if="cardState[platform.value] === CardState.Connect"
                        class="mt-0.5 line-clamp-2 text-xs leading-tight text-foreground/60"
                    >
                        {{ getPlatformDescription(platform.value) }}
                    </p>
                    <div v-else class="mt-2 flex flex-col gap-2 text-left">
                        <div
                            v-for="account in accountsByPlatform[
                                platform.value
                            ]"
                            :key="account.id"
                            class="flex min-w-0 flex-col gap-2 rounded-lg border border-foreground/20 bg-background p-2"
                        >
                            <div class="flex w-full min-w-0 items-center gap-2">
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-xs font-medium">
                                        {{
                                            account.display_name ||
                                            account.username
                                        }}
                                    </p>
                                    <p
                                        v-if="needsReconnect(account)"
                                        class="text-xs text-amber-700"
                                    >
                                        {{ $t('accounts.connection_lost') }}
                                    </p>
                                    <p
                                        v-else-if="!account.is_active"
                                        class="text-xs text-amber-700"
                                    >
                                        {{ $t('accounts.inactive') }}
                                    </p>
                                </div>
                                <Switch
                                    :model-value="account.is_active"
                                    :aria-label="`${$t('accounts.toggle_account')} ${account.display_name || account.username}`"
                                    @update:model-value="toggleAccount(account)"
                                />
                            </div>
                            <div class="flex flex-wrap justify-end gap-1">
                                <Button
                                    v-if="needsReconnect(account)"
                                    variant="outline"
                                    size="sm"
                                    @click="reconnectAccount(account)"
                                >
                                    {{ $t('accounts.reconnect') }}
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    :aria-label="`${$t('accounts.disconnect')} ${account.display_name || account.username}`"
                                    @click="disconnectAccount(account)"
                                >
                                    {{ $t('accounts.disconnect') }}
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>

                <Button
                    size="sm"
                    class="mt-auto w-full"
                    @click="openConnect(platform.value)"
                >
                    {{
                        $t(
                            accountsByPlatform[platform.value].length
                                ? 'accounts.connect_another'
                                : 'accounts.connect_cta',
                        )
                    }}
                </Button>
                <p
                    v-if="platform.value === Platform.Facebook"
                    class="text-xs leading-tight text-foreground/60"
                >
                    {{ $t('accounts.facebook.meta_selection_tip') }}
                </p>
            </div>
        </div>

        <TelegramConnectDialog v-model:open="telegramOpen" />

        <ConfirmDeleteModal
            ref="disconnectModal"
            :title="$t('accounts.disconnect_modal.title')"
            :description="$t('accounts.disconnect_modal.description')"
            :action="$t('accounts.disconnect_modal.confirm')"
            :cancel="$t('accounts.disconnect_modal.cancel')"
        />
    </div>
</template>
