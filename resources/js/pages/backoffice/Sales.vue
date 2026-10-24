<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowRightLeft, CalendarDays, Download, Search, ShoppingCart, UserRound } from 'lucide-vue-next';
import { computed } from 'vue';
import SaleController from '@/actions/App/Http/Controllers/Backoffice/SaleController';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { index as backofficeBusinesses } from '@/routes/backoffice/businesses';
import { exportMethod as backofficeSalesExport, index as backofficeSales } from '@/routes/backoffice/sales';
import type { BreadcrumbItem } from '@/types';
import type { Flash } from '@/types/auth';

type Props = {
    currentBusiness: {
        id: number;
        name: string;
        slug: string;
        default_currency: string;
    };
    filters: {
        search: string;
        status: string;
        start_date: string | null;
        end_date: string | null;
    };
    stats: {
        count: number;
        completed_count: number;
        returned_count: number;
        total_amount: number;
    };
    sales: Array<{
        id: string;
        reference: string;
        status: string;
        date_time: string | null;
        total: number;
        customer_name: string;
        created_by: {
            role: string;
            name: string;
            device_name: string;
        } | null;
        lines: Array<{
            product_title: string;
            quantity: number;
            price: number;
            subtotal: number;
        }>;
        items_count: number;
    }>;
};

const props = defineProps<Props>();
const page = usePage<{ flash: Flash }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Backoffice', href: backofficeBusinesses() },
    { title: 'Ventas', href: backofficeSales() },
];

const money = new Intl.NumberFormat('es-CU', {
    style: 'currency',
    currency: props.currentBusiness.default_currency || 'CUP',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

const dateTime = new Intl.DateTimeFormat('es-ES', {
    dateStyle: 'medium',
    timeStyle: 'short',
});

const statusClasses: Record<string, string> = {
    completed: 'border-emerald-300 text-emerald-700',
    returned: 'border-amber-300 text-amber-700',
    canceled: 'border-rose-300 text-rose-700',
    pending: 'border-slate-300 text-slate-700',
};

const exportUrl = computed(() => {
    const query: Record<string, string> = {};
    if (props.filters.search) {
        query.search = props.filters.search;
    }
    if (props.filters.status) {
        query.status = props.filters.status;
    }
    if (props.filters.start_date) {
        query.start_date = props.filters.start_date;
    }
    if (props.filters.end_date) {
        query.end_date = props.filters.end_date;
    }

    return backofficeSalesExport({ query }).url;
});

const canExport = computed(() => props.sales.length > 0);
</script>

<template>
    <Head title="Ventas" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <section class="rounded-3xl border border-sidebar-border/70 bg-linear-to-r from-background via-background to-primary/5 p-6">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <Badge variant="secondary" class="mb-3 gap-2 rounded-full px-3 py-1">
                            <ShoppingCart class="size-3.5" />
                            Gestion de ventas
                        </Badge>
                        <Heading
                            :title="`Ventas de ${props.currentBusiness.name}`"
                            description="Consulta las ventas sincronizadas del negocio y marca devoluciones cuando corresponda."
                        />
                    </div>

                    <div class="grid gap-3 sm:grid-cols-3">
                        <Card class="rounded-2xl">
                            <CardHeader class="pb-2">
                                <CardDescription>Ventas visibles</CardDescription>
                                <CardTitle class="text-2xl">{{ props.stats.count }}</CardTitle>
                            </CardHeader>
                        </Card>
                        <Card class="rounded-2xl">
                            <CardHeader class="pb-2">
                                <CardDescription>Completadas</CardDescription>
                                <CardTitle class="text-2xl">{{ props.stats.completed_count }}</CardTitle>
                            </CardHeader>
                        </Card>
                        <Card class="rounded-2xl">
                            <CardHeader class="pb-2">
                                <CardDescription>Total</CardDescription>
                                <CardTitle class="text-2xl">{{ money.format(props.stats.total_amount) }}</CardTitle>
                            </CardHeader>
                        </Card>
                    </div>
                </div>
            </section>

            <div
                v-if="page.props.flash.success"
                class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700"
            >
                {{ page.props.flash.success }}
            </div>

            <div
                v-if="page.props.flash.error"
                class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
            >
                {{ page.props.flash.error }}
            </div>

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
                        <CardDescription>Busca por referencia, cliente, vendedor o productos.</CardDescription>
                    </div>

                    <form :action="backofficeSales().url" method="get" class="grid w-full gap-3 lg:max-w-4xl lg:grid-cols-[1.4fr_0.8fr_1fr_1fr_auto_auto]">
                        <div class="relative">
                            <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input name="search" :default-value="props.filters.search" placeholder="Buscar venta" class="pl-9" />
                        </div>
                        <select
                            name="status"
                            :default-value="props.filters.status"
                            class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none"
                        >
                            <option value="">Todos los estados</option>
                            <option value="completed">Completadas</option>
                            <option value="returned">Devueltas</option>
                            <option value="canceled">Canceladas</option>
                        </select>
                        <Input name="start_date" type="date" :default-value="props.filters.start_date ?? ''" />
                        <Input name="end_date" type="date" :default-value="props.filters.end_date ?? ''" />
                        <Button type="submit">Filtrar</Button>
                        <Link :href="backofficeSales()" class="inline-flex">
                            <Button type="button" variant="outline" class="w-full">Limpiar</Button>
                        </Link>
                    </form>
                </CardHeader>
            </Card>

            <Card class="rounded-3xl">
                <CardHeader>
                    <CardTitle>Listado de ventas</CardTitle>
                    <CardDescription>
                        {{ props.sales.length }} venta(s) encontradas.
                    </CardDescription>
                </CardHeader>

                <CardContent class="grid gap-4">
                    <article
                        v-for="sale in props.sales"
                        :key="sale.id"
                        class="rounded-2xl border border-border/70 bg-muted/20 p-4"
                    >
                        <div class="flex flex-col gap-4">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                <div class="space-y-2">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 class="text-base font-semibold">{{ sale.reference }}</h3>
                                        <Badge variant="outline" class="rounded-full capitalize" :class="statusClasses[sale.status] ?? statusClasses.pending">
                                            {{ sale.status }}
                                        </Badge>
                                    </div>

                                    <div class="flex flex-wrap gap-x-6 gap-y-2 text-sm text-muted-foreground">
                                        <span class="inline-flex items-center gap-2">
                                            <UserRound class="size-4" />
                                            {{ sale.customer_name }}
                                        </span>
                                        <span class="inline-flex items-center gap-2">
                                            <CalendarDays class="size-4" />
                                            {{ sale.date_time ? dateTime.format(new Date(sale.date_time)) : 'Sin fecha' }}
                                        </span>
                                        <span>{{ sale.items_count }} linea(s)</span>
                                        <span v-if="sale.created_by">Registrado por: {{ sale.created_by.name }}</span>
                                    </div>
                                </div>

                                <div class="flex flex-col items-start gap-3 lg:items-end">
                                    <p class="text-2xl font-semibold">{{ money.format(sale.total) }}</p>

                                    <Form
                                        v-if="sale.status === 'completed'"
                                        v-bind="SaleController.markReturned.form(sale.id)"
                                        class="inline-flex"
                                        v-slot="{ processing }"
                                    >
                                        <Button :disabled="processing" variant="outline">
                                            <ArrowRightLeft class="size-4" />
                                            Marcar devuelta
                                        </Button>
                                    </Form>
                                </div>
                            </div>

                            <div class="grid gap-2 rounded-2xl border border-border/60 bg-background/70 p-3">
                                <div
                                    v-for="line in sale.lines"
                                    :key="`${sale.id}-${line.product_title}-${line.quantity}`"
                                    class="flex flex-col justify-between gap-1 text-sm md:flex-row md:items-center"
                                >
                                    <span>{{ line.product_title }}</span>
                                    <span class="text-muted-foreground">
                                        {{ line.quantity }} x {{ money.format(line.price) }} = {{ money.format(line.subtotal) }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </article>

                    <div
                        v-if="props.sales.length === 0"
                        class="rounded-2xl border border-dashed border-border px-6 py-10 text-center text-sm text-muted-foreground"
                    >
                        No hay ventas sincronizadas para los filtros aplicados.
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
