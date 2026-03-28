<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { Ban, CalendarDays, CheckCircle2, Search, ShoppingBasket, Trash2, UserRound } from 'lucide-vue-next';
import PurchaseController from '@/actions/App/Http/Controllers/Backoffice/PurchaseController';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { index as backofficeBusinesses } from '@/routes/backoffice/businesses';
import { index as backofficePurchases } from '@/routes/backoffice/purchases';
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
        pending_count: number;
        completed_count: number;
        total_amount: number;
    };
    purchases: Array<{
        id: string;
        reference: string;
        status: string;
        date_time: string | null;
        total: number;
        supplier_name: string;
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
    { title: 'Compras', href: backofficePurchases() },
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
    pending: 'border-amber-300 text-amber-700',
    canceled: 'border-rose-300 text-rose-700',
    returned: 'border-sky-300 text-sky-700',
};
</script>

<template>
    <Head title="Compras" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <section class="rounded-3xl border border-sidebar-border/70 bg-linear-to-r from-background via-background to-primary/5 p-6">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <Badge variant="secondary" class="mb-3 gap-2 rounded-full px-3 py-1">
                            <ShoppingBasket class="size-3.5" />
                            Gestion de compras
                        </Badge>
                        <Heading
                            :title="`Compras de ${props.currentBusiness.name}`"
                            description="Revisa compras sincronizadas, completa compras pendientes y cancela compras ya cerradas."
                        />
                    </div>

                    <div class="grid gap-3 sm:grid-cols-3">
                        <Card class="rounded-2xl">
                            <CardHeader class="pb-2">
                                <CardDescription>Compras visibles</CardDescription>
                                <CardTitle class="text-2xl">{{ props.stats.count }}</CardTitle>
                            </CardHeader>
                        </Card>
                        <Card class="rounded-2xl">
                            <CardHeader class="pb-2">
                                <CardDescription>Pendientes</CardDescription>
                                <CardTitle class="text-2xl">{{ props.stats.pending_count }}</CardTitle>
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

            <Card class="rounded-3xl">
                <CardHeader class="gap-4 md:flex-row md:items-end md:justify-between">
                    <div>
                        <CardTitle>Filtros</CardTitle>
                        <CardDescription>Busca por referencia, proveedor, comprador o productos.</CardDescription>
                    </div>

                    <form :action="backofficePurchases().url" method="get" class="grid w-full gap-3 lg:max-w-4xl lg:grid-cols-[1.4fr_0.8fr_1fr_1fr_auto_auto]">
                        <div class="relative">
                            <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input name="search" :default-value="props.filters.search" placeholder="Buscar compra" class="pl-9" />
                        </div>
                        <select
                            name="status"
                            :default-value="props.filters.status"
                            class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none"
                        >
                            <option value="">Todos los estados</option>
                            <option value="pending">Pendientes</option>
                            <option value="completed">Completadas</option>
                            <option value="canceled">Canceladas</option>
                        </select>
                        <Input name="start_date" type="date" :default-value="props.filters.start_date ?? ''" />
                        <Input name="end_date" type="date" :default-value="props.filters.end_date ?? ''" />
                        <Button type="submit">Filtrar</Button>
                        <Link :href="backofficePurchases()" class="inline-flex">
                            <Button type="button" variant="outline" class="w-full">Limpiar</Button>
                        </Link>
                    </form>
                </CardHeader>
            </Card>

            <Card class="rounded-3xl">
                <CardHeader>
                    <CardTitle>Listado de compras</CardTitle>
                    <CardDescription>{{ props.purchases.length }} compra(s) encontradas.</CardDescription>
                </CardHeader>

                <CardContent class="grid gap-4">
                    <article
                        v-for="purchase in props.purchases"
                        :key="purchase.id"
                        class="rounded-2xl border border-border/70 bg-muted/20 p-4"
                    >
                        <div class="flex flex-col gap-4">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                <div class="space-y-2">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 class="text-base font-semibold">{{ purchase.reference }}</h3>
                                        <Badge variant="outline" class="rounded-full capitalize" :class="statusClasses[purchase.status] ?? statusClasses.pending">
                                            {{ purchase.status }}
                                        </Badge>
                                    </div>

                                    <div class="flex flex-wrap gap-x-6 gap-y-2 text-sm text-muted-foreground">
                                        <span class="inline-flex items-center gap-2">
                                            <UserRound class="size-4" />
                                            {{ purchase.supplier_name }}
                                        </span>
                                        <span class="inline-flex items-center gap-2">
                                            <CalendarDays class="size-4" />
                                            {{ purchase.date_time ? dateTime.format(new Date(purchase.date_time)) : 'Sin fecha' }}
                                        </span>
                                        <span>{{ purchase.items_count }} linea(s)</span>
                                        <span v-if="purchase.created_by">Registrado por: {{ purchase.created_by.name }}</span>
                                    </div>
                                </div>

                                <div class="flex flex-col items-start gap-2 lg:items-end">
                                    <p class="text-2xl font-semibold">{{ money.format(purchase.total) }}</p>

                                    <div class="flex flex-wrap gap-2">
                                        <Form
                                            v-if="purchase.status === 'pending'"
                                            v-bind="PurchaseController.complete.form(purchase.id)"
                                            class="inline-flex"
                                            v-slot="{ processing }"
                                        >
                                            <Button :disabled="processing" variant="outline">
                                                <CheckCircle2 class="size-4" />
                                                Completar
                                            </Button>
                                        </Form>

                                        <Form
                                            v-if="purchase.status === 'completed'"
                                            v-bind="PurchaseController.cancel.form(purchase.id)"
                                            class="inline-flex"
                                            v-slot="{ processing }"
                                        >
                                            <Button :disabled="processing" variant="outline">
                                                <Ban class="size-4" />
                                                Cancelar
                                            </Button>
                                        </Form>

                                        <Form
                                            v-if="purchase.status === 'pending'"
                                            v-bind="PurchaseController.destroy.form(purchase.id)"
                                            class="inline-flex"
                                            v-slot="{ processing }"
                                        >
                                            <Button :disabled="processing" variant="outline" class="text-rose-700">
                                                <Trash2 class="size-4" />
                                                Eliminar
                                            </Button>
                                        </Form>
                                    </div>
                                </div>
                            </div>

                            <div class="grid gap-2 rounded-2xl border border-border/60 bg-background/70 p-3">
                                <div
                                    v-for="line in purchase.lines"
                                    :key="`${purchase.id}-${line.product_title}-${line.quantity}`"
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
                        v-if="props.purchases.length === 0"
                        class="rounded-2xl border border-dashed border-border px-6 py-10 text-center text-sm text-muted-foreground"
                    >
                        No hay compras sincronizadas para los filtros aplicados.
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
