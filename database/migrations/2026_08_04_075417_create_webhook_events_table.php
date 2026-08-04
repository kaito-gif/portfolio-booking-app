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
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('webhook_id', 64)->unique();
            $table->string('topic', 50);
            $table->string('shopify_order_id', 64)->nullable();
            $table->longText('payload')->nullable();
            $table->string('status', 20)->default('received');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('next_attempt_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->dateTime('processed_at')->nullable();
            $table->dateTime('received_at');
            $table->timestamps();

            $table->index('status');
            $table->index('received_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
