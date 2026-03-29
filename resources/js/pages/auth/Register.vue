<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import AuthBase from '@/layouts/AuthLayout.vue';
import { login } from '@/routes';
</script>

<template>
    <AuthBase
        title="Registro deshabilitado"
        description="El acceso al backoffice se gestiona internamente. Si necesitas entrar, solicita tus credenciales."
    >
        <Head title="Registro deshabilitado" />

        <div class="flex flex-col gap-4">
            <Button as-child class="w-full">
                <a :href="login().url">Ir a iniciar sesión</a>
            </Button>
        </div>
    </AuthBase>
</template>
