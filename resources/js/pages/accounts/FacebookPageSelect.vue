<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { IconBrandFacebook, IconExternalLink } from '@tabler/icons-vue';

import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import PopupLayout from '@/layouts/PopupLayout.vue';

import {
    connect as connectFacebook,
    select as selectFacebookPage,
} from '@/routes/app/social/facebook';

interface Page {
    id: string;
    name: string;
    username: string | null;
    picture: string | null;
}

interface Workspace {
    id: string;
    name: string;
}

interface Props {
    workspace: Workspace;
    pages: Page[];
    connectedPageIds: string[];
    guestConnection: boolean;
}

const props = defineProps<Props>();

const form = useForm({ page_id: '', page_ids: [] as string[] });

const handleSelectPage = (page: Page) => {
    form.page_id = page.id;
    form.post(selectFacebookPage.url());
};

const togglePage = (pageId: string) => {
    form.page_ids = form.page_ids.includes(pageId)
        ? form.page_ids.filter((id) => id !== pageId)
        : [...form.page_ids, pageId];
};

const selectAllPages = () => {
    form.page_ids = props.pages.map((page) => page.id);
};

const handleConnectPages = () => {
    if (form.page_ids.length > 0) {
        form.post(selectFacebookPage.url());
    }
};

const openExternal = (url: string | null) => {
    if (url) {
        window.open(url, '_blank', 'noopener');
    }
};

const pageUrl = (username: string | null): string | null =>
    username ? `https://www.facebook.com/${username}` : null;
</script>

<template>
    <PopupLayout :title="$t('accounts.facebook.title')">
        <div class="flex flex-col gap-6">
            <div class="flex items-center gap-3">
                <img
                    src="/images/accounts/facebook.png"
                    alt="Facebook"
                    class="h-10 w-10"
                />
                <div>
                    <h1 class="text-xl font-bold tracking-tight">
                        {{ $t('accounts.facebook.title') }}
                    </h1>
                    <p class="text-sm text-muted-foreground">
                        {{ $t('accounts.facebook.description') }}
                    </p>
                </div>
            </div>

            <div
                class="rounded-lg border bg-muted/50 p-3 text-sm text-muted-foreground"
            >
                {{ $t('accounts.facebook.meta_access_hint') }}
                <Link
                    v-if="!guestConnection"
                    :href="connectFacebook.url()"
                    class="ml-1 font-medium text-primary underline"
                >
                    {{ $t('accounts.facebook.change_meta_access') }}
                </Link>
            </div>

            <div v-if="pages.length === 0" class="py-12 text-center">
                <div
                    class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-muted"
                >
                    <IconBrandFacebook class="h-7 w-7 text-muted-foreground" />
                </div>
                <h3 class="mt-4 text-lg font-semibold">
                    {{ $t('accounts.facebook.no_pages') }}
                </h3>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ $t('accounts.facebook.no_pages_description') }}
                </p>
            </div>

            <div v-else class="grid gap-3">
                <div
                    v-if="!guestConnection && pages.length > 1"
                    class="flex justify-end"
                >
                    <Button variant="ghost" size="sm" @click="selectAllPages">
                        {{ $t('accounts.facebook.select_all') }}
                    </Button>
                </div>
                <div
                    v-for="page in pages"
                    :key="page.id"
                    class="flex items-center gap-4 rounded-lg border bg-card p-4"
                    dusk="facebook-page"
                >
                    <input
                        v-if="!guestConnection"
                        type="checkbox"
                        :checked="form.page_ids.includes(page.id)"
                        :aria-label="`${$t('accounts.facebook.choose')} ${page.name}`"
                        class="size-4 shrink-0 accent-primary"
                        @change="togglePage(page.id)"
                    />
                    <Avatar class="h-12 w-12 shrink-0 rounded-lg">
                        <AvatarImage
                            v-if="page.picture"
                            :src="page.picture"
                            class="object-cover"
                        />
                        <AvatarFallback
                            class="rounded-lg bg-blue-100 dark:bg-blue-900"
                        >
                            <IconBrandFacebook
                                class="h-6 w-6 text-blue-600 dark:text-blue-400"
                            />
                        </AvatarFallback>
                    </Avatar>
                    <div class="min-w-0 flex-1">
                        <h3 class="truncate font-semibold">{{ page.name }}</h3>
                        <p
                            v-if="page.username"
                            class="truncate text-sm text-muted-foreground"
                        >
                            facebook.com/{{ page.username }}
                        </p>
                        <p
                            v-else
                            class="truncate text-sm text-muted-foreground"
                        >
                            {{ $t('accounts.facebook.page_label') }}
                        </p>
                        <p
                            v-if="connectedPageIds.includes(page.id)"
                            class="text-xs text-emerald-700"
                        >
                            {{ $t('accounts.facebook.already_connected') }}
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <Button
                            v-if="pageUrl(page.username)"
                            variant="ghost"
                            size="sm"
                            @click="openExternal(pageUrl(page.username))"
                        >
                            <IconExternalLink class="h-4 w-4" />
                            <span class="hidden sm:inline">{{
                                $t('accounts.facebook.view')
                            }}</span>
                        </Button>
                        <Button
                            v-if="guestConnection"
                            size="sm"
                            dusk="choose-facebook-page"
                            :disabled="form.processing"
                            @click="handleSelectPage(page)"
                        >
                            {{ $t('accounts.facebook.choose') }}
                        </Button>
                    </div>
                </div>
                <p v-if="form.errors.page_ids" class="text-sm text-destructive">
                    {{ form.errors.page_ids }}
                </p>
                <Button
                    v-if="!guestConnection"
                    class="w-full"
                    dusk="connect-facebook-pages"
                    :disabled="form.processing || form.page_ids.length === 0"
                    @click="handleConnectPages"
                >
                    {{ $t('accounts.facebook.connect_selected') }}
                </Button>
            </div>
        </div>
    </PopupLayout>
</template>
