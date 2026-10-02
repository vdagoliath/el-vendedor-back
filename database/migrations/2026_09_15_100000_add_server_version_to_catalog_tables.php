<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El catálogo online entra al protocolo de sync: cada fila materializada
 * necesita `server_version` para que `/sync/pull` (cursor v3) pueda entregarla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_storefronts', function (Blueprint $table) {
            $table->unsignedBigInteger('server_version')->nullable();
            $table->index('server_version');
        });

        Schema::table('catalog_products', function (Blueprint $table) {
            $table->unsignedBigInteger('server_version')->nullable();
            $table->index('server_version');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_storefronts', function (Blueprint $table) {
            $table->dropIndex(['server_version']);
            $table->dropColumn('server_version');
        });

        Schema::table('catalog_products', function (Blueprint $table) {
            $table->dropIndex(['server_version']);
            $table->dropColumn('server_version');
        });
    }
};
