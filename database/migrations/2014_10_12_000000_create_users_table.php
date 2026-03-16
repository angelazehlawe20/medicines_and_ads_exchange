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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username')->unique();
            $table->string('email')->unique()->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('phone')->unique()->nullable();
            $table->enum('governorate', ['Damascus', 'Aleppo', 'Homs', 'Hama', 'Lattakia', 'Tartous', 'Daraa', 'Deir ez-Zor', 'Hasakah', 'Raqqa', 'Suwayda', 'Quneitra', 'Rif Dimashq']);
            $table->enum('role', ['admin', 'citizen', 'pharmacy', 'specialist', 'delivery']);
            $table->boolean('account_status')->default(true);
            $table->text('fcm_token')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
