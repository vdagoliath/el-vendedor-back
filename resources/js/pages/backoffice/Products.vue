<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Boxes, RefreshCw, Search, TriangleAlert } from 'lucide-vue-next';
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
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { index as backofficeBusinesses } from '@/routes/backoffice/businesses';
import { index as backofficeProducts } from '@/routes/backoffice/products';
import { index as backofficeTeam } from '@/routes/backoffice/team';
import type { BreadcrumbItem } from '@/types';

type ProductItem = {
    id: number;
    external_id: string;
    code: string;
    title: string;
    description: string | null;
    type: string;
    regular_price: number;
    purchase_price: number;
    min_stock: number | null;
    stock_total: number;
    updated_at: string | null;
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
    };
    filters: {
        search: string;
    };
    stats: {
        total_products: number;
        low_stock_products: number;
        latest_sync_at: string | null;
    };
    products: {
        data: ProductItem[];
        links: PaginationLink[];
        total: number;
        from: number | null;
        to: number | null;
    };
};

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Backoffice',
        href: backofficeBusinesses(),
    },
    {
        title: 'Productos',
        href: backofficeProducts(),
    },
];

const money = new Intl.NumberFormat('es-CU', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

const dateTime = new Intl.DateTimeFormat('es-ES', {
    dateStyle: 'medium',
    timeStyle: 'short',
});
</script>

<template>
    <Head title="Productos" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <section
                class="relative overflow-hidden rounded-3xl border border-sidebar-border/70 bg-linear-to-br from-background via-background to-primary/5 p-6"
            >
                <div class="relative flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
                    <div class="max-w-2xl space-y-2">
                        <Badge variant="secondary" class="gap-2 rounded-full px-3 py-1">
                            <Boxes class="size-3.5" />
                            Catalogo sincronizado
                        </Badge>
                        <Heading
                            :title="`Productos de ${props.currentBusiness.name}`"
                            description="Visualiza el catalogo que ya llego al backend por sincronizacion offline-first."
                        />
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <Link :href="backofficeBusinesses()" class="inline-flex">
                            <Button variant="outline">Cambiar negocio</Button>
                        </Link>
                        <Link :href="backofficeTeam()" class="inline-flex">
                            <Button variant="outline">Ver equipo</Button>
                        </Link>
                    </div>
                </div>
            </section>

            <div class="grid gap-4 md:grid-cols-3">
                <Card class="rounded-3xl">
                    <CardHeader class="pb-3">
                        <CardDescription>Total sincronizados</CardDescription>
                        <CardTitle class="text-3xl">{{ props.stats.total_products }}</CardTitle>
                    </CardHeader>
                </Card>

                <Card class="rounded-3xl">
                    <CardHeader class="pb-3">
                        <CardDescription>Stock critico</CardDescription>
                        <CardTitle class="text-3xl">{{ props.stats.low_stock_products }}</CardTitle>
                    </CardHeader>
                </Card>

                <Card class="rounded-3xl">
                    <CardHeader class="pb-3">
                        <CardDescription>Ultima actividad</CardDescription>
                        <CardTitle class="text-sm font-medium">
                            {{ props.stats.latest_sync_at ? dateTime.format(new Date(props.stats.latest_sync_at)) : 'Sin actividad' }}
                        </CardTitle>
                    </CardHeader>
                </Card>
            </div>

            <Card class="rounded-3xl">
                <CardHeader class="gap-4 md:flex-row md:items-end md:justify-between">
                    <div>
                        <CardTitle>Listado de productos</CardTitle>
                        <CardDescription>
                            {{ props.products.total }} producto(s) en el negocio actual.
                        </CardDescription>
                    </div>

                    <form :action="backofficeProducts().url" method="get" class="flex w-full max-w-md gap-2">
                        <div class="relative flex-1">
                            <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                name="search"
                                :default-value="props.filters.search"
                                placeholder="Buscar por nombre o codigo"
                                class="pl-9"
                            />
                        </div>
                        <Button type="submit">Buscar</Button>
                    </form>
                </CardHeader>

                <CardContent class="grid gap-4">
                    <article
                        v-for="product in props.products.data"
                        :key="product.external_id"
                        class="rounded-2xl border border-border/70 bg-muted/20 p-4"
                    >
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                            <div class="space-y-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-base font-semibold">{{ product.title }}</h3>
                                    <Badge variant="secondary" class="rounded-full">
                                        {{ product.code }}
                                    </Badge>
                                    <Badge class="rounded-full">
                                        {{ product.type }}
                                    </Badge>
                                    <Badge
                                        v-if="product.min_stock !== null && product.stock_total <= product.min_stock"
                                        variant="outline"
                                        class="rounded-full border-amber-300 text-amber-700"
                                    >
                                        <TriangleAlert class="mr-1 size-3.5" />
                                        Stock critico
                                    </Badge>
                                </div>

                                <p v-if="product.description" class="max-w-2xl text-sm text-muted-foreground">
                                    {{ product.description }}
                                </p>

                                <div class="flex flex-wrap gap-x-6 gap-y-2 text-sm text-muted-foreground">
                                    <span>Venta: {{ money.format(product.regular_price) }}</span>
                                    <span>Compra: {{ money.format(product.purchase_price) }}</span>
                                    <span>Stock: {{ product.stock_total }}</span>
                                    <span v-if="product.min_stock !== null">Minimo: {{ product.min_stock }}</span>
                                </div>
                            </div>

                            <div class="text-sm text-muted-foreground">
                                <div class="flex items-center gap-2">
                                    <RefreshCw class="size-4" />
                                    {{ product.updated_at ? dateTime.format(new Date(product.updated_at)) : 'Sin fecha' }}
                                </div>
                            </div>
                        </div>
                    </article>

                    <div
                        v-if="props.products.data.length === 0"
                        class="rounded-2xl border border-dashed border-border px-6 py-10 text-center text-sm text-muted-foreground"
                    >
                        No hay productos sincronizados todavia para este negocio.
                    </div>

                    <div
                        v-if="props.products.links.length > 3"
                        class="flex flex-wrap items-center justify-between gap-3 border-t border-border/60 pt-4"
                    >
                        <p class="text-sm text-muted-foreground">
                            Mostrando {{ props.products.from ?? 0 }} - {{ props.products.to ?? 0 }} de {{ props.products.total }}
                        </p>

                        <div class="flex flex-wrap gap-2">
                            <Link
                                v-for="link in props.products.links"
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
