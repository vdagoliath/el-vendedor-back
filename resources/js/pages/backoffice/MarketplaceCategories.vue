<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { FolderTree, PencilLine, Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { index, store, update, destroy } from '@/routes/backoffice/marketplace/categories';
import type { BreadcrumbItem } from '@/types';
import type { Flash } from '@/types/auth';

type CategoryRow = {
    id: number;
    slug: string;
    name: string;
    image_url: string | null;
    color: string | null;
    sort: number;
    is_active: boolean;
};

const props = defineProps<{ categories: CategoryRow[] }>();
const page = usePage<{ flash: Flash }>();
const editingId = ref<number | null>(null);
const imageName = ref('');

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Backoffice', href: '/backoffice/businesses' },
    { title: 'MíraloAkí · Categorías', href: index() },
];

const form = useForm({
    slug: '',
    name: '',
    color: '',
    sort: 0,
    is_active: true,
    remove_image: false,
    image: null as File | null,
});

const submitLabel = computed(() => (editingId.value === null ? 'Añadir categoría' : 'Guardar cambios'));

function onFile(event: Event) {
    const target = event.target as HTMLInputElement;
    form.image = target.files?.[0] ?? null;
    imageName.value = form.image?.name ?? '';
}

function submit() {
    const editing = editingId.value;
    form.transform((data) => (editing === null ? data : { ...data, _method: 'put' })).post(
        editing === null ? store.url() : update.url(editing),
        { forceFormData: true, onSuccess: () => resetForm() },
    );
}

function edit(category: CategoryRow) {
    editingId.value = category.id;
    form.slug = category.slug;
    form.name = category.name;
    form.color = category.color ?? '';
    form.sort = category.sort;
    form.is_active = category.is_active;
    form.remove_image = false;
    form.image = null;
    imageName.value = '';
}

function resetForm() {
    editingId.value = null;
    imageName.value = '';
    form.reset();
    form.clearErrors();
}

function remove(category: CategoryRow) {
    if (!window.confirm(`¿Eliminar la categoría ${category.name}?`)) {
        return;
    }
    form.delete(destroy.url(category.id));
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="MíraloAkí · Categorías" />

        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <Heading
                title="Categorías de navegación"
                description="Categorías visibles en la home y el catálogo, con su imagen gestionada desde aquí (se sirve desde /marketplace/categories)."
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
                        <CardTitle class="flex items-center gap-2"><FolderTree class="size-4" /> Categorías</CardTitle>
                        <CardDescription>{{ props.categories.length }} categoría(s)</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div class="grid gap-3">
                            <div
                                v-for="category in props.categories"
                                :key="category.id"
                                class="flex items-center gap-3 rounded-xl border p-3"
                            >
                                <img
                                    v-if="category.image_url"
                                    :src="category.image_url"
                                    :alt="category.name"
                                    class="h-12 w-24 rounded-lg object-cover"
                                />
                                <span
                                    v-else
                                    class="grid h-12 w-24 place-items-center rounded-lg bg-muted text-xs text-muted-foreground"
                                >
                                    sin imagen
                                </span>
                                <div class="min-w-0 flex-1">
                                    <p class="font-semibold">
                                        {{ category.name }}
                                        <Badge v-if="!category.is_active" variant="outline" class="ml-2">inactiva</Badge>
                                    </p>
                                    <p class="truncate text-xs text-muted-foreground">/{{ category.slug }} · orden {{ category.sort }}</p>
                                </div>
                                <div class="flex gap-2">
                                    <Button variant="outline" size="sm" @click="edit(category)">
                                        <PencilLine class="size-4" />
                                    </Button>
                                    <Button variant="ghost" size="sm" @click="remove(category)">
                                        <Trash2 class="size-4" />
                                    </Button>
                                </div>
                            </div>
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
                                <Label for="slug">Slug</Label>
                                <Input id="slug" v-model="form.slug" placeholder="alimentos" />
                                <InputError :message="form.errors.slug" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="name">Nombre</Label>
                                <Input id="name" v-model="form.name" />
                                <InputError :message="form.errors.name" />
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div class="grid gap-2">
                                    <Label for="color">Color</Label>
                                    <Input id="color" v-model="form.color" placeholder="#f97316" />
                                    <InputError :message="form.errors.color" />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="sort">Orden</Label>
                                    <Input id="sort" v-model.number="form.sort" type="number" min="0" />
                                    <InputError :message="form.errors.sort" />
                                </div>
                            </div>
                            <div class="grid gap-2">
                                <Label for="image">Imagen (máx. 4 MB)</Label>
                                <input
                                    id="image"
                                    type="file"
                                    accept="image/*"
                                    class="text-sm"
                                    @change="onFile"
                                />
                                <span v-if="imageName" class="text-xs text-muted-foreground">{{ imageName }}</span>
                                <InputError :message="form.errors.image" />
                            </div>
                            <label v-if="editingId !== null" class="flex items-center gap-2 text-sm">
                                <input v-model="form.remove_image" type="checkbox" class="size-4 rounded border-input" />
                                Quitar imagen actual
                            </label>
                            <label class="flex items-center gap-2 text-sm">
                                <input v-model="form.is_active" type="checkbox" class="size-4 rounded border-input" />
                                Visible en el marketplace
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
