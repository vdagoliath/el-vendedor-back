<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { KeyRound, ShieldCheck, UserCog, UserPlus } from 'lucide-vue-next';
import BackofficeUserController from '@/actions/App/Http/Controllers/Backoffice/BackofficeUserController';
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
import { index as backofficeAccess } from '@/routes/backoffice/access';
import type { BreadcrumbItem } from '@/types';
import type { Flash } from '@/types/auth';

type Props = {
    users: Array<{
        id: number;
        name: string;
        email: string;
        backoffice_role: string | null;
        role_label: string | null;
        created_at: string | null;
    }>;
    stats: {
        total: number;
        super_admins: number;
        implementers: number;
    };
};

defineProps<Props>();

const page = usePage<{
    flash: Flash;
    backofficeRoles: Array<{
        value: string;
        label: string;
    }>;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Backoffice',
        href: backofficeAccess(),
    },
    {
        title: 'Accesos',
        href: backofficeAccess(),
    },
];

const dateTimeFormatter = new Intl.DateTimeFormat('es-ES', {
    dateStyle: 'medium',
    timeStyle: 'short',
});
</script>

<template>
    <Head title="Accesos" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <section class="rounded-3xl border border-sidebar-border/70 bg-linear-to-r from-background via-background to-primary/5 p-6">
                <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                    <div class="max-w-2xl space-y-2">
                        <Badge variant="secondary" class="gap-2 rounded-full px-3 py-1">
                            <ShieldCheck class="size-3.5" />
                            Gobierno del backoffice
                        </Badge>
                        <Heading
                            title="Roles de acceso administrativo"
                            description="Solo existen dos roles exclusivos para el backoffice: administrador supremo e implementador."
                        />
                    </div>

                    <div class="grid gap-2 text-sm text-muted-foreground md:text-right">
                        <span>{{ stats.total }} usuario(s) con acceso</span>
                        <span>{{ stats.super_admins }} administrador(es) supremo(s)</span>
                        <span>{{ stats.implementers }} implementador(es)</span>
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

            <div class="grid gap-6 xl:grid-cols-[1.2fr_0.9fr]">
                <Card class="rounded-3xl">
                    <CardHeader>
                        <CardTitle>Usuarios de backoffice</CardTitle>
                        <CardDescription>
                            Ajusta el rol administrativo o retira el acceso cuando ya no sea necesario.
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="grid gap-4">
                        <article
                            v-for="user in users"
                            :key="user.id"
                            class="rounded-2xl border border-border/70 bg-muted/20 p-4"
                        >
                            <div class="grid gap-4 lg:grid-cols-[1.3fr_0.9fr] lg:items-center">
                                <div class="space-y-2">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 class="font-semibold">{{ user.name }}</h3>
                                        <Badge class="rounded-full">
                                            {{ user.role_label ?? 'Sin acceso' }}
                                        </Badge>
                                    </div>
                                    <p class="text-sm text-muted-foreground">
                                        {{ user.email }}
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        {{ user.created_at ? `Creado ${dateTimeFormatter.format(new Date(user.created_at))}` : 'Sin fecha de creación' }}
                                    </p>
                                </div>

                                <Form
                                    v-bind="BackofficeUserController.update.form(user.id)"
                                    class="grid gap-3"
                                    v-slot="{ errors, processing }"
                                >
                                    <div class="grid gap-2">
                                        <Label :for="`role-${user.id}`">Rol de backoffice</Label>
                                        <select
                                            :id="`role-${user.id}`"
                                            name="backoffice_role"
                                            :default-value="user.backoffice_role ?? ''"
                                            class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none"
                                        >
                                            <option value="">Sin acceso</option>
                                            <option
                                                v-for="role in page.props.backofficeRoles"
                                                :key="role.value"
                                                :value="role.value"
                                            >
                                                {{ role.label }}
                                            </option>
                                        </select>
                                        <InputError :message="errors.backoffice_role" />
                                    </div>

                                    <Button :disabled="processing" variant="outline">
                                        <UserCog class="size-4" />
                                        Guardar rol
                                    </Button>
                                </Form>
                            </div>
                        </article>

                        <div
                            v-if="users.length === 0"
                            class="rounded-2xl border border-dashed border-border px-6 py-10 text-center text-sm text-muted-foreground"
                        >
                            Todavía no hay usuarios con acceso al backoffice.
                        </div>
                    </CardContent>
                </Card>

                <Card class="rounded-3xl">
                    <CardHeader>
                        <CardTitle>Crear acceso de backoffice</CardTitle>
                        <CardDescription>
                            Puedes crear un usuario nuevo o reutilizar un correo existente para asignarle un rol administrativo.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Form
                            v-bind="BackofficeUserController.store.form()"
                            class="space-y-5"
                            v-slot="{ errors, processing }"
                        >
                            <div class="grid gap-2">
                                <Label for="name">Nombre</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    placeholder="Adriana Soto"
                                />
                                <InputError :message="errors.name" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="email">Correo</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    required
                                    placeholder="backoffice@empresa.com"
                                />
                                <InputError :message="errors.email" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="backoffice_role">Rol</Label>
                                <select
                                    id="backoffice_role"
                                    name="backoffice_role"
                                    required
                                    class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none"
                                >
                                    <option
                                        v-for="role in page.props.backofficeRoles"
                                        :key="role.value"
                                        :value="role.value"
                                    >
                                        {{ role.label }}
                                    </option>
                                </select>
                                <InputError :message="errors.backoffice_role" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="password">Contraseña</Label>
                                <Input
                                    id="password"
                                    type="password"
                                    name="password"
                                    placeholder="Obligatoria si el correo no existe"
                                />
                                <InputError :message="errors.password" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="password_confirmation">Confirmar contraseña</Label>
                                <Input
                                    id="password_confirmation"
                                    type="password"
                                    name="password_confirmation"
                                    placeholder="Repite la contraseña"
                                />
                            </div>

                            <Button :disabled="processing" class="w-full">
                                <UserPlus class="size-4" />
                                Guardar acceso
                            </Button>
                        </Form>
                    </CardContent>
                    <CardFooter class="text-sm text-muted-foreground">
                        <KeyRound class="mr-2 size-4" />
                        Si el correo ya existe, actualizaremos su rol de backoffice sin duplicar la cuenta.
                    </CardFooter>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>
