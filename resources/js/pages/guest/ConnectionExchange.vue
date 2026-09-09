<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { IconLoader2, IconLock } from '@tabler/icons-vue';
import { onMounted, ref } from 'vue';

interface Props {
    claimUrl: string;
    copy: {
        title: string;
        description: string;
        invalid: string;
    };
}

const props = defineProps<Props>();
const invalid = ref(false);
const form = useForm({ token: '' });

onMounted(() => {
    const fragment = new URLSearchParams(window.location.hash.slice(1));
    const token = fragment.get('token');

    window.history.replaceState(
        null,
        '',
        window.location.pathname + window.location.search,
    );

    if (!token || !/^[A-Za-z0-9]{80}$/.test(token)) {
        invalid.value = true;
        return;
    }

    form.token = token;
    form.post(props.claimUrl, {
        onError: () => {
            invalid.value = true;
            form.reset('token');
        },
    });
});
</script>

<template>
    <div
        class="flex min-h-screen items-center justify-center bg-background px-4 py-10 text-foreground"
    >
        <Head :title="copy.title" />

        <main
            class="w-full max-w-md rounded-2xl border bg-card p-8 text-center shadow-sm"
            role="status"
            aria-live="polite"
        >
            <div class="flex flex-col items-center gap-4">
                <div
                    class="flex h-14 w-14 items-center justify-center rounded-full bg-muted text-muted-foreground"
                >
                    <IconLock
                        v-if="invalid"
                        class="h-7 w-7"
                        aria-hidden="true"
                    />
                    <IconLoader2
                        v-else
                        class="h-7 w-7 animate-spin"
                        aria-hidden="true"
                    />
                </div>
                <h1 class="text-xl font-semibold">{{ copy.title }}</h1>
                <p class="text-sm leading-6 text-muted-foreground">
                    {{ invalid ? copy.invalid : copy.description }}
                </p>
            </div>
        </main>
    </div>
</template>
