<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { BadgeCheck, Boxes, Building2, ClipboardCheck, Users } from 'lucide-vue-next';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import { index as backofficeBusinesses } from '@/routes/backoffice/businesses';
import { index as backofficePreparation } from '@/routes/backoffice/preparation';
import { index as backofficeProducts } from '@/routes/backoffice/products';
import { index as backofficeTeam } from '@/routes/backoffice/team';
import type { BreadcrumbItem } from '@/types';

type Props = {
    currentBusiness: {
        id: number;
        name: string;
        slug: string;
        address: string | null;
        phone: string | null;
        default_currency: string;
        license_expires_at: string | null;
    };
    summary: {
        ready_items: number;
        total_items: number;
        products_count: number;
        active_members_count: number;
        synced_employees_count: number;
        latest_sync_at: string | null;
        is_ready: boolean;
    };
    checklist: Array<{
        key: string;
        title: string;
        description: string;
        is_ready: boolean;
        meta?: string | null;
    }>;
};

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Backoffice',
        href: backofficeBusinesses(),
    },
    {
        title: 'Preparación',
        href: backofficePreparation(),
    },
];

const dateTimeFormatter = new Intl.DateTimeFormat('es-ES', {
    dateStyle: 'medium',
    timeStyle: 'short',
});
</script>

<template>
    <Head title="Preparación" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <section class="rounded-3xl border border-sidebar-border/70 bg-linear-to-br from-background via-background to-primary/5 p-6">
                <div class="flex flex-col gap-5 xl:flex-row xl:items-end xl:justify-between">
                    <div class="max-w-3xl space-y-3">
                        <Badge variant="secondary" class="gap-2 rounded-full px-3 py-1">
                            <ClipboardCheck class="size-3.5" />
                            Preparación operativa
                        </Badge>
                        <Heading
                            :title="`Negocio listo para sincronizar: ${props.currentBusiness.name}`"
                            description="Este checklist guía al implementador para dejar el negocio preparado antes de empezar a sincronizar dispositivos."
                        />
                        <div class="flex flex-wrap items-center gap-3 text-sm text-muted-foreground">
                            <span class="inline-flex items-center gap-2">
                                <Building2 class="size-4" />
                                {{ props.currentBusiness.slug }}
                            </span>
                            <span class="inline-flex items-center gap-2">
                                <Boxes class="size-4" />
                                {{ props.summary.products_count }} producto(s)
                            </span>
                            <span class="inline-flex items-center gap-2">
                                <Users class="size-4" />
                                {{ props.summary.active_members_count + props.summary.synced_employees_count }} persona(s) preparadas
                            </span>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <Link :href="backofficeProducts()" class="inline-flex">
                            <Button variant="outline">Revisar productos</Button>
                        </Link>
                        <Link :href="backofficeTeam()" class="inline-flex">
                            <Button variant="outline">Revisar equipo</Button>
                        </Link>
                        <Link :href="backofficeBusinesses()" class="inline-flex">
                            <Button variant="outline">Cambiar negocio</Button>
                        </Link>
                    </div>
                </div>
            </section>

            <div class="grid gap-6 xl:grid-cols-[0.95fr_1.25fr]">
                <Card class="rounded-3xl">
                    <CardHeader>
                        <CardTitle>Resumen de alistamiento</CardTitle>
                        <CardDescription>
                            Estado general del negocio actual para habilitar sincronización de dispositivos.
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="grid gap-4">
                        <div class="rounded-2xl border border-border/70 bg-muted/20 p-4">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <p class="text-sm font-medium">Avance del checklist</p>
                                    <p class="text-3xl font-semibold">
                                        {{ props.summary.ready_items }}/{{ props.summary.total_items }}
                                    </p>
                                </div>
                                <Badge :variant="props.summary.is_ready ? 'default' : 'secondary'" class="rounded-full">
                                    {{ props.summary.is_ready ? 'Listo para sincronizar' : 'Pendiente' }}
                                </Badge>
                            </div>
                        </div>

                        <div class="grid gap-3 md:grid-cols-2">
                            <div class="rounded-2xl border border-border/70 p-4">
                                <p class="text-sm text-muted-foreground">Productos</p>
                                <p class="mt-2 text-2xl font-semibold">{{ props.summary.products_count }}</p>
                            </div>
                            <div class="rounded-2xl border border-border/70 p-4">
                                <p class="text-sm text-muted-foreground">Miembros activos</p>
                                <p class="mt-2 text-2xl font-semibold">{{ props.summary.active_members_count }}</p>
                            </div>
                            <div class="rounded-2xl border border-border/70 p-4">
                                <p class="text-sm text-muted-foreground">Empleados sincronizados</p>
                                <p class="mt-2 text-2xl font-semibold">{{ props.summary.synced_employees_count }}</p>
                            </div>
                            <div class="rounded-2xl border border-border/70 p-4">
                                <p class="text-sm text-muted-foreground">Última actividad sync</p>
                                <p class="mt-2 text-sm font-medium">
                                    {{ props.summary.latest_sync_at ? dateTimeFormatter.format(new Date(props.summary.latest_sync_at)) : 'Sin actividad aún' }}
                                </p>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card class="rounded-3xl">
                    <CardHeader>
                        <CardTitle>Checklist de preparación</CardTitle>
                        <CardDescription>
                            Cada punto representa un requisito operativo para dejar el negocio listo.
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="grid gap-4">
                        <article
                            v-for="item in checklist"
                            :key="item.key"
                            class="rounded-2xl border border-border/70 bg-muted/20 p-4"
                        >
                            <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                                <div class="space-y-2">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 class="font-semibold">{{ item.title }}</h3>
                                        <Badge :variant="item.is_ready ? 'default' : 'secondary'" class="rounded-full">
                                            {{ item.is_ready ? 'OK' : 'Pendiente' }}
                                        </Badge>
                                    </div>
                                    <p class="text-sm text-muted-foreground">
                                        {{ item.description }}
                                    </p>
                                    <p v-if="item.meta" class="text-xs text-muted-foreground">
                                        {{ item.meta }}
                                    </p>
                                </div>

                                <div class="rounded-2xl border border-border/70 bg-background p-3">
                                    <BadgeCheck
                                        class="size-5"
                                        :class="item.is_ready ? 'text-emerald-600' : 'text-amber-500'"
                                    />
                                </div>
                            </div>
                        </article>
                    </CardContent>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>
