<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_layouts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('server_version')->nullable()->after('id');
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            // `external_id` es el posId del restaurante: un plano por punto de venta.
            $table->string('external_id', 191);
            $table->string('pos_name')->nullable();
            $table->json('salons')->nullable();
            $table->json('tables')->nullable();
            $table->string('last_received_event_id', 191)->nullable();
            $table->timestamp('source_created_at')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['business_id', 'external_id']);
            $table->index(['business_id', 'updated_at']);
            $table->index('server_version', 'restaurant_layouts_server_version_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_layouts');
    }
};
