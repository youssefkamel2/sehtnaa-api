<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->json('name'); // Multi-language support
            $table->json('description')->nullable();
            $table->string('icon')->nullable();
            $table->boolean('is_active')->default(true);
            // column to check if this category support multiple services
            $table->boolean('is_multiple')->default(false);
            $table->integer('order')->default(0);
            $table->foreignId('added_by')->constrained('users')->onDelete('cascade');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down()
    {
        Schema::dropIfExists('categories');
    }
};