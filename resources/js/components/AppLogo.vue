<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useTranslations } from '@/composables/useTranslations';
import AppLogoIcon from '@/components/AppLogoIcon.vue';

const page = usePage<{ name?: string }>();
const { t } = useTranslations();

const appName = computed(() =>
    page.props.name && page.props.name !== 'Laravel'
        ? page.props.name
        : 'El Vendedor',
);
</script>

<template>
    <div
        class="flex aspect-square size-10 items-center justify-center rounded-2xl bg-sidebar-primary text-sidebar-primary-foreground shadow-sm ring-1 ring-sidebar-border/70"
    >
        <AppLogoIcon class="size-5 fill-current text-white dark:text-black" />
    </div>
    <div class="ml-1 grid min-w-0 flex-1 text-left leading-tight">
        <span class="truncate text-sm font-semibold text-sidebar-foreground">
            {{ appName }}
        </span>
        <span
            class="truncate text-[11px] font-medium uppercase tracking-[0.2em] text-sidebar-foreground/55"
        >
            {{ t('backoffice') }}
        </span>
    </div>
</template>
