<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\CatalogProduct;
use App\Models\CatalogStorefront;
use Illuminate\Database\Seeder;

/**
 * Catálogo de ejemplo para probar el catálogo online contra el backend real.
 *
 *   php artisan db:seed --class=CatalogDemoSeeder
 *
 * Crea (si no existe) el negocio `bodega-la-esquina`, publica su catálogo en
 * `bodega-la-esquina.miraloaki.com` y carga productos con precios propios.
 */
class CatalogDemoSeeder extends Seeder
{
    public function run(): void
    {
        $business = Business::query()->firstOrCreate(
            ['slug' => 'bodega-la-esquina'],
            [
                'name' => 'Bodega La Esquina',
                'address' => 'Calle 23 #456 e/ 10 y 12, Vedado, La Habana',
                'phone' => '+53 5551 2345',
                'default_currency' => 'CUP',
                'is_active' => true,
            ],
        );

        CatalogStorefront::query()->updateOrCreate(
            ['business_id' => $business->id],
            [
                'subdomain' => 'bodega-la-esquina',
                'display_name' => 'Bodega La Esquina',
                'tagline' => 'Productos de primera necesidad, siempre a mano',
                'about' => 'Somos la bodega del barrio. Vendemos al detalle y por mayor, con entrega en la zona y atención por WhatsApp. Si no encuentras algo, pregúntanos: lo pedimos para ti.',
                'accent_color' => '#0F766E',
                'whatsapp' => '5355512345',
                'phone' => '+53 5551 2345',
                'email' => 'labodega@example.com',
                'address' => 'Calle 23 #456 e/ 10 y 12, Vedado, La Habana',
                'city' => 'La Habana',
                'hours' => [
                    ['day' => 1, 'open' => '08:00', 'close' => '19:00'],
                    ['day' => 2, 'open' => '08:00', 'close' => '19:00'],
                    ['day' => 3, 'open' => '08:00', 'close' => '19:00'],
                    ['day' => 4, 'open' => '08:00', 'close' => '19:00'],
                    ['day' => 5, 'open' => '08:00', 'close' => '19:00'],
                    ['day' => 6, 'open' => '08:00', 'close' => '14:00'],
                    ['day' => 0, 'closed' => true, 'open' => '00:00', 'close' => '00:00'],
                ],
                'social' => ['instagram' => 'https://instagram.com/bodegalaesquina'],
                'currency' => 'CUP',
                'currency_symbol' => null,
                'delivery_note' => 'Entrega en Vedado y Centro Habana el mismo día',
                'payment_note' => 'Aceptamos efectivo y transferencia (Transfermóvil)',
                'template' => 'clasico',
                'is_published' => true,
                'published_at' => now(),
            ],
        );

        foreach ($this->products() as $index => $row) {
            CatalogProduct::query()->updateOrCreate(
                [
                    'business_id' => $business->id,
                    'product_external_id' => $row['external_id'],
                ],
                [
                    'slug' => $row['slug'],
                    'title' => $row['title'],
                    'description' => $row['description'],
                    'price' => $row['price'],
                    'currency' => 'CUP',
                    'unit' => $row['unit'],
                    'badge' => $row['badge'] ?? null,
                    'category_name' => $row['category'],
                    'category_emoji' => $row['emoji'],
                    'images' => [],
                    'is_visible' => true,
                    'is_featured' => $row['featured'] ?? false,
                    'is_available' => $row['available'] ?? true,
                    'sort' => $index + 1,
                ],
            );
        }

        $this->command?->info('Catálogo de ejemplo listo en bodega-la-esquina.miraloaki.com');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function products(): array
    {
        return [
            ['external_id' => 'product-arroz', 'slug' => 'arroz-grano-largo', 'title' => 'Arroz grano largo', 'description' => 'Arroz de grano largo, bolsa sellada de 1 kg.', 'price' => 320, 'unit' => 'kg', 'category' => 'Alimentos', 'emoji' => '🍚', 'badge' => 'Más vendido', 'featured' => true],
            ['external_id' => 'product-frijol', 'slug' => 'frijol-negro', 'title' => 'Frijol negro', 'description' => 'Frijol negro seleccionado, libre de piedra. Bolsa de 1 lb.', 'price' => 480, 'unit' => 'lb', 'category' => 'Alimentos', 'emoji' => '🫘', 'featured' => true],
            ['external_id' => 'product-aceite', 'slug' => 'aceite-girasol', 'title' => 'Aceite de girasol', 'description' => 'Aceite de girasol de 1 litro, envase plástico sellado.', 'price' => 950, 'unit' => 'litro', 'category' => 'Alimentos', 'emoji' => '🫗'],
            ['external_id' => 'product-azucar', 'slug' => 'azucar-refinada', 'title' => 'Azúcar refinada', 'description' => 'Azúcar blanca refinada, bolsa de 1 kg.', 'price' => 260, 'unit' => 'kg', 'category' => 'Alimentos', 'emoji' => '🍬'],
            ['external_id' => 'product-cafe', 'slug' => 'cafe-cubita-250', 'title' => 'Café Cubita 250 g', 'description' => 'Café tostado y molido, paquete de 250 g. Aroma intenso.', 'price' => 1250, 'unit' => 'paquete', 'category' => 'Alimentos', 'emoji' => '☕', 'badge' => 'Nuevo', 'featured' => true],
            ['external_id' => 'product-leche', 'slug' => 'leche-en-polvo', 'title' => 'Leche en polvo', 'description' => 'Leche entera en polvo, bolsa de 400 g.', 'price' => 1580, 'unit' => 'bolsa', 'category' => 'Alimentos', 'emoji' => '🥛', 'available' => false],
            ['external_id' => 'product-pan', 'slug' => 'pan-de-gloria', 'title' => 'Pan de gloria', 'description' => 'Pan de gloria recién horneado, dulce y esponjoso.', 'price' => 350, 'unit' => 'unidad', 'category' => 'Alimentos', 'emoji' => '🥖', 'badge' => 'Recién hecho', 'featured' => true],
            ['external_id' => 'product-refresco', 'slug' => 'refresco-cola', 'title' => 'Refresco de cola 1.5 L', 'description' => 'Refresco de cola bien frío, botella de 1.5 litros.', 'price' => 700, 'unit' => 'botella', 'category' => 'Bebidas', 'emoji' => '🥤'],
            ['external_id' => 'product-agua', 'slug' => 'agua-mineral', 'title' => 'Agua mineral 5 L', 'description' => 'Agua mineral natural, pomo de 5 litros.', 'price' => 420, 'unit' => 'pomo', 'category' => 'Bebidas', 'emoji' => '💧'],
            ['external_id' => 'product-jabon', 'slug' => 'jabon-de-tocar', 'title' => 'Jabón de tocarte', 'description' => 'Jabón de baño, barra de 120 g.', 'price' => 180, 'unit' => 'barra', 'category' => 'Aseo', 'emoji' => '🧼'],
            ['external_id' => 'product-detergente', 'slug' => 'detergente-1kg', 'title' => 'Detergente en polvo', 'description' => 'Detergente para ropa, bolsa de 1 kg.', 'price' => 890, 'unit' => 'kg', 'category' => 'Aseo', 'emoji' => '🧴'],
            ['external_id' => 'product-galletas', 'slug' => 'galletas-dulces', 'title' => 'Galletas dulces', 'description' => 'Paquete de galletas dulces, 12 unidades.', 'price' => 240, 'unit' => 'paquete', 'category' => 'Snacks', 'emoji' => '🍪'],
        ];
    }
}
