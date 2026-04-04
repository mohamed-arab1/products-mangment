<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 12, 2)->default(0);
            $table->date('production_date')->nullable();
            $table->unsignedInteger('shelf_life_value')->nullable();
            $table->string('shelf_life_unit', 16)->nullable();
            $table->date('expiry_date')->nullable();
            $table->decimal('purchase_price', 12, 2)->nullable();
            $table->string('notes', 255)->nullable();
            $table->timestamps();
            $table->index(['product_id', 'expiry_date']);
        });

        $driver = Schema::getConnection()->getDriverName();
        $products = DB::table('products')->select([
            'id', 'stock_quantity', 'production_date', 'shelf_life_value',
            'shelf_life_unit', 'expiry_date', 'purchase_price',
        ])->get();

        foreach ($products as $p) {
            $qty = (float) ($p->stock_quantity ?? 0);
            if ($qty <= 0) {
                continue;
            }
            DB::table('product_batches')->insert([
                'product_id' => $p->id,
                'quantity' => $qty,
                'production_date' => $p->production_date,
                'shelf_life_value' => $p->shelf_life_value,
                'shelf_life_unit' => $p->shelf_life_unit,
                'expiry_date' => $p->expiry_date,
                'purchase_price' => $p->purchase_price,
                'notes' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if ($driver === 'mysql') {
            DB::statement('
                UPDATE products p
                INNER JOIN (
                    SELECT product_id, SUM(quantity) AS sq
                    FROM product_batches
                    GROUP BY product_id
                ) b ON b.product_id = p.id
                SET p.stock_quantity = FLOOR(b.sq)
            ');
        } else {
            foreach ($products as $p) {
                $sum = (float) DB::table('product_batches')->where('product_id', $p->id)->sum('quantity');
                if ($sum > 0) {
                    DB::table('products')->where('id', $p->id)->update(['stock_quantity' => (int) round($sum)]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_batches');
    }
};
