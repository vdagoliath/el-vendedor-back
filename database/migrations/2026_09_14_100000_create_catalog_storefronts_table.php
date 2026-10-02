<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo online por negocio: configuración pública del subdominio
 * ({subdomain}.miraloaki.com). Canal independiente del ecommerce.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_storefronts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('subdomain')->unique();
            $table->string('display_name');
            $table->string('tagline')->nullable();
            $table->text('about')->nullable();
            $table->string('cover_url')->nullable();
            $table->string('logo_url')->nullable();
            $table->string('accent_color', 7)->default('#0F766E');
            $table->string('accent_contrast', 7)->nullable();
            $table->string('whatsapp', 32)->default('');
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->json('hours')->nullable();
            $table->json('social')->nullable();
            $table->string('currency', 3)->default('CUP');
            $table->string('currency_symbol', 8)->nullable();
            $table->string('delivery_note')->nullable();
            $table->string('payment_note')->nullable();
            $table->string('template', 32)->default('clasico');
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['is_published', 'subdomain']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_storefronts');
    }
};
