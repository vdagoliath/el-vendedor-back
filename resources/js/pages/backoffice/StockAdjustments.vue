<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { CalendarDays, Download, Search, SlidersHorizontal, Warehouse as WarehouseIcon } from 'lucide-vue-next';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { index as backofficeBusinesses } from '@/routes/backoffice/businesses';
import {
    exportMethod as backofficeStockAdjustmentsExport,
    index as backofficeStockAdjustments,
} from '@/routes/backoffice/stock-adjustments';
import type { BreadcrumbItem } from '@/types';

type WarehouseOption = {
    external_id: string;
    name: string;
};

type AdjustmentItem = {
    id: number;
    external_id: string;
    adjustment_at: string | null;
    reason: string | null;
    previous_quantity: number | null;
    target_quantity: number;
    change_quantity: number;
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
        reason: string;
        start_date: string | null;
        end_date: string | null;
    };
    stats: {
        count: number;
        total_change: number;
    };
    adjustments: {
        data: AdjustmentItem[];
        links: PaginationLink[];
        total: number;
        from: number | null;
        to: number | null;
    };
    warehouses: WarehouseOption[];
};

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Backoffice', href: backofficeBusinesses() },
    { title: 'Ajustes de inventario', href: backofficeStockAdjustments() },
];

const quantityFormat = new Intl.NumberFormat('es-CU', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 4,
});

const signedFormat = new Intl.NumberFormat('es-CU', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 4,
    signDisplay: 'exceptZero',
});

const dateTime = new Intl.DateTimeFormat('es-ES', {
    dateStyle: 'medium',
    timeStyle: 'short',
});

const exportUrl = computed(() => {
    const query: Record<string, string> = {};
    if (props.filters.search) {
        query.search = props.filters.search;
    }
    if (props.filters.warehouse) {
        query.warehouse = props.filters.warehouse;
    }
    if (props.filters.reason) {
        query.reason = props.filters.reason;
    }
    if (props.filters.start_date) {
        query.start_date = props.filters.start_date;
    }
    if (props.filters.end_date) {
        query.end_date = props.filters.end_date;
    }

    return backofficeStockAdjustmentsExport({ query }).url;
});

const canExport = computed(() => props.adjustments.data.length > 0);
</script>

<template>
    <Head title="Ajustes de inventario" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <section class="rounded-3xl border border-sidebar-border/70 bg-linear-to-r from-background via-background to-primary/5 p-6">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <Badge variant="secondary" class="mb-3 gap-2 rounded-full px-3 py-1">
                            <SlidersHorizontal class="size-3.5" />
                            Inventario
                        </Badge>
                        <Heading
                            :title="`Ajustes de inventario de ${props.currentBusiness.name}`"
                            description="Consulta correcciones manuales aplicadas al stock por almacén."
                        />
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <Card class="rounded-2xl">
                            <CardHeader class="pb-2">
                                <CardDescription>Ajustes visibles</CardDescription>
                                <CardTitle class="text-2xl">{{ props.stats.count }}</CardTitle>
                            </CardHeader>
                        </Card>
                        <Card class="rounded-2xl">
                            <CardHeader class="pb-2">
                                <CardDescription>Diferencia neta</CardDescription>
                                <CardTitle class="text-2xl">{{ signedFormat.format(props.stats.total_change) }}</CardTitle>
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
                        <CardDescription>Filtra por producto, almacén, razón o rango de fechas.</CardDescription>
                    </div>

                    <form
                        :action="backofficeStockAdjustments().url"
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
                        <Input name="reason" :default-value="props.filters.reason" placeholder="Razón" />
                        <Input name="start_date" type="date" :default-value="props.filters.start_date ?? ''" />
                        <Input name="end_date" type="date" :default-value="props.filters.end_date ?? ''" />
                        <Button type="submit">Filtrar</Button>
                        <Link :href="backofficeStockAdjustments()" class="inline-flex">
                            <Button type="button" variant="outline" class="w-full">Limpiar</Button>
                        </Link>
                    </form>
                </CardHeader>
            </Card>

            <Card class="rounded-3xl">
                <CardHeader>
                    <CardTitle>Listado de ajustes</CardTitle>
                    <CardDescription>{{ props.adjustments.total }} ajuste(s) en total.</CardDescription>
                </CardHeader>

                <CardContent class="grid gap-3">
                    <article
                        v-for="adjustment in props.adjustments.data"
                        :key="adjustment.id"
                        class="rounded-2xl border border-border/70 bg-muted/20 p-4"
                    >
                        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                            <div class="space-y-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-base font-semibold">{{ adjustment.product.title }}</h3>
                                    <Badge v-if="adjustment.product.code" variant="outline" class="rounded-full">
                                        {{ adjustment.product.code }}
                                    </Badge>
                                    <Badge v-if="adjustment.reason" variant="outline" class="rounded-full">
                                        {{ adjustment.reason }}
                                    </Badge>
                                </div>

                                <div class="flex flex-wrap items-center gap-x-3 gap-y-2 text-sm text-muted-foreground">
                                    <span class="inline-flex items-center gap-2">
                                        <WarehouseIcon class="size-4" />
                                        {{ adjustment.warehouse.name }}
                                    </span>
                                    <span class="inline-flex items-center gap-2">
                                        <CalendarDays class="size-4" />
                                        {{ adjustment.adjustment_at ? dateTime.format(new Date(adjustment.adjustment_at)) : 'Sin fecha' }}
                                    </span>
                                    <span v-if="adjustment.previous_quantity !== null">
                                        Previo: {{ quantityFormat.format(adjustment.previous_quantity) }}
                                    </span>
                                    <span>
                                        Objetivo: {{ quantityFormat.format(adjustment.target_quantity) }}
                                    </span>
                                </div>
                            </div>

                            <p
                                class="text-2xl font-semibold tabular-nums"
                                :class="adjustment.change_quantity < 0 ? 'text-rose-700' : 'text-emerald-700'"
                            >
                                {{ signedFormat.format(adjustment.change_quantity) }}
                            </p>
                        </div>
                    </article>

                    <div
                        v-if="props.adjustments.data.length === 0"
                        class="rounded-2xl border border-dashed border-border px-6 py-10 text-center text-sm text-muted-foreground"
                    >
                        No hay ajustes para los filtros aplicados.
                    </div>

                    <div
                        v-if="props.adjustments.links.length > 3"
                        class="flex flex-wrap items-center justify-between gap-3 border-t border-border/60 pt-4"
                    >
                        <p class="text-sm text-muted-foreground">
                            Mostrando {{ props.adjustments.from ?? 0 }} - {{ props.adjustments.to ?? 0 }} de {{ props.adjustments.total }}
                        </p>

                        <div class="flex flex-wrap gap-2">
                            <Link
                                v-for="link in props.adjustments.links"
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
