<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->onDelete('cascade');
            // $table->foreignId('service_id')->constrained()->onDelete('cascade');
            $table->string('phone')->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->text('additional_info')->nullable();
            $table->enum('gender', ['male', 'female']);
            $table->string('address')->nullable();
            $table->integer('age')->nullable();
            $table->decimal('total_price', 10, 2)->default(0);
            $table->enum('status', ['pending', 'accepted', 'completed', 'cancelled'])->default('pending');
            $table->foreignId('assigned_provider_id')->nullable()->constrained('providers')->onDelete('set null');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });


    }

    public function down(): void
    {
        Schema::dropIfExists('requests');
    }
};
