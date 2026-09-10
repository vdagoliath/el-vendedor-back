<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { LogOut, UserRound } from 'lucide-vue-next';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import { index, revoke } from '@/routes/backoffice/marketplace/consumers';
import type { BreadcrumbItem } from '@/types';
import type { Flash } from '@/types/auth';

type ConsumerRow = {
    id: number;
    name: string;
    email: string;
    email_verified_at: string | null;
    created_at: string | null;
    sessions: number;
    last_used_at: string | null;
};

const props = defineProps<{
    consumers: {
        data: ConsumerRow[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
}>();

const page = usePage<{ flash: Flash }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Backoffice', href: '/backoffice/businesses' },
    { title: 'MíraloAkí · Consumidores', href: index() },
];

const total = computed(() => props.consumers.data.length);

function formatDate(value: string | null): string {
    return value ? new Date(value).toLocaleString('es-ES') : '—';
}

function revokeSessions(consumer: ConsumerRow) {
    if (!window.confirm(`¿Revocar las sesiones de ${consumer.email}?`)) {
        return;
    }
    router.post(revoke.url(consumer.id));
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="MíraloAkí · Consumidores" />

        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <Heading
                title="Consumidores"
                description="Usuarios con una sesión de consumidor (token Sanctum con ability consumer). Revocar cierra sus sesiones activas."
            />

            <p v-if="page.props.flash?.success" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                {{ page.props.flash.success }}
            </p>

            <Card>
                <CardHeader>
                    <CardTitle class="flex items-center gap-2"><UserRound class="size-4" /> Consumidores registrados</CardTitle>
                    <CardDescription>{{ total }} en esta página</CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b text-left text-muted-foreground">
                                    <th class="py-2 pr-3">Nombre</th>
                                    <th class="py-2 pr-3">Correo</th>
                                    <th class="py-2 pr-3">Verificado</th>
                                    <th class="py-2 pr-3">Sesiones</th>
                                    <th class="py-2 pr-3">Último uso</th>
                                    <th class="py-2 pr-3">Registro</th>
                                    <th class="py-2 pr-3"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="consumer in props.consumers.data" :key="consumer.id" class="border-b last:border-0">
                                    <td class="py-2 pr-3 font-medium">{{ consumer.name }}</td>
                                    <td class="py-2 pr-3">{{ consumer.email }}</td>
                                    <td class="py-2 pr-3">
                                        <Badge :variant="consumer.email_verified_at ? 'secondary' : 'outline'">
                                            {{ consumer.email_verified_at ? 'sí' : 'no' }}
                                        </Badge>
                                    </td>
                                    <td class="py-2 pr-3">{{ consumer.sessions }}</td>
                                    <td class="py-2 pr-3">{{ formatDate(consumer.last_used_at) }}</td>
                                    <td class="py-2 pr-3">{{ formatDate(consumer.created_at) }}</td>
                                    <td class="py-2 pr-3 text-right">
                                        <Button variant="ghost" size="sm" @click="revokeSessions(consumer)">
                                            <LogOut class="mr-2 size-4" /> Revocar
                                        </Button>
                                    </td>
                                </tr>
                                <tr v-if="props.consumers.data.length === 0">
                                    <td colspan="7" class="py-6 text-center text-muted-foreground">Sin consumidores registrados.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
