<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { Coins, PencilLine, Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { index, store, update, destroy } from '@/routes/backoffice/marketplace/currencies';
import type { BreadcrumbItem } from '@/types';
import type { Flash } from '@/types/auth';

type CurrencyRow = {
    id: number;
    code: string;
    name: string;
    symbol: string;
    decimals: number;
    is_default: boolean;
    sort: number;
};

const props = defineProps<{ currencies: CurrencyRow[] }>();
const page = usePage<{ flash: Flash }>();
const editingId = ref<number | null>(null);

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Backoffice', href: '/backoffice/businesses' },
    { title: 'MíraloAkí · Monedas', href: index() },
];

const form = useForm({
    code: '',
    name: '',
    symbol: '',
    decimals: 2,
    is_default: false,
    sort: 0,
});

const submitLabel = computed(() => (editingId.value === null ? 'Añadir moneda' : 'Guardar cambios'));

function submit() {
    if (editingId.value === null) {
        form.post(store.url(), { onSuccess: () => resetForm() });
        return;
    }

    form.put(update.url(editingId.value), { onSuccess: () => resetForm() });
}

function edit(currency: CurrencyRow) {
    editingId.value = currency.id;
    form.code = currency.code;
    form.name = currency.name;
    form.symbol = currency.symbol;
    form.decimals = currency.decimals;
    form.is_default = currency.is_default;
    form.sort = currency.sort;
}

function resetForm() {
    editingId.value = null;
    form.reset();
    form.clearErrors();
}

function remove(currency: CurrencyRow) {
    if (!window.confirm(`¿Eliminar la moneda ${currency.code}?`)) {
        return;
    }
    form.delete(destroy.url(currency.id));
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="MíraloAkí · Monedas" />

        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <Heading
                title="Monedas de visualización"
                description="Monedas que el marketplace ofrece al consumidor. La predeterminada se usa al mostrar precios (la cotización final la decide el backend)."
            />

            <p v-if="page.props.flash?.success" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                {{ page.props.flash.success }}
            </p>
            <p v-if="page.props.flash?.error" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ page.props.flash.error }}
            </p>

            <div class="grid gap-6 lg:grid-cols-[1fr_360px]">
                <Card>
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2"><Coins class="size-4" /> Monedas configuradas</CardTitle>
                        <CardDescription>{{ props.currencies.length }} moneda(s)</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b text-left text-muted-foreground">
                                        <th class="py-2 pr-3">Código</th>
                                        <th class="py-2 pr-3">Nombre</th>
                                        <th class="py-2 pr-3">Símbolo</th>
                                        <th class="py-2 pr-3">Decimales</th>
                                        <th class="py-2 pr-3">Orden</th>
                                        <th class="py-2 pr-3"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="currency in props.currencies" :key="currency.id" class="border-b last:border-0">
                                        <td class="py-2 pr-3 font-semibold">
                                            {{ currency.code }}
                                            <Badge v-if="currency.is_default" variant="secondary" class="ml-2">predeterminada</Badge>
                                        </td>
                                        <td class="py-2 pr-3">{{ currency.name }}</td>
                                        <td class="py-2 pr-3">{{ currency.symbol }}</td>
                                        <td class="py-2 pr-3">{{ currency.decimals }}</td>
                                        <td class="py-2 pr-3">{{ currency.sort }}</td>
                                        <td class="py-2 pr-3">
                                            <div class="flex justify-end gap-2">
                                                <Button variant="outline" size="sm" @click="edit(currency)">
                                                    <PencilLine class="size-4" />
                                                </Button>
                                                <Button variant="ghost" size="sm" :disabled="currency.is_default" @click="remove(currency)">
                                                    <Trash2 class="size-4" />
                                                </Button>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2"><Plus class="size-4" /> {{ submitLabel }}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form class="grid gap-4" @submit.prevent="submit">
                            <div class="grid gap-2">
                                <Label for="code">Código ISO (3)</Label>
                                <Input id="code" v-model="form.code" maxlength="3" class="uppercase" />
                                <InputError :message="form.errors.code" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="name">Nombre</Label>
                                <Input id="name" v-model="form.name" />
                                <InputError :message="form.errors.name" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="symbol">Símbolo</Label>
                                <Input id="symbol" v-model="form.symbol" maxlength="16" />
                                <InputError :message="form.errors.symbol" />
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div class="grid gap-2">
                                    <Label for="decimals">Decimales</Label>
                                    <Input id="decimals" v-model.number="form.decimals" type="number" min="0" max="4" />
                                    <InputError :message="form.errors.decimals" />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="sort">Orden</Label>
                                    <Input id="sort" v-model.number="form.sort" type="number" min="0" />
                                    <InputError :message="form.errors.sort" />
                                </div>
                            </div>
                            <label class="flex items-center gap-2 text-sm">
                                <input v-model="form.is_default" type="checkbox" class="size-4 rounded border-input" />
                                Marcar como predeterminada
                            </label>
                            <div class="flex gap-2">
                                <Button type="submit" :disabled="form.processing">{{ submitLabel }}</Button>
                                <Button v-if="editingId !== null" type="button" variant="ghost" @click="resetForm">Cancelar</Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>
