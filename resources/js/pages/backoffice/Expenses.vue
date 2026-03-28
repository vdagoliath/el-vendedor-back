<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { CalendarDays, PencilLine, ReceiptText, Search, Trash2 } from 'lucide-vue-next';
import ExpenseController from '@/actions/App/Http/Controllers/Backoffice/ExpenseController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { index as backofficeBusinesses } from '@/routes/backoffice/businesses';
import { index as backofficeExpenses } from '@/routes/backoffice/expenses';
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
        start_date: string | null;
        end_date: string | null;
    };
    stats: {
        count: number;
        total_amount: number;
    };
    expenses: Array<{
        id: string;
        date: string | null;
        description: string;
        category: string;
        amount: number;
    }>;
};

const props = defineProps<Props>();
const page = usePage<{ flash: Flash }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Backoffice', href: backofficeBusinesses() },
    { title: 'Gastos', href: backofficeExpenses() },
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
    <Head title="Gastos" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <section class="rounded-3xl border border-sidebar-border/70 bg-linear-to-r from-background via-background to-primary/5 p-6">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <Badge variant="secondary" class="mb-3 gap-2 rounded-full px-3 py-1">
                            <ReceiptText class="size-3.5" />
                            Gestion de gastos
                        </Badge>
                        <Heading
                            :title="`Gastos de ${props.currentBusiness.name}`"
                            description="Crea, edita y elimina gastos operativos desde el backoffice con sincronizacion hacia los dispositivos."
                        />
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <Card class="rounded-2xl">
                            <CardHeader class="pb-2">
                                <CardDescription>Gastos visibles</CardDescription>
                                <CardTitle class="text-2xl">{{ props.stats.count }}</CardTitle>
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

            <div class="grid gap-6 xl:grid-cols-[1.1fr_1.4fr]">
                <Card class="rounded-3xl">
                    <CardHeader>
                        <CardTitle>Registrar gasto</CardTitle>
                        <CardDescription>Crea un gasto nuevo en el negocio actual.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Form
                            v-bind="ExpenseController.store.form()"
                            class="space-y-4"
                            v-slot="{ errors, processing }"
                        >
                            <div class="grid gap-2">
                                <Label for="expense_date">Fecha</Label>
                                <Input id="expense_date" name="date" type="datetime-local" required />
                                <InputError :message="errors.date" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="expense_category">Categoria</Label>
                                <Input id="expense_category" name="category" placeholder="Servicios" required />
                                <InputError :message="errors.category" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="expense_description">Descripcion</Label>
                                <Input id="expense_description" name="description" placeholder="Pago de electricidad" required />
                                <InputError :message="errors.description" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="expense_amount">Monto</Label>
                                <Input id="expense_amount" name="amount" type="number" min="0" step="0.01" required />
                                <InputError :message="errors.amount" />
                            </div>

                            <Button :disabled="processing" class="w-full">
                                Crear gasto
                            </Button>
                        </Form>
                    </CardContent>
                </Card>

                <Card class="rounded-3xl">
                    <CardHeader class="gap-4">
                        <div>
                            <CardTitle>Listado de gastos</CardTitle>
                            <CardDescription>Filtra y actualiza los gastos ya sincronizados.</CardDescription>
                        </div>

                        <form :action="backofficeExpenses().url" method="get" class="grid gap-3 lg:grid-cols-[1.2fr_1fr_1fr_auto_auto]">
                            <div class="relative">
                                <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                                <Input name="search" :default-value="props.filters.search" placeholder="Buscar gasto" class="pl-9" />
                            </div>
                            <Input name="start_date" type="date" :default-value="props.filters.start_date ?? ''" />
                            <Input name="end_date" type="date" :default-value="props.filters.end_date ?? ''" />
                            <Button type="submit">Filtrar</Button>
                            <Link :href="backofficeExpenses()" class="inline-flex">
                                <Button type="button" variant="outline" class="w-full">Limpiar</Button>
                            </Link>
                        </form>
                    </CardHeader>

                    <CardContent class="grid gap-4">
                        <article
                            v-for="expense in props.expenses"
                            :key="expense.id"
                            class="rounded-2xl border border-border/70 bg-muted/20 p-4"
                        >
                            <div class="grid gap-4">
                                <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                    <div class="space-y-2">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <Badge variant="secondary" class="rounded-full">
                                                {{ expense.category }}
                                            </Badge>
                                            <span class="text-sm text-muted-foreground inline-flex items-center gap-2">
                                                <CalendarDays class="size-4" />
                                                {{ expense.date ? dateTime.format(new Date(expense.date)) : 'Sin fecha' }}
                                            </span>
                                        </div>
                                        <p class="text-xl font-semibold">{{ money.format(expense.amount) }}</p>
                                    </div>

                                    <Form
                                        v-bind="ExpenseController.destroy.form(expense.id)"
                                        class="inline-flex"
                                        v-slot="{ processing: deleting }"
                                    >
                                        <Button :disabled="deleting" variant="outline" class="text-rose-700">
                                            <Trash2 class="size-4" />
                                            Eliminar
                                        </Button>
                                    </Form>
                                </div>

                                <Form
                                    v-bind="ExpenseController.update.form(expense.id)"
                                    class="grid gap-4"
                                    v-slot="{ errors, processing }"
                                >
                                    <div class="grid gap-3 md:grid-cols-2">
                                        <div class="grid gap-2">
                                            <Label :for="`expense-date-${expense.id}`">Fecha</Label>
                                            <Input
                                                :id="`expense-date-${expense.id}`"
                                                name="date"
                                                type="datetime-local"
                                                :default-value="expense.date ? expense.date.slice(0, 16) : ''"
                                                required
                                            />
                                        </div>
                                        <div class="grid gap-2">
                                            <Label :for="`expense-category-${expense.id}`">Categoria</Label>
                                            <Input
                                                :id="`expense-category-${expense.id}`"
                                                name="category"
                                                :default-value="expense.category"
                                                required
                                            />
                                        </div>
                                        <div class="grid gap-2 md:col-span-2">
                                            <Label :for="`expense-description-${expense.id}`">Descripcion</Label>
                                            <Input
                                                :id="`expense-description-${expense.id}`"
                                                name="description"
                                                :default-value="expense.description"
                                                required
                                            />
                                        </div>
                                        <div class="grid gap-2">
                                            <Label :for="`expense-amount-${expense.id}`">Monto</Label>
                                            <Input
                                                :id="`expense-amount-${expense.id}`"
                                                name="amount"
                                                type="number"
                                                min="0"
                                                step="0.01"
                                                :default-value="expense.amount"
                                                required
                                            />
                                        </div>
                                    </div>

                                    <InputError :message="errors.date || errors.category || errors.description || errors.amount" />

                                    <div class="flex justify-end">
                                        <Button :disabled="processing">
                                            <PencilLine class="size-4" />
                                            Guardar cambios
                                        </Button>
                                    </div>
                                </Form>
                            </div>
                        </article>

                        <div
                            v-if="props.expenses.length === 0"
                            class="rounded-2xl border border-dashed border-border px-6 py-10 text-center text-sm text-muted-foreground"
                        >
                            No hay gastos sincronizados para los filtros aplicados.
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>
