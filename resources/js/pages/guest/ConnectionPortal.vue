<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    IconBrandFacebook,
    IconBrandInstagram,
    IconCheck,
    IconLock,
} from '@tabler/icons-vue';

interface Props {
    projectName: string;
    platform: 'facebook' | 'instagram';
    platformLabel: string;
    permissions: string[];
    connectUrl: string;
    copy: {
        eyebrow: string;
        title: string;
        description: string;
        permissionsTitle: string;
        privacy: string;
        connect: string;
    };
}

defineProps<Props>();
</script>

<template>
    <div
        class="flex min-h-screen items-center justify-center bg-background px-4 py-10 text-foreground"
    >
        <Head :title="copy.title" />

        <main
            class="w-full max-w-lg rounded-2xl border bg-card p-6 shadow-sm sm:p-8"
            dusk="guest-connection-portal"
        >
            <div class="flex flex-col gap-6">
                <div class="flex items-center justify-between gap-4">
                    <div
                        class="flex items-center gap-2 text-sm font-medium text-muted-foreground"
                    >
                        <IconLock class="h-4 w-4" aria-hidden="true" />
                        <span>{{ copy.eyebrow }}</span>
                    </div>
                    <div
                        class="flex h-12 w-12 items-center justify-center rounded-xl bg-muted"
                    >
                        <IconBrandFacebook
                            v-if="platform === 'facebook'"
                            class="h-7 w-7 text-blue-600"
                            aria-hidden="true"
                        />
                        <IconBrandInstagram
                            v-else
                            class="h-7 w-7 text-pink-600"
                            aria-hidden="true"
                        />
                    </div>
                </div>

                <div class="flex flex-col gap-2">
                    <p class="text-sm font-medium text-muted-foreground">
                        {{ projectName }}
                    </p>
                    <h1 class="text-2xl font-semibold tracking-tight">
                        {{ copy.title }}
                    </h1>
                    <p class="text-sm leading-6 text-muted-foreground">
                        {{ copy.description }}
                    </p>
                </div>

                <div
                    class="flex flex-col gap-3 rounded-xl border bg-muted/40 p-4"
                >
                    <h2 class="text-sm font-semibold">
                        {{ copy.permissionsTitle }}
                    </h2>
                    <ul class="flex flex-col gap-3">
                        <li
                            v-for="permission in permissions"
                            :key="permission"
                            class="flex gap-3 text-sm leading-5"
                        >
                            <IconCheck
                                class="mt-0.5 h-4 w-4 shrink-0 text-green-600"
                                aria-hidden="true"
                            />
                            <span>{{ permission }}</span>
                        </li>
                    </ul>
                </div>

                <p class="text-xs leading-5 text-muted-foreground">
                    {{ copy.privacy }}
                </p>

                <a
                    :href="connectUrl"
                    class="inline-flex h-11 items-center justify-center rounded-md bg-primary px-5 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                    dusk="connect-social-account"
                >
                    {{ copy.connect }}
                </a>
            </div>
        </main>
    </div>
</template>
