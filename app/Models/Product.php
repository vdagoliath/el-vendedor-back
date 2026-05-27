<?php

namespace App\Models;

use App\Models\Concerns\HasServerVersion;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    use HasServerVersion;
    use SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'business_id',
        'external_id',
        'server_version',
        'code',
        'title',
        'description',
        'type',
        'regular_price',
        'purchase_price',
        'barcode_type',
        'min_stock',
        'category_external_id',
        'unit_of_measurement',
        'unit_of_measurement_purchase',
        'stock_by_warehouse',
        'has_recipe',
        'recipe_items',
        'can_breakdown',
        'breakdown_target_product_external_id',
        'breakdown_target_quantity',
        'breakdown_target_title_snapshot',
        'breakdown_target_unit_symbol_snapshot',
        'source_created_at',
        'source_updated_at',
        'last_received_event_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'regular_price' => 'decimal:4',
            'purchase_price' => 'decimal:4',
            'min_stock' => 'decimal:4',
            'unit_of_measurement' => 'array',
            'unit_of_measurement_purchase' => 'array',
            'stock_by_warehouse' => 'array',
            'has_recipe' => 'boolean',
            'recipe_items' => 'array',
            'can_breakdown' => 'boolean',
            'breakdown_target_quantity' => 'decimal:4',
            'source_created_at' => 'datetime',
            'source_updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Get the business that owns the product.
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
