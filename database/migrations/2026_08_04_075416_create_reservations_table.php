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
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('slot_id')->constrained('slots');
            $table->char('code', 15)->unique('reservations_code_unique');
            $table->string('name', 50);
            $table->string('email', 255);
            $table->string('phone', 20)->nullable();
            $table->string('status', 20)->default('inventory_pending');
            $table->string('source', 20);
            $table->string('shopify_order_id', 64)->nullable();
            $table->string('shopify_line_item_id', 64)->nullable();
            $table->unsignedTinyInteger('seat_index')->nullable();
            $table->dateTime('checked_in_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancelled_by', 20)->nullable();
            $table->timestamps();

            $table->unique(
                ['shopify_order_id', 'shopify_line_item_id', 'seat_index'],
                'reservations_order_line_seat_unique'
            );
            $table->index('email', 'reservations_email_index');
            $table->index(['slot_id', 'status'], 'reservations_slot_status_index');
            $table->index('status', 'reservations_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
