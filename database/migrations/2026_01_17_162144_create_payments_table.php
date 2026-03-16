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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->enum('payment_method', ['electronic', 'cash']);
            // new
            $table->enum('payment_status', ['pending', 'paid','failed','refunded'])->default('pending');
            // new
            $table->string('stripe_payment_id')->nullable(); // لتخزين رقم العملية في سترايب
            $table->string('currency')->default('USD');
            $table->timestamp('transaction_time')->useCurrent();
            $table->decimal('amount', 10, 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
