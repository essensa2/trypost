<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { IconCircleCheck, IconCircleX } from '@tabler/icons-vue';

defineProps<{
    success: boolean;
    title: string;
    message: string;
}>();
</script>

<template>
    <div
        class="flex min-h-screen items-center justify-center bg-background px-4 py-10 text-foreground"
    >
        <Head :title="title" />

        <main
            class="w-full max-w-md rounded-2xl border bg-card p-8 text-center shadow-sm"
            role="status"
            aria-live="polite"
        >
            <div class="flex flex-col items-center gap-4">
                <div
                    class="flex h-14 w-14 items-center justify-center rounded-full"
                    :class="
                        success
                            ? 'bg-green-100 text-green-600'
                            : 'bg-red-100 text-red-600'
                    "
                >
                    <IconCircleCheck
                        v-if="success"
                        class="h-7 w-7"
                        aria-hidden="true"
                    />
                    <IconCircleX v-else class="h-7 w-7" aria-hidden="true" />
                </div>
                <h1 class="text-xl font-semibold">{{ title }}</h1>
                <p class="text-sm leading-6 text-muted-foreground">
                    {{ message }}
                </p>
            </div>
        </main>
    </div>
</template>
