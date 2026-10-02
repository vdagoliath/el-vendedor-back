<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El escritorio captura categoria (proveedores) y estado para ambos tipos de
     * contacto. Sin estas columnas el push no fallaba, pero el dato se perdia y
     * el pull lo devolvia vacio: el CRUD de proveedores quedaba a medias.
     */
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->string('category', 191)->nullable()->after('type');
            $table->string('status', 32)->nullable()->after('category');
            $table->string('id_card', 32)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropColumn(['category', 'status']);
            $table->string('id_card', 11)->nullable()->change();
        });
    }
};
