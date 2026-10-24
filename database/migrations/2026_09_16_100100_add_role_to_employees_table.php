<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            // Rol operativo del empleado dentro del negocio:
            // waiter | cashier | kitchen | manager (null = sin asignar).
            $table->string('role', 32)->nullable()->after('mobile');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn('role');
        });
    }
};
