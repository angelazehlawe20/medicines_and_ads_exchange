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
        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('availability_status')->default(0);  // تكون حالة المندوب مشغول حتى يقوم بتغييرها
            $table->enum('governorate', ['Damascus', 'Aleppo', 'Homs', 'Hama', 'Lattakia', 'Tartous', 'Idlib', 'Daraa', 'Deir ez-Zor', 'Hasakah', 'Raqqa', 'Suwayda', 'Quneitra', 'Rif Dimashq']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deliveries');
    }
};
