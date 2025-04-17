<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained('services')->onDelete('cascade');
            $table->json('name');
            $table->enum('type', ['input', 'file']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_requirements');
    }
};