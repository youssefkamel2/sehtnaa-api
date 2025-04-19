<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->string('campaign_id')->index();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('user_type')->default('customer')->index();
            $table->string('title');
            $table->text('body');
            $table->json('data')->nullable();
            $table->json('response')->nullable();
            $table->json('attempt_logs')->nullable();
            $table->string('device_token')->nullable();
            $table->unsignedSmallInteger('attempts_count')->default(0);
            $table->boolean('is_sent')->default(false);
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            
            $table->index(['campaign_id', 'user_id']);
            $table->index(['is_sent', 'attempts_count']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('notification_logs');
    }
};