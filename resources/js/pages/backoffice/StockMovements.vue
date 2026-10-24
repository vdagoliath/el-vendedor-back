<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, CalendarDays, Download, Search, Warehouse as WarehouseIcon } from 'lucide-vue-next';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { index as backofficeBusinesses } from '@/routes/backoffice/businesses';
import {
    exportMethod as backofficeStockMovementsExport,
    index as backofficeStockMovements,
} from '@/routes/backoffice/stock-movements';
import type { BreadcrumbItem } from '@/types';

type WarehouseOption = {
    external_id: string;
    name: string;
};

type MovementItem = {
    id: number;
    external_id: string;
    movement_at: string | null;
    quantity: number;
    product: {
        external_id: string;
        title: string;
        code: string | null;
    };
    from_warehouse: {
        external_id: string;
        name: string;
    };
    to_warehouse: {
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
        from: string;
        to: string;
        start_date: string | null;
        end_date: string | null;
    };
    stats: {
        count: number;
        total_quantity: number;
    };
    movements: {
        data: MovementItem[];
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
    { title: 'Movimientos de almacén', href: backofficeStockMovements() },
];

const quantityFormat = new Intl.NumberFormat('es-CU', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 4,
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
    if (props.filters.from) {
        query.from = props.filters.from;
    }
    if (props.filters.to) {
        query.to = props.filters.to;
    }
    if (props.filters.start_date) {
        query.start_date = props.filters.start_date;
    }
    if (props.filters.end_date) {
        query.end_date = props.filters.end_date;
    }

    return backofficeStockMovementsExport({ query }).url;
});

const canExport = computed(() => props.movements.data.length > 0);
</script>

<template>
    <Head title="Movimientos de almacén" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <section class="rounded-3xl border border-sidebar-border/70 bg-linear-to-r from-background via-background to-primary/5 p-6">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <Badge variant="secondary" class="mb-3 gap-2 rounded-full px-3 py-1">
                            <WarehouseIcon class="size-3.5" />
                            Inventario
                        </Badge>
                        <Heading
                            :title="`Movimientos entre almacenes de ${props.currentBusiness.name}`"
                            description="Consulta los traspasos de inventario entre almacenes del negocio."
                        />
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <Card class="rounded-2xl">
                            <CardHeader class="pb-2">
                                <CardDescription>Movimientos visibles</CardDescription>
                                <CardTitle class="text-2xl">{{ props.stats.count }}</CardTitle>
                            </CardHeader>
                        </Card>
                        <Card class="rounded-2xl">
                            <CardHeader class="pb-2">
                                <CardDescription>Cantidad total</CardDescription>
                                <CardTitle class="text-2xl">{{ quantityFormat.format(props.stats.total_quantity) }}</CardTitle>
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
                        <CardDescription>Filtra por producto, almacén de origen, almacén de destino o rango de fechas.</CardDescription>
                    </div>

                    <form
                        :action="backofficeStockMovements().url"
                        method="get"
                        class="grid w-full gap-3 lg:max-w-5xl lg:grid-cols-[1.4fr_1fr_1fr_0.9fr_0.9fr_auto_auto]"
                    >
                        <div class="relative">
                            <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input name="search" :default-value="props.filters.search" placeholder="Buscar producto" class="pl-9" />
                        </div>
                        <select
                            name="from"
                            :default-value="props.filters.from"
                            class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none"
                        >
                            <option value="">Cualquier origen</option>
                            <option
                                v-for="warehouse in props.warehouses"
                                :key="`from-${warehouse.external_id}`"
                                :value="warehouse.external_id"
                            >
                                {{ warehouse.name }}
                            </option>
                        </select>
                        <select
                            name="to"
                            :default-value="props.filters.to"
                            class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none"
                        >
                            <option value="">Cualquier destino</option>
                            <option
                                v-for="warehouse in props.warehouses"
                                :key="`to-${warehouse.external_id}`"
                                :value="warehouse.external_id"
                            >
                                {{ warehouse.name }}
                            </option>
                        </select>
                        <Input name="start_date" type="date" :default-value="props.filters.start_date ?? ''" />
                        <Input name="end_date" type="date" :default-value="props.filters.end_date ?? ''" />
                        <Button type="submit">Filtrar</Button>
                        <Link :href="backofficeStockMovements()" class="inline-flex">
                            <Button type="button" variant="outline" class="w-full">Limpiar</Button>
                        </Link>
                    </form>
                </CardHeader>
            </Card>

            <Card class="rounded-3xl">
                <CardHeader>
                    <CardTitle>Listado de movimientos</CardTitle>
                    <CardDescription>{{ props.movements.total }} movimiento(s) en total.</CardDescription>
                </CardHeader>

                <CardContent class="grid gap-3">
                    <article
                        v-for="movement in props.movements.data"
                        :key="movement.id"
                        class="rounded-2xl border border-border/70 bg-muted/20 p-4"
                    >
                        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                            <div class="space-y-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-base font-semibold">{{ movement.product.title }}</h3>
                                    <Badge v-if="movement.product.code" variant="outline" class="rounded-full">
                                        {{ movement.product.code }}
                                    </Badge>
                                </div>

                                <div class="flex flex-wrap items-center gap-x-3 gap-y-2 text-sm text-muted-foreground">
                                    <span class="inline-flex items-center gap-2">
                                        <WarehouseIcon class="size-4" />
                                        {{ movement.from_warehouse.name }}
                                    </span>
                                    <ArrowRight class="size-4 text-muted-foreground" />
                                    <span class="inline-flex items-center gap-2">
                                        <WarehouseIcon class="size-4" />
                                        {{ movement.to_warehouse.name }}
                                    </span>
                                    <span class="inline-flex items-center gap-2">
                                        <CalendarDays class="size-4" />
                                        {{ movement.movement_at ? dateTime.format(new Date(movement.movement_at)) : 'Sin fecha' }}
                                    </span>
                                </div>
                            </div>

                            <p class="text-2xl font-semibold">{{ quantityFormat.format(movement.quantity) }}</p>
                        </div>
                    </article>

                    <div
                        v-if="props.movements.data.length === 0"
                        class="rounded-2xl border border-dashed border-border px-6 py-10 text-center text-sm text-muted-foreground"
                    >
                        No hay movimientos para los filtros aplicados.
                    </div>

                    <div
                        v-if="props.movements.links.length > 3"
                        class="flex flex-wrap items-center justify-between gap-3 border-t border-border/60 pt-4"
                    >
                        <p class="text-sm text-muted-foreground">
                            Mostrando {{ props.movements.from ?? 0 }} - {{ props.movements.to ?? 0 }} de {{ props.movements.total }}
                        </p>

                        <div class="flex flex-wrap gap-2">
                            <Link
                                v-for="link in props.movements.links"
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
