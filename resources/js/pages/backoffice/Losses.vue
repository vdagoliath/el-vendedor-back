<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { CalendarDays, Download, Search, Trash2, Warehouse as WarehouseIcon } from 'lucide-vue-next';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { index as backofficeBusinesses } from '@/routes/backoffice/businesses';
import { exportMethod as backofficeLossesExport, index as backofficeLosses } from '@/routes/backoffice/losses';
import type { BreadcrumbItem } from '@/types';

type WarehouseOption = {
    external_id: string;
    name: string;
};

type LossType = 'damaged' | 'expired' | 'stolen' | 'other';

type LossItem = {
    id: number;
    external_id: string;
    loss_at: string | null;
    loss_type: LossType;
    notes: string | null;
    quantity: number;
    previous_quantity: number | null;
    unit_cost: number | null;
    total_cost: number | null;
    product: {
        external_id: string;
        title: string;
        code: string | null;
    };
    warehouse: {
        external_id: string;
        name: string;
    };
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type Props = {
    currentBusiness: {
        id: number;
        name: string;
        slug: string;
        default_currency: string;
    };
    filters: {
        search: string;
        warehouse: string;
        loss_type: string;
        start_date: string | null;
        end_date: string | null;
    };
    stats: {
        count: number;
        total_quantity: number;
        total_cost: number;
    };
    losses: {
        data: LossItem[];
        links: PaginationLink[];
        total: number;
        from: number | null;
        to: number | null;
    };
    warehouses: WarehouseOption[];
    lossTypes: LossType[];
};

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Backoffice', href: backofficeBusinesses() },
    { title: 'Mermas', href: backofficeLosses() },
];

const quantityFormat = new Intl.NumberFormat('es-CU', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 4,
});

const currencyFormat = computed(
    () =>
        new Intl.NumberFormat('es-CU', {
            style: 'currency',
            currency: props.currentBusiness.default_currency || 'CUP',
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }),
);

const dateTime = new Intl.DateTimeFormat('es-ES', {
    dateStyle: 'medium',
    timeStyle: 'short',
});

const lossTypeLabels: Record<LossType, string> = {
    damaged: 'Dañado',
    expired: 'Vencido',
    stolen: 'Robo',
    other: 'Otro',
};

const lossTypeBadge = (type: LossType) => {
    switch (type) {
        case 'damaged':
            return 'bg-amber-500/12 text-amber-700 dark:text-amber-300';
        case 'expired':
            return 'bg-rose-500/12 text-rose-700 dark:text-rose-300';
        case 'stolen':
            return 'bg-purple-500/12 text-purple-700 dark:text-purple-300';
        default:
            return 'bg-slate-500/12 text-slate-700 dark:text-slate-300';
    }
};

const exportUrl = computed(() => {
    const query: Record<string, string> = {};
    if (props.filters.search) {
        query.search = props.filters.search;
    }
    if (props.filters.warehouse) {
        query.warehouse = props.filters.warehouse;
    }
    if (props.filters.loss_type) {
        query.loss_type = props.filters.loss_type;
    }
    if (props.filters.start_date) {
        query.start_date = props.filters.start_date;
    }
    if (props.filters.end_date) {
        query.end_date = props.filters.end_date;
    }

    return backofficeLossesExport({ query }).url;
});

const canExport = computed(() => props.losses.data.length > 0);
</script>

<template>
    <Head title="Mermas" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <section class="rounded-3xl border border-sidebar-border/70 bg-linear-to-r from-background via-background to-primary/5 p-6">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <Badge variant="secondary" class="mb-3 gap-2 rounded-full px-3 py-1">
                            <Trash2 class="size-3.5" />
                            Inventario
                        </Badge>
                        <Heading
                            :title="`Mermas de ${props.currentBusiness.name}`"
                            description="Consulta las pérdidas de inventario registradas por daño, vencimiento, robo u otras causas."
                        />
                    </div>

                    <div class="grid gap-3 sm:grid-cols-3">
                        <Card class="rounded-2xl">
                            <CardHeader class="pb-2">
                                <CardDescription>Mermas visibles</CardDescription>
                                <CardTitle class="text-2xl">{{ props.stats.count }}</CardTitle>
                            </CardHeader>
                        </Card>
                        <Card class="rounded-2xl">
                            <CardHeader class="pb-2">
                                <CardDescription>Cantidad total</CardDescription>
                                <CardTitle class="text-2xl">{{ quantityFormat.format(props.stats.total_quantity) }}</CardTitle>
                            </CardHeader>
                        </Card>
                        <Card class="rounded-2xl">
                            <CardHeader class="pb-2">
                                <CardDescription>Costo total</CardDescription>
                                <CardTitle class="text-2xl">{{ currencyFormat.format(props.stats.total_cost) }}</CardTitle>
                            </CardHeader>
                        </Card>
                    </div>
                </div>
            </section>

            <div class="flex justify-end">
                <Button as="a" :href="exportUrl" :disabled="!canExport" :aria-disabled="!canExport" variant="outline">
                    <Download class="size-4" />
                    Exportar a Excel
                </Button>
            </div>

            <Card class="rounded-3xl">
                <CardHeader class="gap-4 md:flex-row md:items-end md:justify-between">
                    <div>
                        <CardTitle>Filtros</CardTitle>
                        <CardDescription>Filtra por producto, almacén, tipo de merma o rango de fechas.</CardDescription>
                    </div>

                    <form
                        :action="backofficeLosses().url"
                        method="get"
                        class="grid w-full gap-3 lg:max-w-5xl lg:grid-cols-[1.2fr_1fr_1fr_0.9fr_0.9fr_auto_auto]"
                    >
                        <div class="relative">
                            <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input name="search" :default-value="props.filters.search" placeholder="Buscar producto" class="pl-9" />
                        </div>
                        <select
                            name="warehouse"
                            :default-value="props.filters.warehouse"
                            class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none"
                        >
                            <option value="">Todos los almacenes</option>
                            <option
                                v-for="warehouse in props.warehouses"
                                :key="warehouse.external_id"
                                :value="warehouse.external_id"
                            >
                                {{ warehouse.name }}
                            </option>
                        </select>
                        <select
                            name="loss_type"
                            :default-value="props.filters.loss_type"
                            class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none"
                        >
                            <option value="">Todos los tipos</option>
                            <option v-for="type in props.lossTypes" :key="type" :value="type">
                                {{ lossTypeLabels[type] }}
                            </option>
                        </select>
                        <Input name="start_date" type="date" :default-value="props.filters.start_date ?? ''" />
                        <Input name="end_date" type="date" :default-value="props.filters.end_date ?? ''" />
                        <Button type="submit">Filtrar</Button>
                        <Link :href="backofficeLosses()" class="inline-flex">
                            <Button type="button" variant="outline" class="w-full">Limpiar</Button>
                        </Link>
                    </form>
                </CardHeader>
            </Card>

            <Card class="rounded-3xl">
                <CardHeader>
                    <CardTitle>Listado de mermas</CardTitle>
                    <CardDescription>{{ props.losses.total }} merma(s) en total.</CardDescription>
                </CardHeader>

                <CardContent class="grid gap-3">
                    <article
                        v-for="loss in props.losses.data"
                        :key="loss.id"
                        class="rounded-2xl border border-border/70 bg-muted/20 p-4"
                    >
                        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                            <div class="space-y-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-base font-semibold">{{ loss.product.title }}</h3>
                                    <Badge v-if="loss.product.code" variant="outline" class="rounded-full">
                                        {{ loss.product.code }}
                                    </Badge>
                                    <span
                                        class="inline-flex items-center rounded-full px-2.5 py-1 text-[10px] font-semibold uppercase tracking-[0.18em]"
                                        :class="lossTypeBadge(loss.loss_type)"
                                    >
                                        {{ lossTypeLabels[loss.loss_type] }}
                                    </span>
                                </div>

                                <div class="flex flex-wrap items-center gap-x-3 gap-y-2 text-sm text-muted-foreground">
                                    <span class="inline-flex items-center gap-2">
                                        <WarehouseIcon class="size-4" />
                                        {{ loss.warehouse.name }}
                                    </span>
                                    <span class="inline-flex items-center gap-2">
                                        <CalendarDays class="size-4" />
                                        {{ loss.loss_at ? dateTime.format(new Date(loss.loss_at)) : 'Sin fecha' }}
                                    </span>
                                    <span v-if="loss.previous_quantity !== null">
                                        Previo: {{ quantityFormat.format(loss.previous_quantity) }}
                                    </span>
                                    <span v-if="loss.unit_cost !== null">
                                        Costo unitario: {{ currencyFormat.format(loss.unit_cost) }}
                                    </span>
                                </div>

                                <p v-if="loss.notes" class="text-sm text-muted-foreground">{{ loss.notes }}</p>
                            </div>

                            <div class="text-right">
                                <p class="text-2xl font-semibold tabular-nums text-rose-700">
                                    -{{ quantityFormat.format(loss.quantity) }}
                                </p>
                                <p v-if="loss.total_cost !== null" class="text-xs text-muted-foreground">
                                    {{ currencyFormat.format(loss.total_cost) }}
                                </p>
                            </div>
                        </div>
                    </article>

                    <div
                        v-if="props.losses.data.length === 0"
                        class="rounded-2xl border border-dashed border-border px-6 py-10 text-center text-sm text-muted-foreground"
                    >
                        No hay mermas para los filtros aplicados.
                    </div>

                    <div
                        v-if="props.losses.links.length > 3"
                        class="flex flex-wrap items-center justify-between gap-3 border-t border-border/60 pt-4"
                    >
                        <p class="text-sm text-muted-foreground">
                            Mostrando {{ props.losses.from ?? 0 }} - {{ props.losses.to ?? 0 }} de {{ props.losses.total }}
                        </p>

                        <div class="flex flex-wrap gap-2">
                            <Link
                                v-for="link in props.losses.links"
                                :key="`${link.label}-${link.url}`"
                                :href="link.url || ''"
                                :class="[
                                    'rounded-xl border px-3 py-2 text-sm',
                                    link.active ? 'border-primary bg-primary text-primary-foreground' : 'border-border bg-background',
                                    !link.url ? 'pointer-events-none opacity-40' : '',
                                ]"
                                v-html="link.label"
                            />
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
