<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->unique()->index();
            $table->string('phone')->unique()->index();
            $table->string('password');
            $table->enum('user_type', ['customer', 'admin', 'provider']);
            $table->enum('status', ['pending', 'active', 'de-active'])->default('pending');
            $table->text('address')->nullable();
            $table->enum('gender', ['male', 'female']);
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->string('fcm_token')->nullable();
            $table->string('device_type')->nullable();
            $table->string('profile_image')->nullable();
            $table->date('birth_date')->nullable();
            $table->softDeletes();
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
