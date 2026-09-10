<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { Activity, RefreshCw } from 'lucide-vue-next';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import { index, refresh } from '@/routes/backoffice/marketplace/rates';
import type { BreadcrumbItem } from '@/types';
import type { Flash } from '@/types/auth';

type RatesPayload = {
    base: string;
    updated_at: string | null;
    rates: Record<string, number>;
    cup_source_updated_at?: string | null;
    error?: string | null;
};

const props = defineProps<{
    rates: RatesPayload;
    providers: { qvapay: string; open_er_api: string };
}>();

const page = usePage<{ flash: Flash }>();
const form = useForm({});

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Backoffice', href: '/backoffice/businesses' },
    { title: 'MíraloAkí · Tasas', href: index() },
];

const rows = computed(() => Object.entries(props.rates.rates ?? {}));
const updatedLabel = computed(() => (props.rates.updated_at ? new Date(props.rates.updated_at).toLocaleString('es-ES') : '—'));
const cupSourceLabel = computed(() =>
    props.rates.cup_source_updated_at ? new Date(props.rates.cup_source_updated_at).toLocaleString('es-ES') : '—',
);

function refreshRates() {
    form.post(refresh.url());
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="MíraloAkí · Tasas" />

        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <Heading
                title="Tasas de visualización"
                description="Tasas usadas solo para mostrar equivalencias aproximadas. El cobro y la cotización definitiva siempre ocurren en la moneda del pedido."
            />

            <p v-if="page.props.flash?.success" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                {{ page.props.flash.success }}
            </p>
            <p v-if="props.rates.error" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                {{ props.rates.error }}
            </p>

            <Card>
                <CardHeader class="flex flex-row items-center justify-between gap-4">
                    <div>
                        <CardTitle class="flex items-center gap-2"><Activity class="size-4" /> USD como base</CardTitle>
                        <CardDescription>
                            Generado: {{ updatedLabel }} · CUP (QvaPay): {{ cupSourceLabel }}
                        </CardDescription>
                    </div>
                    <Button :disabled="form.processing" @click="refreshRates">
                        <RefreshCw class="mr-2 size-4" /> Actualizar ahora
                    </Button>
                </CardHeader>
                <CardContent>
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b text-left text-muted-foreground">
                                <th class="py-2 pr-3">Moneda</th>
                                <th class="py-2 pr-3">Tasa por 1 USD</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="[code, rate] in rows" :key="code" class="border-b last:border-0">
                                <td class="py-2 pr-3 font-semibold">{{ code }}</td>
                                <td class="py-2 pr-3">{{ rate }}</td>
                            </tr>
                        </tbody>
                    </table>

                    <dl class="mt-6 grid gap-1 text-xs text-muted-foreground">
                        <div class="flex gap-2"><dt class="font-medium">QvaPay:</dt><dd class="truncate">{{ props.providers.qvapay }}</dd></div>
                        <div class="flex gap-2"><dt class="font-medium">open.er-api:</dt><dd class="truncate">{{ props.providers.open_er_api }}</dd></div>
                    </dl>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
