<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('shipping_formulas', function (Blueprint $table) {
            $table->id();
            $table->string('country_code', 2);
            $table->string('country_name');
            $table->decimal('base_fee', 10, 2)->comment('Base fee for shipping to this country');
            $table->decimal('price_per_kg', 10, 2)->comment('Additional price per kg');
            $table->decimal('price_per_cubic_meter', 10, 2)->nullable()->comment('Additional price per cubic meter of volume');
            $table->decimal('min_shipping_fee', 10, 2)->comment('Minimum shipping fee regardless of calculated amount');
            $table->decimal('max_weight', 10, 2)->nullable()->comment('Maximum weight allowed in kg');
            $table->string('currency', 3)->default('EUR');
            $table->decimal('handling_fee_percentage', 5, 2)->default(0)->comment('Additional percentage for handling');
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            // Index on country code for fast lookups
            $table->index('country_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shipping_formulas');
    }
};