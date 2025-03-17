<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('required_documents', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Name of the document (e.g., National ID, License)
            $table->enum('provider_type', ['individual', 'organizational']); // Document applies to individual or organizational providers
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('required_documents');
    }
};