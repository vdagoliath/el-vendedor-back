<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { Building2, CircleCheckBig, Plus, Store } from 'lucide-vue-next';
import BusinessController from '@/actions/App/Http/Controllers/Backoffice/BusinessController';
import CurrentBusinessController from '@/actions/App/Http/Controllers/Backoffice/CurrentBusinessController';
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
    is_active: boolean;
    current_user_role: string | null;
    membership_is_active: boolean | null;
    is_current: boolean;
};

type Props = {
    businesses: BusinessItem[];
};

defineProps<Props>();

const page = usePage<{ auth: Auth; flash: Flash }>();

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
                            description="Crea nuevos negocios, cambia el contexto actual y avanza al equipo del negocio activo."
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
                        >
                            <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
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
                                </div>

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
                                        :disabled="processing || business.is_current"
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
                            </div>
                        </article>
                    </CardContent>
                </Card>

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
            </div>
        </div>
    </AppLayout>
</template>
