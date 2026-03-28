<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { ShieldCheck, UserPlus, Users } from 'lucide-vue-next';
import CurrentBusinessEmployeeController from '@/actions/App/Http/Controllers/Backoffice/CurrentBusinessEmployeeController';
import CurrentBusinessMemberController from '@/actions/App/Http/Controllers/Backoffice/CurrentBusinessMemberController';
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
import type { Flash } from '@/types/auth';

type Props = {
    currentBusiness: {
        id: number;
        name: string;
        slug: string;
    };
    members: Array<{
        id: number;
        name: string;
        email: string;
        role: string | null;
        membership_is_active: boolean;
        backoffice_role: string | null;
        backoffice_role_label: string | null;
    }>;
    employees: Array<{
        id: string;
        name: string;
        mobile: string;
        updated_at: string | null;
    }>;
};

defineProps<Props>();

const page = usePage<{ flash: Flash }>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Backoffice',
        href: backofficeBusinesses(),
    },
    {
        title: 'Equipo',
        href: backofficeTeam(),
    },
];
</script>

<template>
    <Head title="Equipo" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <section
                class="rounded-3xl border border-sidebar-border/70 bg-linear-to-r from-background via-background to-primary/5 p-6"
            >
                <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                    <div>
                        <Badge variant="secondary" class="mb-3 gap-2 rounded-full px-3 py-1">
                            <Users class="size-3.5" />
                            Equipo del negocio actual
                        </Badge>
                        <Heading
                            :title="currentBusiness.name"
                            :description="`Administra el acceso de las personas que trabajan en ${currentBusiness.slug}.`"
                        />
                    </div>

                    <Link :href="backofficeBusinesses()" class="inline-flex">
                        <Button variant="outline">Cambiar negocio</Button>
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

            <div class="grid gap-6 xl:grid-cols-[1.2fr_0.9fr]">
                <Card class="rounded-3xl">
                    <CardHeader>
                        <CardTitle>Miembros actuales</CardTitle>
                        <CardDescription>
                            Dueños y empleados con acceso al backoffice o al punto de venta.
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="grid gap-4">
                        <article
                            v-for="member in members"
                            :key="member.id"
                            class="rounded-2xl border border-border/70 bg-muted/20 p-4"
                        >
                            <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                                <div class="space-y-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 class="font-semibold">{{ member.name }}</h3>
                                        <Badge class="rounded-full">
                                            {{ member.role }}
                                        </Badge>
                                        <Badge
                                            v-if="member.backoffice_role_label"
                                            variant="secondary"
                                            class="rounded-full"
                                        >
                                            <ShieldCheck class="mr-1 size-3.5" />
                                            {{ member.backoffice_role_label }}
                                        </Badge>
                                    </div>
                                    <p class="text-sm text-muted-foreground">
                                        {{ member.email }}
                                    </p>
                                </div>

                                <Badge
                                    :variant="member.membership_is_active ? 'secondary' : 'outline'"
                                    class="rounded-full"
                                >
                                    {{ member.membership_is_active ? 'Activo' : 'Inactivo' }}
                                </Badge>
                            </div>
                        </article>
                    </CardContent>
                </Card>

                <Card class="rounded-3xl">
                    <CardHeader>
                        <CardTitle>Agregar miembro</CardTitle>
                        <CardDescription>
                            Crea una cuenta nueva para un empleado o un dueño adicional del negocio.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Form
                            v-bind="CurrentBusinessMemberController.store.form()"
                            class="space-y-5"
                            v-slot="{ errors, processing }"
                        >
                            <div class="grid gap-2">
                                <Label for="name">Nombre</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    required
                                    placeholder="Mariela Perez"
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
                                    placeholder="empleada@negocio.com"
                                />
                                <InputError :message="errors.email" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="role">Rol</Label>
                                <select
                                    id="role"
                                    name="role"
                                    class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none"
                                >
                                    <option value="employee">Empleado</option>
                                    <option value="owner">Dueño</option>
                                </select>
                                <InputError :message="errors.role" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="password">Contraseña</Label>
                                <Input
                                    id="password"
                                    type="password"
                                    name="password"
                                    required
                                    placeholder="password"
                                />
                                <InputError :message="errors.password" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="password_confirmation">Confirmar contraseña</Label>
                                <Input
                                    id="password_confirmation"
                                    type="password"
                                    name="password_confirmation"
                                    required
                                    placeholder="password"
                                />
                            </div>

                            <Button :disabled="processing" class="w-full">
                                <UserPlus class="size-4" />
                                Agregar miembro
                            </Button>
                        </Form>
                    </CardContent>
                    <CardFooter class="text-sm text-muted-foreground">
                        Los empleados podrán vender; los dueños podrán gestionar el negocio.
                    </CardFooter>
                </Card>
            </div>

            <div class="grid gap-6 xl:grid-cols-[1.2fr_0.9fr]">
                <Card class="rounded-3xl">
                    <CardHeader>
                        <CardTitle>Empleados sincronizados</CardTitle>
                        <CardDescription>
                            Estos empleados son los que usan los vendedores en sus dispositivos y se sincronizan con el negocio.
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="grid gap-4">
                        <article
                            v-for="employee in employees"
                            :key="employee.id"
                            class="rounded-2xl border border-border/70 bg-muted/20 p-4"
                        >
                            <div class="grid gap-4">
                                <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                                    <p class="text-sm text-muted-foreground">
                                        {{ employee.updated_at ? `Actualizado ${new Date(employee.updated_at).toLocaleString('es-ES')}` : 'Sin fecha de actualizacion' }}
                                    </p>

                                    <Form
                                        v-bind="CurrentBusinessEmployeeController.destroy.form(employee.id)"
                                        class="inline-flex"
                                        v-slot="{ processing: deleting }"
                                    >
                                        <Button :disabled="deleting" variant="outline" class="text-red-700">
                                            Eliminar
                                        </Button>
                                    </Form>
                                </div>

                                <Form
                                    v-bind="CurrentBusinessEmployeeController.update.form(employee.id)"
                                    class="grid gap-4"
                                    v-slot="{ errors, processing }"
                                >
                                    <div class="grid gap-4 md:grid-cols-2">
                                        <div class="grid gap-2">
                                            <Label :for="`employee-name-${employee.id}`">Nombre</Label>
                                            <Input
                                                :id="`employee-name-${employee.id}`"
                                                name="name"
                                                :default-value="employee.name"
                                                required
                                            />
                                        </div>

                                        <div class="grid gap-2">
                                            <Label :for="`employee-mobile-${employee.id}`">Movil</Label>
                                            <Input
                                                :id="`employee-mobile-${employee.id}`"
                                                name="mobile"
                                                :default-value="employee.mobile"
                                                required
                                            />
                                        </div>
                                    </div>

                                    <div class="flex justify-end">
                                        <Button :disabled="processing">
                                            Guardar
                                        </Button>
                                    </div>

                                    <InputError :message="errors.name || errors.mobile" />
                                </Form>
                            </div>
                        </article>

                        <div
                            v-if="employees.length === 0"
                            class="rounded-2xl border border-dashed border-border px-6 py-10 text-center text-sm text-muted-foreground"
                        >
                            Todavia no hay empleados sincronizados para este negocio.
                        </div>
                    </CardContent>
                </Card>

                <Card class="rounded-3xl">
                    <CardHeader>
                        <CardTitle>Agregar empleado sincronizado</CardTitle>
                        <CardDescription>
                            Crea empleados que luego podran seleccionarse en los dispositivos vendedores y en los puntos de venta.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Form
                            v-bind="CurrentBusinessEmployeeController.store.form()"
                            class="space-y-5"
                            v-slot="{ errors, processing }"
                        >
                            <div class="grid gap-2">
                                <Label for="employee_name">Nombre</Label>
                                <Input
                                    id="employee_name"
                                    name="name"
                                    required
                                    placeholder="Juan Perez"
                                />
                                <InputError :message="errors.name" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="employee_mobile">Movil</Label>
                                <Input
                                    id="employee_mobile"
                                    name="mobile"
                                    required
                                    placeholder="5351234567"
                                />
                                <InputError :message="errors.mobile" />
                            </div>

                            <Button :disabled="processing" class="w-full">
                                <UserPlus class="size-4" />
                                Agregar empleado
                            </Button>
                        </Form>
                    </CardContent>
                    <CardFooter class="text-sm text-muted-foreground">
                        La gestion de empleados aqui impacta directamente lo que ven los vendedores al sincronizar.
                    </CardFooter>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>
