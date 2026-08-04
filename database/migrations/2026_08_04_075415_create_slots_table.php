<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workshop_id')->constrained('workshops');
            $table->dateTime('starts_at');
            $table->unsignedSmallInteger('capacity');
            $table->string('status', 20)->default('draft');
            $table->string('shopify_variant_id', 64)->nullable()->unique('slots_variant_unique');
            $table->string('shopify_inventory_item_id', 64)->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index('starts_at', 'slots_starts_at_index');
            $table->index(['status', 'starts_at'], 'slots_status_starts_at_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('slots');
    }
};
