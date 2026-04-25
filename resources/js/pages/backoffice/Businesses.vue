<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { CircleCheckBig, Copy, KeyRound, PencilLine, Plus, Power, PowerOff, Store } from 'lucide-vue-next';
import { ref } from 'vue';
import BusinessController from '@/actions/App/Http/Controllers/Backoffice/BusinessController';
import CurrentBusinessController from '@/actions/App/Http/Controllers/Backoffice/CurrentBusinessController';
import LicensePricingController from '@/actions/App/Http/Controllers/Backoffice/LicensePricingController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { index as backofficeBusinesses } from '@/routes/backoffice/businesses';
import { index as backofficeTeam } from '@/routes/backoffice/team';
import type { BreadcrumbItem } from '@/types';
import type { Auth, Flash } from '@/types/auth';

type BusinessItem = {
    id: number;
    name: string;
    slug: string;
    address: string | null;
    phone: string | null;
    default_currency: string;
    owner_emails: string[];
    is_active: boolean;
    current_user_role: string | null;
    membership_is_active: boolean | null;
    is_current: boolean;
};

type Props = {
    businesses: BusinessItem[];
    currentBusiness: BusinessItem | null;
    generatedSyncToken: {
        token: string | null;
        owner_email: string | null;
        warning: string | null;
    };
    licensePricing: {
        catalog: {
            operationWindowDays: number;
            conditions: {
                currency: string;
                activePosDefinition: string;
                activeOwnerDefinition: string;
            };
            rules: Array<{
                id: string;
                name: string;
                description: string | null;
                currency: string;
                monthlyPrice: number;
                minActivePos: number;
                maxActivePos: number | null;
                minActiveOwners: number | null;
                maxActiveOwners: number | null;
            }>;
        };
        quote: {
            currency: string;
            monthlyPrice: number;
            activePosCount: number;
            activeOwnerCount: number;
            operationWindowDays: number;
            ruleLabel: string | null;
            ruleDescription: string | null;
        } | null;
        config: {
            id: number | null;
            active_pos_operation_window_days: number;
        };
        rules: Array<{
            id: number;
            name: string;
            description: string | null;
            currency: string;
            monthly_price: number;
            min_active_pos: number;
            max_active_pos: number | null;
            min_active_owners: number | null;
            max_active_owners: number | null;
            sort_order: number;
            is_active: boolean;
        }>;
    };
};

defineProps<Props>();

const page = usePage<{ auth: Auth; flash: Flash }>();
const copiedToken = ref(false);
const editingBusinessId = ref<number | null>(null);

const toggleEdit = (businessId: number) => {
    editingBusinessId.value = editingBusinessId.value === businessId ? null : businessId;
};

const copySyncToken = async (token: string | null) => {
    if (!token || !navigator?.clipboard) {
        return;
    }

    await navigator.clipboard.writeText(token);
    copiedToken.value = true;

    window.setTimeout(() => {
        copiedToken.value = false;
    }, 2000);
};

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Backoffice',
        href: backofficeBusinesses(),
    },
    {
        title: 'Negocios',
        href: backofficeBusinesses(),
    },
];
</script>

<template>
    <Head title="Negocios" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <section
                class="relative overflow-hidden rounded-3xl border border-sidebar-border/70 bg-linear-to-br from-background via-background to-sidebar-accent/40 p-6"
            >
                <div class="absolute inset-y-0 right-0 hidden w-1/3 bg-radial-[circle_at_top_right] from-primary/12 via-transparent to-transparent md:block" />
                <div class="relative flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
                    <div class="max-w-2xl space-y-2">
                        <Badge variant="secondary" class="gap-2 rounded-full px-3 py-1">
                            <Store class="size-3.5" />
                            Backoffice
                        </Badge>
                        <Heading
                            title="Gestiona tus negocios"
                            description="Crea nuevos negocios, cambia el contexto actual y completa la ficha operativa del negocio activo."
                        />
                    </div>

                    <Link :href="backofficeTeam()" class="inline-flex">
                        <Button variant="outline">Ir al equipo actual</Button>
                    </Link>
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

            <div class="grid gap-6 xl:grid-cols-[1.3fr_0.9fr]">
                <Card class="rounded-3xl">
                    <CardHeader>
                        <CardTitle>Negocios disponibles</CardTitle>
                        <CardDescription>
                            Elige el negocio con el que vas a trabajar en el backoffice.
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="grid gap-4">
                        <article
                            v-for="business in businesses"
                            :key="business.id"
                            class="rounded-2xl border border-border/70 bg-muted/20 p-4"
                            :class="{ 'opacity-70': !business.is_active }"
                        >
                            <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                                <div class="space-y-2">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 class="text-base font-semibold">
                                            {{ business.name }}
                                        </h3>
                                        <Badge
                                            v-if="business.is_current"
                                            class="rounded-full"
                                        >
                                            Actual
                                        </Badge>
                                        <Badge
                                            v-if="!business.is_active"
                                            variant="outline"
                                            class="rounded-full border-red-300 text-red-700"
                                        >
                                            Inactivo
                                        </Badge>
                                        <Badge
                                            v-if="business.current_user_role"
                                            variant="secondary"
                                            class="rounded-full"
                                        >
                                            {{ business.current_user_role }}
                                        </Badge>
                                    </div>
                                    <p class="text-sm text-muted-foreground">
                                        {{ business.slug }}
                                    </p>
                                    <div class="grid gap-1 text-sm text-muted-foreground">
                                        <p>
                                            Moneda: {{ business.default_currency }}
                                        </p>
                                        <p v-if="business.phone">
                                            Teléfono: {{ business.phone }}
                                        </p>
                                        <p v-if="business.address">
                                            Dirección: {{ business.address }}
                                        </p>
                                        <p v-if="business.owner_emails.length">
                                            Dueños: {{ business.owner_emails.join(', ') }}
                                        </p>
                                    </div>
                                </div>

                                <div class="flex flex-col gap-2 md:items-end">
                                    <Form
                                        v-bind="CurrentBusinessController.update.form()"
                                        class="flex"
                                        v-slot="{ processing }"
                                    >
                                        <input
                                            type="hidden"
                                            name="business_id"
                                            :value="business.id"
                                        />
                                        <Button
                                            :disabled="processing || business.is_current || !business.is_active"
                                            :variant="business.is_current ? 'secondary' : 'default'"
                                            class="min-w-40"
                                        >
                                            <CircleCheckBig
                                                v-if="business.is_current"
                                                class="size-4"
                                            />
                                            {{ business.is_current ? 'Seleccionado' : 'Usar este negocio' }}
                                        </Button>
                                    </Form>

                                    <div class="flex flex-wrap gap-2 md:justify-end">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            class="min-w-32"
                                            @click="toggleEdit(business.id)"
                                        >
                                            <PencilLine class="size-4" />
                                            {{ editingBusinessId === business.id ? 'Cerrar edición' : 'Editar' }}
                                        </Button>

                                        <Form
                                            v-if="business.is_active"
                                            v-bind="BusinessController.destroy.form(business.id)"
                                            class="inline-flex"
                                            v-slot="{ processing: deactivating }"
                                        >
                                            <Button
                                                :disabled="deactivating"
                                                variant="outline"
                                                class="min-w-32 text-red-700"
                                            >
                                                <PowerOff class="size-4" />
                                                Desactivar
                                            </Button>
                                        </Form>
                                        <Form
                                            v-else
                                            v-bind="BusinessController.restore.form(business.id)"
                                            class="inline-flex"
                                            v-slot="{ processing: activating }"
                                        >
                                            <Button
                                                :disabled="activating"
                                                variant="outline"
                                                class="min-w-32 text-emerald-700"
                                            >
                                                <Power class="size-4" />
                                                Reactivar
                                            </Button>
                                        </Form>
                                    </div>
                                </div>
                            </div>

                            <Form
                                v-if="editingBusinessId === business.id"
                                v-bind="BusinessController.update.form(business.id)"
                                class="mt-5 grid gap-4 rounded-2xl border border-dashed border-border bg-background/60 p-4"
                                v-slot="{ errors, processing }"
                            >
                                <div class="grid gap-4 md:grid-cols-2">
                                    <div class="grid gap-2">
                                        <Label :for="`edit-name-${business.id}`">Nombre del negocio</Label>
                                        <Input
                                            :id="`edit-name-${business.id}`"
                                            name="name"
                                            required
                                            :default-value="business.name"
                                        />
                                        <InputError :message="errors.name" />
                                    </div>
                                    <div class="grid gap-2">
                                        <Label :for="`edit-slug-${business.id}`">Slug</Label>
                                        <Input
                                            :id="`edit-slug-${business.id}`"
                                            name="slug"
                                            :default-value="business.slug"
                                        />
                                        <InputError :message="errors.slug" />
                                    </div>
                                    <div class="grid gap-2">
                                        <Label :for="`edit-address-${business.id}`">Dirección</Label>
                                        <Input
                                            :id="`edit-address-${business.id}`"
                                            name="address"
                                            :default-value="business.address ?? ''"
                                        />
                                        <InputError :message="errors.address" />
                                    </div>
                                    <div class="grid gap-2">
                                        <Label :for="`edit-phone-${business.id}`">Teléfono</Label>
                                        <Input
                                            :id="`edit-phone-${business.id}`"
                                            name="phone"
                                            :default-value="business.phone ?? ''"
                                        />
                                        <InputError :message="errors.phone" />
                                    </div>
                                    <div class="grid gap-2">
                                        <Label :for="`edit-currency-${business.id}`">Moneda</Label>
                                        <Input
                                            :id="`edit-currency-${business.id}`"
                                            name="default_currency"
                                            required
                                            :default-value="business.default_currency"
                                        />
                                        <InputError :message="errors.default_currency" />
                                    </div>
                                </div>

                                <div class="flex justify-end">
                                    <Button :disabled="processing">
                                        Guardar cambios
                                    </Button>
                                </div>
                            </Form>
                        </article>
                    </CardContent>
                </Card>

                <div class="grid gap-6">
                    <Card class="rounded-3xl">
                        <CardHeader>
                            <CardTitle>Crear negocio</CardTitle>
                            <CardDescription>
                                Registra un nuevo negocio y lo dejaremos activo para ti de inmediato.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Form
                                v-bind="BusinessController.store.form()"
                                class="space-y-5"
                                v-slot="{ errors, processing }"
                            >
                                <div class="grid gap-2">
                                    <Label for="name">Nombre del negocio</Label>
                                    <Input
                                        id="name"
                                        name="name"
                                        required
                                        placeholder="Bodega Central"
                                    />
                                    <InputError :message="errors.name" />
                                </div>

                                <div class="grid gap-2">
                                    <Label for="slug">Slug opcional</Label>
                                    <Input
                                        id="slug"
                                        name="slug"
                                        placeholder="bodega-central"
                                    />
                                    <InputError :message="errors.slug" />
                                </div>

                                <div class="grid gap-2">
                                    <Label for="address">Dirección</Label>
                                    <Input
                                        id="address"
                                        name="address"
                                        placeholder="Calle 10 #123 entre A y B"
                                    />
                                    <InputError :message="errors.address" />
                                </div>

                                <div class="grid gap-2">
                                    <Label for="phone">Teléfono</Label>
                                    <Input
                                        id="phone"
                                        name="phone"
                                        placeholder="+53 55555555"
                                    />
                                    <InputError :message="errors.phone" />
                                </div>

                                <div class="grid gap-2">
                                    <Label for="default_currency">Moneda</Label>
                                    <Input
                                        id="default_currency"
                                        name="default_currency"
                                        required
                                        default-value="CUP"
                                        placeholder="CUP"
                                    />
                                    <InputError :message="errors.default_currency" />
                                </div>

                                <Button :disabled="processing" class="w-full">
                                    <Plus class="size-4" />
                                    Crear negocio
                                </Button>
                            </Form>
                        </CardContent>
                        <CardFooter class="text-sm text-muted-foreground">
                            Si no defines un slug, lo generamos automáticamente.
                        </CardFooter>
                    </Card>

                    <Card v-if="currentBusiness" class="rounded-3xl">
                        <CardHeader>
                            <CardTitle>Precio de licencia del negocio actual</CardTitle>
                            <CardDescription>
                                Este cálculo usa solo puntos de venta activos y dueños activos.
                            </CardDescription>
                        </CardHeader>
                        <CardContent v-if="licensePricing.quote" class="space-y-4">
                            <div class="rounded-2xl border border-border/70 bg-muted/20 p-4">
                                <p class="text-sm text-muted-foreground">Tarifa vigente</p>
                                <p class="mt-2 text-3xl font-semibold">
                                    {{ licensePricing.quote.monthlyPrice.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}
                                    {{ licensePricing.quote.currency }}/mes
                                </p>
                                <p class="mt-2 text-sm text-muted-foreground">
                                    {{ licensePricing.quote.ruleLabel ?? 'Sin regla aplicada' }}
                                </p>
                            </div>

                            <div class="grid gap-3 md:grid-cols-2">
                                <div class="rounded-2xl border border-border/70 p-4">
                                    <p class="text-sm text-muted-foreground">POS activos contados</p>
                                    <p class="mt-2 text-2xl font-semibold">{{ licensePricing.quote.activePosCount }}</p>
                                </div>
                                <div class="rounded-2xl border border-border/70 p-4">
                                    <p class="text-sm text-muted-foreground">Dueños activos contados</p>
                                    <p class="mt-2 text-2xl font-semibold">{{ licensePricing.quote.activeOwnerCount }}</p>
                                </div>
                            </div>

                            <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                                <p><strong>Ventana de operaciones regulares:</strong> {{ licensePricing.quote.operationWindowDays }} días.</p>
                                <p class="mt-2">{{ licensePricing.quote.ruleDescription ?? 'La tarifa se determina por el tramo configurado en el tarifario activo.' }}</p>
                            </div>
                        </CardContent>
                    </Card>

                    <Card v-if="page.props.auth.user?.is_platform_admin" class="rounded-3xl">
                        <CardHeader>
                            <CardTitle>Tarifario de licencias</CardTitle>
                            <CardDescription>
                                Configura las reglas globales de cobro y define cuántos días cuentan para considerar un POS con operaciones regulares.
                            </CardDescription>
                        </CardHeader>
                        <CardContent class="space-y-6">
                            <Form
                                v-bind="LicensePricingController.updateConfig.form()"
                                class="space-y-4 rounded-2xl border border-border/70 p-4"
                                v-slot="{ errors, processing }"
                            >
                                <div class="grid gap-2">
                                    <Label for="active_pos_operation_window_days">Ventana de operación regular (días)</Label>
                                    <Input
                                        id="active_pos_operation_window_days"
                                        name="active_pos_operation_window_days"
                                        type="number"
                                        min="1"
                                        :default-value="licensePricing.config.active_pos_operation_window_days"
                                    />
                                    <InputError :message="errors.active_pos_operation_window_days" />
                                </div>

                                <Button :disabled="processing" variant="outline">
                                    Guardar ventana de actividad
                                </Button>
                            </Form>

                            <div class="space-y-4">
                                <article
                                    v-for="rule in licensePricing.rules"
                                    :key="rule.id"
                                    class="rounded-2xl border border-border/70 p-4"
                                >
                                    <Form
                                        v-bind="LicensePricingController.update.form(rule.id)"
                                        class="space-y-4"
                                        v-slot="{ processing }"
                                    >
                                        <div class="grid gap-4 md:grid-cols-2">
                                            <div class="grid gap-2">
                                                <Label :for="`rule-name-${rule.id}`">Nombre</Label>
                                                <Input :id="`rule-name-${rule.id}`" name="name" :default-value="rule.name" />
                                            </div>
                                            <div class="grid gap-2">
                                                <Label :for="`rule-currency-${rule.id}`">Moneda</Label>
                                                <Input :id="`rule-currency-${rule.id}`" name="currency" :default-value="rule.currency" />
                                            </div>
                                            <div class="grid gap-2 md:col-span-2">
                                                <Label :for="`rule-description-${rule.id}`">Descripción</Label>
                                                <Input :id="`rule-description-${rule.id}`" name="description" :default-value="rule.description ?? ''" />
                                            </div>
                                            <div class="grid gap-2">
                                                <Label :for="`rule-price-${rule.id}`">Precio mensual</Label>
                                                <Input :id="`rule-price-${rule.id}`" name="monthly_price" type="number" step="0.01" min="0" :default-value="rule.monthly_price" />
                                            </div>
                                            <div class="grid gap-2">
                                                <Label :for="`rule-sort-${rule.id}`">Orden</Label>
                                                <Input :id="`rule-sort-${rule.id}`" name="sort_order" type="number" min="0" :default-value="rule.sort_order" />
                                            </div>
                                            <div class="grid gap-2">
                                                <Label :for="`rule-min-pos-${rule.id}`">POS activos mínimo</Label>
                                                <Input :id="`rule-min-pos-${rule.id}`" name="min_active_pos" type="number" min="0" :default-value="rule.min_active_pos" />
                                            </div>
                                            <div class="grid gap-2">
                                                <Label :for="`rule-max-pos-${rule.id}`">POS activos máximo</Label>
                                                <Input :id="`rule-max-pos-${rule.id}`" name="max_active_pos" type="number" min="0" :default-value="rule.max_active_pos ?? ''" />
                                            </div>
                                            <div class="grid gap-2">
                                                <Label :for="`rule-min-owners-${rule.id}`">Dueños activos mínimo</Label>
                                                <Input :id="`rule-min-owners-${rule.id}`" name="min_active_owners" type="number" min="0" :default-value="rule.min_active_owners ?? ''" />
                                            </div>
                                            <div class="grid gap-2">
                                                <Label :for="`rule-max-owners-${rule.id}`">Dueños activos máximo</Label>
                                                <Input :id="`rule-max-owners-${rule.id}`" name="max_active_owners" type="number" min="0" :default-value="rule.max_active_owners ?? ''" />
                                            </div>
                                        </div>

                                        <label class="flex items-center gap-2 text-sm">
                                            <input type="hidden" name="is_active" value="0" />
                                            <input type="checkbox" name="is_active" value="1" :checked="rule.is_active" />
                                            Regla activa
                                        </label>

                                        <div class="flex flex-col gap-3 md:flex-row">
                                            <Button :disabled="processing" variant="outline">Guardar regla</Button>
                                        </div>
                                    </Form>
                                    <Form
                                        v-bind="LicensePricingController.destroy.form(rule.id)"
                                        class="mt-3 flex"
                                        v-slot="{ processing: deleting }"
                                    >
                                        <Button :disabled="deleting" variant="destructive" type="submit">Eliminar</Button>
                                    </Form>
                                </article>
                            </div>

                            <Form
                                v-bind="LicensePricingController.store.form()"
                                class="space-y-4 rounded-2xl border border-dashed border-border p-4"
                                v-slot="{ processing }"
                            >
                                <h3 class="text-base font-semibold">Crear nueva regla</h3>
                                <div class="grid gap-4 md:grid-cols-2">
                                    <div class="grid gap-2">
                                        <Label for="new-rule-name">Nombre</Label>
                                        <Input id="new-rule-name" name="name" placeholder="Hasta 5 POS activos" />
                                    </div>
                                    <div class="grid gap-2">
                                        <Label for="new-rule-currency">Moneda</Label>
                                        <Input id="new-rule-currency" name="currency" value="CUP" />
                                    </div>
                                    <div class="grid gap-2 md:col-span-2">
                                        <Label for="new-rule-description">Descripción</Label>
                                        <Input id="new-rule-description" name="description" placeholder="Describe cuándo aplica esta regla." />
                                    </div>
                                    <div class="grid gap-2">
                                        <Label for="new-rule-price">Precio mensual</Label>
                                        <Input id="new-rule-price" name="monthly_price" type="number" min="0" step="0.01" />
                                    </div>
                                    <div class="grid gap-2">
                                        <Label for="new-rule-order">Orden</Label>
                                        <Input id="new-rule-order" name="sort_order" type="number" min="0" value="50" />
                                    </div>
                                    <div class="grid gap-2">
                                        <Label for="new-rule-min-pos">POS activos mínimo</Label>
                                        <Input id="new-rule-min-pos" name="min_active_pos" type="number" min="0" />
                                    </div>
                                    <div class="grid gap-2">
                                        <Label for="new-rule-max-pos">POS activos máximo</Label>
                                        <Input id="new-rule-max-pos" name="max_active_pos" type="number" min="0" />
                                    </div>
                                    <div class="grid gap-2">
                                        <Label for="new-rule-min-owners">Dueños activos mínimo</Label>
                                        <Input id="new-rule-min-owners" name="min_active_owners" type="number" min="0" />
                                    </div>
                                    <div class="grid gap-2">
                                        <Label for="new-rule-max-owners">Dueños activos máximo</Label>
                                        <Input id="new-rule-max-owners" name="max_active_owners" type="number" min="0" />
                                    </div>
                                </div>

                                <label class="flex items-center gap-2 text-sm">
                                    <input type="hidden" name="is_active" value="0" />
                                    <input type="checkbox" name="is_active" value="1" checked />
                                    Regla activa
                                </label>

                                <Button :disabled="processing" class="w-full">Crear regla</Button>
                            </Form>
                        </CardContent>
                    </Card>

                    <Card v-if="currentBusiness" class="rounded-3xl">
                        <CardHeader>
                            <CardTitle class="flex items-center gap-2">
                                <KeyRound class="size-4" />
                                Token de sincronización
                            </CardTitle>
                            <CardDescription>
                                Genera un token nuevo para el negocio actual y compártelo con el dueño que vaya a configurar la app. El valor solo se muestra una vez.
                            </CardDescription>
                        </CardHeader>
                        <CardContent class="space-y-5">
                            <div class="rounded-2xl border border-border/70 bg-muted/20 p-4 text-sm text-muted-foreground">
                                <p v-if="currentBusiness.owner_emails.length">
                                    Se emitirá para: {{ currentBusiness.owner_emails.join(', ') }}
                                </p>
                                <p v-else>
                                    Este negocio todavía no tiene dueños activos asociados en el backoffice.
                                </p>
                            </div>

                            <Form
                                :action="`/backoffice/businesses/${currentBusiness.id}/sync-token`"
                                method="post"
                                class="space-y-4"
                                v-slot="{ processing }"
                            >
                                <Button :disabled="processing || !currentBusiness.owner_emails.length" class="w-full">
                                    <KeyRound class="size-4" />
                                    {{ processing ? 'Generando token...' : 'Generar nuevo token' }}
                                </Button>
                            </Form>

                            <div
                                v-if="generatedSyncToken.token"
                                class="space-y-3 rounded-2xl border border-amber-200 bg-amber-50 p-4"
                            >
                                <p class="text-sm font-medium text-amber-900">
                                    Token visible solo una vez
                                </p>
                                <p class="text-sm text-amber-800">
                                    Dueño asociado: {{ generatedSyncToken.owner_email ?? 'No disponible' }}
                                </p>
                                <p v-if="generatedSyncToken.warning" class="text-sm text-amber-800">
                                    {{ generatedSyncToken.warning }}
                                </p>
                                <div class="flex flex-col gap-3 md:flex-row">
                                    <Input readonly :value="generatedSyncToken.token" class="font-mono text-xs" />
                                    <Button type="button" variant="outline" class="shrink-0" @click="copySyncToken(generatedSyncToken.token)">
                                        <Copy class="size-4" />
                                        {{ copiedToken ? 'Copiado' : 'Copiar token' }}
                                    </Button>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
