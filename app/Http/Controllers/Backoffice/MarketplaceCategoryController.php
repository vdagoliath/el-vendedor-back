<?php

namespace App\Http\Controllers\Backoffice;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MarketplaceCategoryController extends Controller
{
    /**
     * Categorias de navegacion (imagen gestionada desde el backoffice).
     */
    public function index(): Response
    {
        return Inertia::render('backoffice/MarketplaceCategories', [
            'categories' => MarketplaceCategory::query()->ordered()->get()->map(fn (MarketplaceCategory $category): array => [
                'id' => $category->id,
                'slug' => $category->slug,
                'name' => $category->name,
                'image_url' => $category->image_url,
                'color' => $category->color,
                'sort' => $category->sort,
                'is_active' => $category->is_active,
            ])->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatePayload($request);
        $validated['image_url'] = $this->storeImage($request, $validated['slug']);

        MarketplaceCategory::query()->create($validated);

        return to_route('backoffice.marketplace.categories.index')
            ->with('success', 'La categoría fue creada.');
    }

    public function update(Request $request, MarketplaceCategory $category): RedirectResponse
    {
        $validated = $this->validatePayload($request, $category);

        if ($request->boolean('remove_image')) {
            $this->deleteImage($category->image_url);
            $validated['image_url'] = null;
        }

        $imageUrl = $this->storeImage($request, $validated['slug']);
        if ($imageUrl !== null) {
            $this->deleteImage($category->image_url);
            $validated['image_url'] = $imageUrl;
        }

        $category->update($validated);

        return to_route('backoffice.marketplace.categories.index')
            ->with('success', 'La categoría fue actualizada.');
    }

    public function destroy(MarketplaceCategory $category): RedirectResponse
    {
        $this->deleteImage($category->image_url);
        $category->delete();

        return to_route('backoffice.marketplace.categories.index')
            ->with('success', 'La categoría fue eliminada.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, ?MarketplaceCategory $category = null): array
    {
        return $request->validate([
            'slug' => [
                'required', 'string', 'max:120', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('marketplace_categories', 'slug')->ignore($category?->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:16'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['boolean'],
            'remove_image' => ['boolean'],
            'image' => ['nullable', 'image', 'max:4096'],
        ]);
    }

    /**
     * Guarda la imagen en public/marketplace/categories y devuelve su URL
     * publica (o null si no se subio archivo).
     */
    private function storeImage(Request $request, string $slug): ?string
    {
        $file = $request->file('image');

        if (! $file instanceof UploadedFile) {
            return null;
        }

        $directory = public_path('marketplace/categories');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $filename = Str::slug($slug).'-'.Str::lower(Str::random(8)).'.'.$file->getClientOriginalExtension();
        $file->move($directory, $filename);

        return '/marketplace/categories/'.$filename;
    }

    private function deleteImage(?string $imageUrl): void
    {
        if (! $imageUrl || ! str_starts_with($imageUrl, '/marketplace/categories/')) {
            return;
        }

        $path = public_path(ltrim($imageUrl, '/'));

        if (is_file($path)) {
            unlink($path);
        }
    }
}
