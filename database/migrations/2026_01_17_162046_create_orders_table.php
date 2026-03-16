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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('pharmacy_id')->constrained('pharmacies')->cascadeOnDelete();
            $table->foreignId('delivery_id')->nullable()->constrained('deliveries')->cascadeOnDelete();
            $table->string('coupon_code')->nullable();
            $table->decimal('total_price', 10, 2);
            $table->enum('governorate', ['Damascus', 'Aleppo', 'Homs', 'Hama', 'Lattakia', 'Tartous', 'Daraa', 'Deir ez-Zor', 'Hasakah', 'Raqqa', 'Suwayda', 'Quneitra', 'Rif Dimashq']);
            $table->enum('ph_approval_status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->enum('order_status', ['pending', 'in_process', 'picked_up', 'delivered', 'cancelled'])->default('pending');
            $table->enum('delivery_approval_status', ['pending', 'assigned', 'accepted', 'rejected'])->default('pending');
            $table->string('address');
            $table->string('customer_name');
            $table->string('phone_number');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
