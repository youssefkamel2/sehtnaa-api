<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProviderNotificationsTable extends Migration
{
    public function up()
    {
        Schema::create('provider_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('requests')->onDelete('cascade');
            $table->foreignId('provider_id')->constrained('providers')->onDelete('cascade');
            $table->decimal('distance', 8, 2);
            $table->integer('radius');
            $table->timestamp('notified_at');
            $table->timestamps();
            
            $table->unique(['request_id', 'provider_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('provider_notifications');
    }
}