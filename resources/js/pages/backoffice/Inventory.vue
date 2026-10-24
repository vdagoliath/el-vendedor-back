<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Boxes, Download, Search, TriangleAlert } from 'lucide-vue-next';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { index as backofficeBusinesses } from '@/routes/backoffice/businesses';
import {
    exportMethod as backofficeInventoryExport,
    index as backofficeInventory,
} from '@/routes/backoffice/inventory';
import type { BreadcrumbItem } from '@/types';

type WarehouseOption = {
    external_id: string;
    name: string;
};

type InventoryRow = {
    product_external_id: string;
    product_title: string;
    product_code: string | null;
    min_stock: number | null;
    is_critical: boolean;
    by_warehouse: Record<string, number>;
    total: number;
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
        only_with_stock: boolean;
        only_critical: boolean;
    };
    stats: {
        product_count: number;
        total_quantity: number;
        critical_count: number;
    };
    warehouses: WarehouseOption[];
    inventory: {
        data: InventoryRow[];
        page: number;
        per_page: number;
        total: number;
        last_page: number;
    };
};

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Backoffice', href: backofficeBusinesses() },
    { title: 'Inventario', href: backofficeInventory() },
];

const quantityFormat = new Intl.NumberFormat('es-CU', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 4,
});

const exportUrl = computed(() => {
    const query: Record<string, string> = {};
    if (props.filters.search) {
        query.search = props.filters.search;
    }
    if (props.filters.warehouse) {
        query.warehouse = props.filters.warehouse;
    }
    if (props.filters.only_with_stock) {
        query.only_with_stock = '1';
    }
    if (props.filters.only_critical) {
        query.only_critical = '1';
    }

    return backofficeInventoryExport({ query }).url;
});

const canExport = computed(() => props.inventory.data.length > 0);

const paginationPages = computed<number[]>(() => {
    const pages: number[] = [];
    const total = props.inventory.last_page;
    const current = props.inventory.page;
    const windowSize = 2;

    for (let p = 1; p <= total; p++) {
        if (p === 1 || p === total || (p >= current - windowSize && p <= current + windowSize)) {
            pages.push(p);
        }
    }

    return pages;
});

function buildPageUrl(page: number): string {
    const query: Record<string, string> = {};
    if (props.filters.search) {
        query.search = props.filters.search;
    }
    if (props.filters.warehouse) {
        query.warehouse = props.filters.warehouse;
    }
    if (props.filters.only_with_stock) {
        query.only_with_stock = '1';
    }
    if (props.filters.only_critical) {
        query.only_critical = '1';
    }
    query.page = String(page);

    return backofficeInventory({ query }).url;
}
</script>

<template>
    <Head title="Inventario" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <section class="rounded-3xl border border-sidebar-border/70 bg-linear-to-r from-background via-background to-primary/5 p-6">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <Badge variant="secondary" class="mb-3 gap-2 rounded-full px-3 py-1">
                            <Boxes class="size-3.5" />
                            Inventario
                        </Badge>
                        <Heading
                            :title="`Inventario de ${props.currentBusiness.name}`"
                            description="Consulta el stock total y la distribución por almacén de cada producto."
                        />
                    </div>

                    <div class="grid gap-3 sm:grid-cols-3">
                        <Card class="rounded-2xl">
                            <CardHeader class="pb-2">
                                <CardDescription>Productos visibles</CardDescription>
                                <CardTitle class="text-2xl">{{ props.stats.product_count }}</CardTitle>
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
                                <CardDescription>Stock crítico</CardDescription>
                                <CardTitle class="text-2xl">{{ props.stats.critical_count }}</CardTitle>
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
                        <CardDescription>Filtra por producto, almacén, stock disponible o stock crítico.</CardDescription>
                    </div>

                    <form
                        :action="backofficeInventory().url"
                        method="get"
                        class="grid w-full gap-3 lg:max-w-5xl lg:grid-cols-[1.4fr_1fr_auto_auto_auto_auto]"
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
                        <label class="inline-flex items-center gap-2 text-sm text-muted-foreground">
                            <input type="checkbox" name="only_with_stock" value="1" :checked="props.filters.only_with_stock" />
                            Solo con stock
                        </label>
                        <label class="inline-flex items-center gap-2 text-sm text-muted-foreground">
                            <input type="checkbox" name="only_critical" value="1" :checked="props.filters.only_critical" />
                            Solo crítico
                        </label>
                        <Button type="submit">Filtrar</Button>
                        <Link :href="backofficeInventory()" class="inline-flex">
                            <Button type="button" variant="outline" class="w-full">Limpiar</Button>
                        </Link>
                    </form>
                </CardHeader>
            </Card>

            <Card class="rounded-3xl">
                <CardHeader>
                    <CardTitle>Inventario</CardTitle>
                    <CardDescription>{{ props.inventory.total }} producto(s) en total.</CardDescription>
                </CardHeader>

                <CardContent class="grid gap-4">
                    <div
                        v-if="props.inventory.data.length === 0"
                        class="rounded-2xl border border-dashed border-border px-6 py-10 text-center text-sm text-muted-foreground"
                    >
                        No hay productos para los filtros aplicados.
                    </div>

                    <div v-else class="overflow-x-auto rounded-2xl border border-border/70">
                        <table class="w-full min-w-max border-collapse text-sm">
                            <thead class="bg-muted/40 text-left">
                                <tr>
                                    <th class="sticky left-0 z-10 bg-muted/40 px-4 py-3 font-semibold">Producto</th>
                                    <th
                                        v-for="warehouse in props.warehouses"
                                        :key="`th-${warehouse.external_id}`"
                                        class="px-4 py-3 text-right font-semibold"
                                    >
                                        {{ warehouse.name }}
                                    </th>
                                    <th class="px-4 py-3 text-right font-semibold">Total</th>
                                    <th class="px-4 py-3 text-right font-semibold">Mínimo</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="row in props.inventory.data"
                                    :key="row.product_external_id"
                                    class="border-t border-border/70"
                                >
                                    <td class="sticky left-0 z-10 bg-background px-4 py-3">
                                        <div class="flex flex-col">
                                            <div class="flex items-center gap-2">
                                                <span class="font-medium">{{ row.product_title }}</span>
                                                <Badge
                                                    v-if="row.is_critical"
                                                    variant="outline"
                                                    class="gap-1 rounded-full border-rose-300 text-rose-700"
                                                >
                                                    <TriangleAlert class="size-3" />
                                                    Crítico
                                                </Badge>
                                            </div>
                                            <span v-if="row.product_code" class="text-xs text-muted-foreground">{{ row.product_code }}</span>
                                        </div>
                                    </td>
                                    <td
                                        v-for="warehouse in props.warehouses"
                                        :key="`${row.product_external_id}-${warehouse.external_id}`"
                                        class="px-4 py-3 text-right tabular-nums"
                                        :class="(row.by_warehouse[warehouse.external_id] ?? 0) === 0 ? 'text-muted-foreground/60' : ''"
                                    >
                                        {{ quantityFormat.format(row.by_warehouse[warehouse.external_id] ?? 0) }}
                                    </td>
                                    <td class="px-4 py-3 text-right font-semibold tabular-nums">
                                        {{ quantityFormat.format(row.total) }}
                                    </td>
                                    <td class="px-4 py-3 text-right tabular-nums text-muted-foreground">
                                        {{ row.min_stock !== null ? quantityFormat.format(row.min_stock) : '—' }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div
                        v-if="props.inventory.last_page > 1"
                        class="flex flex-wrap items-center justify-between gap-3 border-t border-border/60 pt-4"
                    >
                        <p class="text-sm text-muted-foreground">
                            Página {{ props.inventory.page }} de {{ props.inventory.last_page }} ({{ props.inventory.total }} productos)
                        </p>

                        <div class="flex flex-wrap gap-2">
                            <Link
                                v-for="page in paginationPages"
                                :key="page"
                                :href="buildPageUrl(page)"
                                :class="[
                                    'rounded-xl border px-3 py-2 text-sm',
                                    page === props.inventory.page
                                        ? 'border-primary bg-primary text-primary-foreground'
                                        : 'border-border bg-background',
                                ]"
                            >
                                {{ page }}
                            </Link>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
