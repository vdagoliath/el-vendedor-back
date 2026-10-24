<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, CalendarDays, Clock, ShoppingCart, UserRound, Wallet } from 'lucide-vue-next';
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

type SessionItem = {
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

type Props = {
    currentBusiness: {
        id: number;
        name: string;
        slug: string;
        default_currency: string;
    };
    pointOfSale: {
        external_id: string;
        name: string;
        warehouse_external_id: string | null;
        warehouse_name: string | null;
    };
    filters: {
        status: string;
        start_date: string | null;
        end_date: string | null;
    };
    stats: {
        sessions_count: number;
        open_count: number;
        closed_count: number;
        sales_total: number;
    };
    sessions: SessionItem[];
};

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Backoffice', href: backofficeBusinesses() },
    { title: 'Puntos de venta', href: backofficePointsOfSale() },
    { title: props.pointOfSale.name, href: backofficePosSessions(props.pointOfSale.external_id) },
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
    open: 'border-emerald-300 text-emerald-700',
    closed: 'border-slate-300 text-slate-700',
};

function formatDuration(minutes: number | null): string {
    if (minutes === null) {
        return '—';
    }

    if (minutes < 60) {
        return `${minutes} min`;
    }

    const hours = Math.floor(minutes / 60);
    const remaining = minutes % 60;

    return remaining === 0 ? `${hours} h` : `${hours} h ${remaining} min`;
}

function difference(opening: number, closing: number | null): number | null {
    return closing === null ? null : Math.round((closing - opening) * 100) / 100;
}
</script>

<template>
    <Head :title="`Cajas · ${props.pointOfSale.name}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <section class="rounded-3xl border border-sidebar-border/70 bg-linear-to-r from-background via-background to-primary/5 p-6">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <Link :href="backofficePointsOfSale()" class="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
                            <ArrowLeft class="size-4" />
                            Volver a puntos de venta
                        </Link>

                        <Heading
                            :title="`Cajas de ${props.pointOfSale.name}`"
                            :description="props.pointOfSale.warehouse_name
                                ? `Almacén: ${props.pointOfSale.warehouse_name}`
                                : 'Histórico de aperturas y cierres de caja.'"
                        />
                    </div>

                    <div class="grid gap-3 sm:grid-cols-4">
                        <Card class="rounded-2xl">
                            <CardHeader class="pb-2">
                                <CardDescription>Total</CardDescription>
                                <CardTitle class="text-2xl">{{ props.stats.sessions_count }}</CardTitle>
                            </CardHeader>
                        </Card>
                        <Card class="rounded-2xl">
                            <CardHeader class="pb-2">
                                <CardDescription>Abiertas</CardDescription>
                                <CardTitle class="text-2xl text-emerald-600">{{ props.stats.open_count }}</CardTitle>
                            </CardHeader>
                        </Card>
                        <Card class="rounded-2xl">
                            <CardHeader class="pb-2">
                                <CardDescription>Cerradas</CardDescription>
                                <CardTitle class="text-2xl">{{ props.stats.closed_count }}</CardTitle>
                            </CardHeader>
                        </Card>
                        <Card class="rounded-2xl">
                            <CardHeader class="pb-2">
                                <CardDescription>Ventas</CardDescription>
                                <CardTitle class="text-2xl">{{ money.format(props.stats.sales_total) }}</CardTitle>
                            </CardHeader>
                        </Card>
                    </div>
                </div>
            </section>

            <Card class="rounded-3xl">
                <CardHeader class="gap-4 md:flex-row md:items-end md:justify-between">
                    <div>
                        <CardTitle>Filtros</CardTitle>
                        <CardDescription>Filtra por estado y rango de fecha de apertura.</CardDescription>
                    </div>

                    <form
                        :action="backofficePosSessions(props.pointOfSale.external_id).url"
                        method="get"
                        class="grid w-full gap-3 lg:max-w-3xl lg:grid-cols-[0.8fr_1fr_1fr_auto_auto]"
                    >
                        <select
                            name="status"
                            :default-value="props.filters.status"
                            class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none"
                        >
                            <option value="">Todos los estados</option>
                            <option value="open">Abiertas</option>
                            <option value="closed">Cerradas</option>
                        </select>
                        <Input name="start_date" type="date" :default-value="props.filters.start_date ?? ''" />
                        <Input name="end_date" type="date" :default-value="props.filters.end_date ?? ''" />
                        <Button type="submit">Filtrar</Button>
                        <Link :href="backofficePosSessions(props.pointOfSale.external_id)" class="inline-flex">
                            <Button type="button" variant="outline" class="w-full">Limpiar</Button>
                        </Link>
                    </form>
                </CardHeader>
            </Card>

            <Card class="rounded-3xl">
                <CardHeader>
                    <CardTitle>Sesiones</CardTitle>
                    <CardDescription>{{ props.sessions.length }} sesión(es) encontradas.</CardDescription>
                </CardHeader>

                <CardContent class="grid gap-4">
                    <article
                        v-for="session in props.sessions"
                        :key="session.external_id"
                        class="rounded-2xl border border-border/70 bg-muted/20 p-4"
                    >
                        <div class="flex flex-col gap-4">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                <div class="space-y-2">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 class="text-base font-semibold">
                                            {{ session.opened_at ? dateTime.format(new Date(session.opened_at)) : 'Sin apertura' }}
                                        </h3>
                                        <Badge
                                            variant="outline"
                                            class="rounded-full capitalize"
                                            :class="statusClasses[session.status] ?? statusClasses.closed"
                                        >
                                            {{ session.status === 'open' ? 'Abierta' : 'Cerrada' }}
                                        </Badge>
                                    </div>

                                    <div class="flex flex-wrap gap-x-6 gap-y-2 text-sm text-muted-foreground">
                                        <span class="inline-flex items-center gap-2">
                                            <Clock class="size-4" />
                                            Duración: {{ formatDuration(session.duration_minutes) }}
                                        </span>
                                        <span class="inline-flex items-center gap-2">
                                            <CalendarDays class="size-4" />
                                            Cierre: {{ session.closed_at ? dateTime.format(new Date(session.closed_at)) : '—' }}
                                        </span>
                                        <span class="inline-flex items-center gap-2">
                                            <ShoppingCart class="size-4" />
                                            {{ session.sales_count }} venta(s) · {{ money.format(session.sales_total) }}
                                        </span>
                                    </div>

                                    <div class="grid gap-2 text-sm md:grid-cols-2">
                                        <div v-if="session.opened_by" class="flex items-center gap-2 text-muted-foreground">
                                            <UserRound class="size-4" />
                                            <span>Apertura: <strong class="text-foreground">{{ session.opened_by.name }}</strong></span>
                                        </div>
                                        <div v-if="session.closed_by" class="flex items-center gap-2 text-muted-foreground">
                                            <UserRound class="size-4" />
                                            <span>Cierre: <strong class="text-foreground">{{ session.closed_by.name }}</strong></span>
                                        </div>
                                    </div>
                                </div>

                                <div class="grid gap-3 rounded-2xl border border-border/60 bg-background/70 p-3 text-sm">
                                    <div class="flex items-center justify-between gap-6">
                                        <span class="inline-flex items-center gap-2 text-muted-foreground">
                                            <Wallet class="size-4" />
                                            Apertura
                                        </span>
                                        <span class="font-semibold">{{ money.format(session.opening_balance) }}</span>
                                    </div>
                                    <div class="flex items-center justify-between gap-6">
                                        <span class="inline-flex items-center gap-2 text-muted-foreground">
                                            <Wallet class="size-4" />
                                            Cierre
                                        </span>
                                        <span class="font-semibold">
                                            {{ session.closing_balance !== null ? money.format(session.closing_balance) : '—' }}
                                        </span>
                                    </div>
                                    <div
                                        v-if="difference(session.opening_balance, session.closing_balance) !== null"
                                        class="flex items-center justify-between gap-6 border-t border-border/60 pt-2"
                                    >
                                        <span class="text-muted-foreground">Diferencia</span>
                                        <span
                                            class="font-semibold"
                                            :class="(difference(session.opening_balance, session.closing_balance) ?? 0) < 0 ? 'text-rose-600' : 'text-emerald-600'"
                                        >
                                            {{ money.format(difference(session.opening_balance, session.closing_balance) ?? 0) }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </article>

                    <div
                        v-if="props.sessions.length === 0"
                        class="rounded-2xl border border-dashed border-border px-6 py-10 text-center text-sm text-muted-foreground"
                    >
                        No hay sesiones de caja para los filtros aplicados.
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
