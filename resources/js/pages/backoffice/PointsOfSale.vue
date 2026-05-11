<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { CalendarDays, Search, Store, Users, Warehouse } from 'lucide-vue-next';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { index as backofficeBusinesses } from '@/routes/backoffice/businesses';
import { index as backofficePointsOfSale, sessions as backofficePosSessions } from '@/routes/backoffice/points-of-sale';
import type { BreadcrumbItem } from '@/types';

type SessionActor = {
    role: string;
    name: string;
    device_name: string;
};

type OpenSession = {
    external_id: string;
    status: string;
    opened_at: string | null;
    closed_at: string | null;
    duration_minutes: number | null;
    opening_balance: number;
    closing_balance: number | null;
    opened_by: SessionActor | null;
    closed_by: SessionActor | null;
    sales_count: number;
    sales_total: number;
};

type PointOfSaleItem = {
    external_id: string;
    name: string;
    warehouse_external_id: string | null;
    warehouse_name: string | null;
    employees_count: number;
    sessions_total: number;
    sessions_open: number;
    sessions_closed: number;
    open_session: OpenSession | null;
    updated_at: string | null;
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
    };
    stats: {
        points_of_sale_count: number;
        open_sessions_count: number;
        closed_sessions_count: number;
    };
    points_of_sale: PointOfSaleItem[];
};

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Backoffice', href: backofficeBusinesses() },
    { title: 'Puntos de venta', href: backofficePointsOfSale() },
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
</script>

<template>
    <Head title="Puntos de venta" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <section class="rounded-3xl border border-sidebar-border/70 bg-linear-to-r from-background via-background to-primary/5 p-6">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <Badge variant="secondary" class="mb-3 gap-2 rounded-full px-3 py-1">
                            <Store class="size-3.5" />
                            Operaciones de caja
                        </Badge>
                        <Heading
                            :title="`Puntos de venta de ${props.currentBusiness.name}`"
                            description="Consulta los puntos de venta del negocio y revisa el estado de sus cajas."
                        />
                    </div>

                    <div class="grid gap-3 sm:grid-cols-3">
                        <Card class="rounded-2xl">
                            <CardHeader class="pb-2">
                                <CardDescription>Puntos de venta</CardDescription>
                                <CardTitle class="text-2xl">{{ props.stats.points_of_sale_count }}</CardTitle>
                            </CardHeader>
                        </Card>
                        <Card class="rounded-2xl">
                            <CardHeader class="pb-2">
                                <CardDescription>Cajas abiertas</CardDescription>
                                <CardTitle class="text-2xl text-emerald-600">{{ props.stats.open_sessions_count }}</CardTitle>
                            </CardHeader>
                        </Card>
                        <Card class="rounded-2xl">
                            <CardHeader class="pb-2">
                                <CardDescription>Cajas cerradas</CardDescription>
                                <CardTitle class="text-2xl">{{ props.stats.closed_sessions_count }}</CardTitle>
                            </CardHeader>
                        </Card>
                    </div>
                </div>
            </section>

            <Card class="rounded-3xl">
                <CardHeader class="gap-4 md:flex-row md:items-end md:justify-between">
                    <div>
                        <CardTitle>Filtros</CardTitle>
                        <CardDescription>Busca por nombre del punto de venta.</CardDescription>
                    </div>

                    <form
                        :action="backofficePointsOfSale().url"
                        method="get"
                        class="grid w-full gap-3 lg:max-w-xl lg:grid-cols-[1fr_auto_auto]"
                    >
                        <div class="relative">
                            <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input name="search" :default-value="props.filters.search" placeholder="Buscar punto de venta" class="pl-9" />
                        </div>
                        <Button type="submit">Filtrar</Button>
                        <Link :href="backofficePointsOfSale()" class="inline-flex">
                            <Button type="button" variant="outline" class="w-full">Limpiar</Button>
                        </Link>
                    </form>
                </CardHeader>
            </Card>

            <Card class="rounded-3xl">
                <CardHeader>
                    <CardTitle>Listado</CardTitle>
                    <CardDescription>
                        {{ props.points_of_sale.length }} punto(s) de venta.
                    </CardDescription>
                </CardHeader>

                <CardContent class="grid gap-4">
                    <article
                        v-for="pos in props.points_of_sale"
                        :key="pos.external_id"
                        class="rounded-2xl border border-border/70 bg-muted/20 p-4"
                    >
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                            <div class="space-y-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-base font-semibold">{{ pos.name }}</h3>
                                    <Badge
                                        v-if="pos.open_session"
                                        variant="outline"
                                        class="rounded-full border-emerald-300 text-emerald-700"
                                    >
                                        Caja abierta
                                    </Badge>
                                    <Badge
                                        v-else
                                        variant="outline"
                                        class="rounded-full border-slate-300 text-slate-600"
                                    >
                                        Sin caja abierta
                                    </Badge>
                                </div>

                                <div class="flex flex-wrap gap-x-6 gap-y-2 text-sm text-muted-foreground">
                                    <span v-if="pos.warehouse_name" class="inline-flex items-center gap-2">
                                        <Warehouse class="size-4" />
                                        {{ pos.warehouse_name }}
                                    </span>
                                    <span class="inline-flex items-center gap-2">
                                        <Users class="size-4" />
                                        {{ pos.employees_count }} empleado(s)
                                    </span>
                                    <span class="inline-flex items-center gap-2">
                                        <CalendarDays class="size-4" />
                                        Última actualización:
                                        {{ pos.updated_at ? dateTime.format(new Date(pos.updated_at)) : 'Sin datos' }}
                                    </span>
                                </div>

                                <div
                                    v-if="pos.open_session"
                                    class="rounded-2xl border border-emerald-200 bg-emerald-50/60 px-4 py-3 text-sm text-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-200"
                                >
                                    <p class="font-medium">
                                        Apertura: {{ pos.open_session.opened_at ? dateTime.format(new Date(pos.open_session.opened_at)) : '—' }}
                                    </p>
                                    <p class="text-xs">
                                        Saldo de apertura: {{ money.format(pos.open_session.opening_balance) }}
                                        <span v-if="pos.open_session.opened_by"> · Abierta por: {{ pos.open_session.opened_by.name }}</span>
                                    </p>
                                </div>
                            </div>

                            <div class="flex flex-col items-start gap-3 lg:items-end">
                                <div class="grid grid-cols-3 gap-3 text-center text-xs uppercase tracking-wide text-muted-foreground">
                                    <div>
                                        <p>Total</p>
                                        <p class="text-lg font-semibold text-foreground">{{ pos.sessions_total }}</p>
                                    </div>
                                    <div>
                                        <p>Abiertas</p>
                                        <p class="text-lg font-semibold text-emerald-600">{{ pos.sessions_open }}</p>
                                    </div>
                                    <div>
                                        <p>Cerradas</p>
                                        <p class="text-lg font-semibold text-foreground">{{ pos.sessions_closed }}</p>
                                    </div>
                                </div>

                                <Link :href="backofficePosSessions(pos.external_id)" class="inline-flex">
                                    <Button variant="outline">Ver sesiones</Button>
                                </Link>
                            </div>
                        </div>
                    </article>

                    <div
                        v-if="props.points_of_sale.length === 0"
                        class="rounded-2xl border border-dashed border-border px-6 py-10 text-center text-sm text-muted-foreground"
                    >
                        No hay puntos de venta sincronizados para los filtros aplicados.
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
