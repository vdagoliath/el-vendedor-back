<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    Activity,
    AlertTriangle,
    Boxes,
    Calendar,
    CalendarDays,
    CalendarRange,
    DollarSign,
    Medal,
    PackageSearch,
    ReceiptText,
    ShoppingCart,
    TrendingDown,
    TrendingUp,
    Wallet,
} from 'lucide-vue-next';
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
import { dashboard } from '@/routes';
import { index as backofficeBusinesses } from '@/routes/backoffice/businesses';
import { index as backofficeProducts } from '@/routes/backoffice/products';
import { index as backofficeTeam } from '@/routes/backoffice/team';
import type { BreadcrumbItem } from '@/types';

type Props = {
    currentBusiness: {
        id: number;
        name: string;
        slug: string;
        default_currency: string;
    };
    filters: {
        start_date: string | null;
        end_date: string | null;
    };
    overview: {
        sales: number;
        purchases: number;
        expenses: number;
        outgoing: number;
        balance: number;
        daily_average_expenses: number;
    };
    periodPerformance: {
        day: { sales: number; profit: number };
        week: { sales: number; profit: number };
        month: { sales: number; profit: number };
    };
    inventory: {
        low_stock_count: number;
        total_value: number;
    };
    topProduct: {
        title: string;
        qty: number;
    } | null;
    totalProfit: number;
    topExpenseCategories: Array<{
        name: string;
        total: number;
    }>;
    stats: {
        latest_activity_at: string | null;
        completed_sales_count: number;
        completed_purchases_count: number;
        expenses_count: number;
    };
};

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
    },
];

const currencyFormatter = new Intl.NumberFormat('es-CU', {
    style: 'currency',
    currency: props.currentBusiness.default_currency || 'CUP',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

const numberFormatter = new Intl.NumberFormat('es-CU', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
});

const dateTimeFormatter = new Intl.DateTimeFormat('es-ES', {
    dateStyle: 'medium',
    timeStyle: 'short',
});

const expenseCategoryTotal = props.topExpenseCategories.reduce((sum, item) => sum + item.total, 0);

const kpiCards = [
    {
        title: 'Ventas',
        value: props.overview.sales,
        description: 'Ventas completadas en el rango seleccionado.',
        icon: TrendingUp,
        accent: 'text-emerald-600',
        surface: 'from-emerald-500/10 via-emerald-500/5 to-transparent',
    },
    {
        title: 'Egresos',
        value: props.overview.outgoing,
        description: 'Compras completadas mas gastos operativos.',
        icon: TrendingDown,
        accent: 'text-rose-600',
        surface: 'from-rose-500/10 via-rose-500/5 to-transparent',
    },
    {
        title: 'Utilidad Neta Estimada',
        value: props.overview.balance,
        description: props.overview.balance >= 0 ? 'Ganancia estimada del periodo.' : 'Perdida estimada del periodo.',
        icon: Wallet,
        accent: props.overview.balance >= 0 ? 'text-sky-600' : 'text-rose-600',
        surface: props.overview.balance >= 0 ? 'from-sky-500/10 via-sky-500/5 to-transparent' : 'from-rose-500/10 via-rose-500/5 to-transparent',
    },
];

const periodCards = [
    {
        title: 'Ventas del Dia',
        value: props.periodPerformance.day.sales,
        icon: Calendar,
        accent: 'text-emerald-600',
    },
    {
        title: 'Ganancias del Dia',
        value: props.periodPerformance.day.profit,
        icon: DollarSign,
        accent: 'text-sky-600',
    },
    {
        title: 'Ventas de la Semana',
        value: props.periodPerformance.week.sales,
        icon: CalendarRange,
        accent: 'text-emerald-600',
    },
    {
        title: 'Ganancias de la Semana',
        value: props.periodPerformance.week.profit,
        icon: Activity,
        accent: 'text-sky-600',
    },
    {
        title: 'Ventas del Mes',
        value: props.periodPerformance.month.sales,
        icon: CalendarDays,
        accent: 'text-emerald-600',
    },
    {
        title: 'Ganancias del Mes',
        value: props.periodPerformance.month.profit,
        icon: Wallet,
        accent: 'text-sky-600',
    },
];
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <section class="overflow-hidden rounded-3xl border border-sidebar-border/70 bg-linear-to-br from-background via-background to-primary/5 p-6">
                <div class="flex flex-col gap-5 xl:flex-row xl:items-end xl:justify-between">
                    <div class="max-w-3xl space-y-3">
                        <Badge variant="secondary" class="gap-2 rounded-full px-3 py-1">
                            <Boxes class="size-3.5" />
                            Dashboard sincronizado
                        </Badge>
                        <Heading
                            :title="`Resumen de ${props.currentBusiness.name}`"
                            description="Replica en el backoffice las mismas metricas clave que hoy se usan en la app del negocio."
                        />
                        <div class="flex flex-wrap items-center gap-3 text-sm text-muted-foreground">
                            <span class="inline-flex items-center gap-2">
                                <Activity class="size-4" />
                                {{ props.stats.latest_activity_at ? dateTimeFormatter.format(new Date(props.stats.latest_activity_at)) : 'Sin actividad reciente' }}
                            </span>
                            <span class="inline-flex items-center gap-2">
                                <ShoppingCart class="size-4" />
                                {{ props.stats.completed_sales_count }} venta(s) completadas
                            </span>
                            <span class="inline-flex items-center gap-2">
                                <ReceiptText class="size-4" />
                                {{ props.stats.completed_purchases_count }} compra(s) completadas
                            </span>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <Link :href="backofficeProducts()" class="inline-flex">
                            <Button variant="outline">Ver productos</Button>
                        </Link>
                        <Link :href="backofficeTeam()" class="inline-flex">
                            <Button variant="outline">Ver equipo</Button>
                        </Link>
                        <Link :href="backofficeBusinesses()" class="inline-flex">
                            <Button variant="outline">Cambiar negocio</Button>
                        </Link>
                    </div>
                </div>
            </section>

            <Card class="rounded-3xl">
                <CardHeader class="gap-4 md:flex-row md:items-end md:justify-between">
                    <div>
                        <CardTitle>Rango de fechas</CardTitle>
                        <CardDescription>
                            Las ventas, compras, egresos, utilidad neta, gastos por categoria y promedio diario respetan este filtro.
                        </CardDescription>
                    </div>

                    <form :action="dashboard().url" method="get" class="grid w-full gap-3 md:max-w-3xl md:grid-cols-[1fr_1fr_auto_auto]">
                        <Input name="start_date" type="date" :default-value="props.filters.start_date ?? ''" />
                        <Input name="end_date" type="date" :default-value="props.filters.end_date ?? ''" />
                        <Button type="submit">Aplicar</Button>
                        <Link :href="dashboard()" class="inline-flex">
                            <Button type="button" variant="outline" class="w-full">Limpiar</Button>
                        </Link>
                    </form>
                </CardHeader>
            </Card>

            <div class="grid gap-4 xl:grid-cols-3">
                <Card
                    v-for="card in kpiCards"
                    :key="card.title"
                    class="rounded-3xl border-border/70 bg-linear-to-br"
                    :class="card.surface"
                >
                    <CardHeader class="space-y-4 pb-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <CardDescription>{{ card.title }}</CardDescription>
                                <CardTitle class="mt-2 text-3xl">
                                    {{ currencyFormatter.format(card.value) }}
                                </CardTitle>
                            </div>
                            <div class="rounded-2xl bg-background/80 p-3 shadow-xs">
                                <component :is="card.icon" class="size-5" :class="card.accent" />
                            </div>
                        </div>
                        <p class="text-sm text-muted-foreground">
                            {{ card.description }}
                        </p>
                    </CardHeader>
                </Card>
            </div>

            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <Card
                    v-for="card in periodCards"
                    :key="card.title"
                    class="rounded-3xl border-border/70"
                >
                    <CardHeader class="pb-3">
                        <div class="flex items-center justify-between gap-3">
                            <div class="space-y-1">
                                <CardDescription>{{ card.title }}</CardDescription>
                                <CardTitle class="text-2xl">
                                    {{ currencyFormatter.format(card.value) }}
                                </CardTitle>
                            </div>
                            <div class="rounded-2xl bg-muted/60 p-3">
                                <component :is="card.icon" class="size-5" :class="card.accent" />
                            </div>
                        </div>
                    </CardHeader>
                </Card>
            </div>

            <div class="grid gap-6 xl:grid-cols-[1.2fr_0.8fr]">
                <div class="grid gap-6">
                    <Card class="rounded-3xl overflow-hidden">
                        <CardHeader class="border-b border-border/60 bg-linear-to-r from-amber-500/10 via-transparent to-transparent">
                            <div class="flex items-center gap-3">
                                <div class="rounded-2xl bg-amber-500/10 p-3">
                                    <Medal class="size-5 text-amber-600" />
                                </div>
                                <div>
                                    <CardDescription>Producto Estrella</CardDescription>
                                    <CardTitle>{{ props.topProduct?.title ?? 'Sin registros' }}</CardTitle>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent class="pt-6">
                            <p class="text-sm text-muted-foreground">
                                {{ props.topProduct ? `${numberFormatter.format(props.topProduct.qty)} unidades vendidas` : 'Todavia no hay ventas completadas para calcular el ranking.' }}
                            </p>
                        </CardContent>
                    </Card>

                    <div class="grid gap-4 md:grid-cols-2">
                        <Card class="rounded-3xl">
                            <CardHeader class="pb-3">
                                <CardDescription>Utilidad Real</CardDescription>
                                <CardTitle class="text-3xl">
                                    {{ currencyFormatter.format(props.totalProfit) }}
                                </CardTitle>
                            </CardHeader>
                            <CardContent class="text-sm text-muted-foreground">
                                Calculada con ventas completadas y costo actual de compra de cada producto.
                            </CardContent>
                        </Card>

                        <Card class="rounded-3xl">
                            <CardHeader class="pb-3">
                                <CardDescription>Inversion en Stock</CardDescription>
                                <CardTitle class="text-3xl">
                                    {{ currencyFormatter.format(props.inventory.total_value) }}
                                </CardTitle>
                            </CardHeader>
                            <CardContent class="text-sm text-muted-foreground">
                                Valor total del inventario actual usando stock por almacen y precio de compra.
                            </CardContent>
                        </Card>
                    </div>

                    <Card class="rounded-3xl">
                        <CardHeader>
                            <CardTitle>Gastos por Categoria</CardTitle>
                            <CardDescription>
                                Distribucion de los gastos operativos dentro del rango activo.
                            </CardDescription>
                        </CardHeader>
                        <CardContent class="grid gap-4">
                            <article
                                v-for="category in props.topExpenseCategories"
                                :key="category.name"
                                class="grid gap-2 rounded-2xl border border-border/70 bg-muted/20 p-4"
                            >
                                <div class="flex items-center justify-between gap-4">
                                    <div>
                                        <h3 class="font-medium">{{ category.name }}</h3>
                                        <p class="text-sm text-muted-foreground">
                                            {{ expenseCategoryTotal > 0 ? `${Math.round((category.total / expenseCategoryTotal) * 100)}% del total de gastos` : 'Sin porcentaje disponible' }}
                                        </p>
                                    </div>
                                    <span class="text-sm font-semibold">
                                        {{ currencyFormatter.format(category.total) }}
                                    </span>
                                </div>
                                <div class="h-2 overflow-hidden rounded-full bg-muted">
                                    <div
                                        class="h-full rounded-full bg-rose-500"
                                        :style="{ width: `${expenseCategoryTotal > 0 ? (category.total / expenseCategoryTotal) * 100 : 0}%` }"
                                    />
                                </div>
                            </article>

                            <div
                                v-if="props.topExpenseCategories.length === 0"
                                class="rounded-2xl border border-dashed border-border px-6 py-10 text-center text-sm text-muted-foreground"
                            >
                                No hay gastos registrados para el rango seleccionado.
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <div class="grid gap-6">
                    <Card class="rounded-3xl">
                        <CardHeader>
                            <CardTitle>Resumen operativo</CardTitle>
                            <CardDescription>
                                Indicadores rapidos del negocio actual.
                            </CardDescription>
                        </CardHeader>
                        <CardContent class="grid gap-4">
                            <article class="rounded-2xl border border-border/70 bg-muted/20 p-4">
                                <div class="flex items-center gap-3">
                                    <div class="rounded-2xl bg-amber-500/10 p-3">
                                        <AlertTriangle class="size-5 text-amber-600" />
                                    </div>
                                    <div>
                                        <p class="text-sm text-muted-foreground">Stock critico</p>
                                        <p class="text-2xl font-semibold">{{ props.inventory.low_stock_count }}</p>
                                    </div>
                                </div>
                            </article>

                            <article class="rounded-2xl border border-border/70 bg-muted/20 p-4">
                                <div class="flex items-center gap-3">
                                    <div class="rounded-2xl bg-sky-500/10 p-3">
                                        <DollarSign class="size-5 text-sky-600" />
                                    </div>
                                    <div>
                                        <p class="text-sm text-muted-foreground">Gasto diario promedio</p>
                                        <p class="text-2xl font-semibold">{{ currencyFormatter.format(props.overview.daily_average_expenses) }}</p>
                                    </div>
                                </div>
                            </article>

                            <article class="rounded-2xl border border-border/70 bg-muted/20 p-4">
                                <div class="flex items-center gap-3">
                                    <div class="rounded-2xl bg-emerald-500/10 p-3">
                                        <ShoppingCart class="size-5 text-emerald-600" />
                                    </div>
                                    <div>
                                        <p class="text-sm text-muted-foreground">Compras completadas</p>
                                        <p class="text-2xl font-semibold">{{ props.stats.completed_purchases_count }}</p>
                                    </div>
                                </div>
                            </article>

                            <article class="rounded-2xl border border-border/70 bg-muted/20 p-4">
                                <div class="flex items-center gap-3">
                                    <div class="rounded-2xl bg-rose-500/10 p-3">
                                        <ReceiptText class="size-5 text-rose-600" />
                                    </div>
                                    <div>
                                        <p class="text-sm text-muted-foreground">Gastos registrados</p>
                                        <p class="text-2xl font-semibold">{{ props.stats.expenses_count }}</p>
                                    </div>
                                </div>
                            </article>
                        </CardContent>
                    </Card>

                    <Card
                        v-if="props.inventory.low_stock_count > 0"
                        class="rounded-3xl border-amber-200 bg-amber-50/70"
                    >
                        <CardHeader>
                            <div class="flex items-center gap-3">
                                <div class="rounded-2xl bg-amber-100 p-3">
                                    <PackageSearch class="size-5 text-amber-700" />
                                </div>
                                <div>
                                    <CardTitle>Atencion: Stock Critico</CardTitle>
                                    <CardDescription>
                                        Hay {{ props.inventory.low_stock_count }} producto(s) agotados o por debajo del minimo.
                                    </CardDescription>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent>
                            <Link :href="backofficeProducts()" class="inline-flex">
                                <Button variant="outline">Revisar inventario</Button>
                            </Link>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
