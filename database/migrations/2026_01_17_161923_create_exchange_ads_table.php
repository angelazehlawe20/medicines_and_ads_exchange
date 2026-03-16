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
        Schema::create('exchange_ads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('governorate', ['Damascus', 'Aleppo', 'Homs', 'Hama', 'Lattakia', 'Tartous', 'Daraa', 'Deir ez-Zor', 'Hasakah', 'Raqqa', 'Suwayda', 'Quneitra', 'Rif Dimashq']);
            $table->foreignId('specialist_id')->nullable()->constrained('specialists')->cascadeOnDelete();
            $table->string('medicine_name');
            $table->string('image')->nullable();
            $table->decimal('price', 10, 2)->default(0.00)->nullable(); //3456734567.32
            $table->enum('ad_type', ['donation', 'sale']);
            $table->boolean('security_check_status')->nullable();
            $table->boolean('is_showing')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exchange_ads');
    }
};
