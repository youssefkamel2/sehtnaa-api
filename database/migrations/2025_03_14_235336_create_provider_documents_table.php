<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('provider_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->onDelete('cascade');
            $table->foreignId('required_document_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('deleted_document_name')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('document_path'); // Path to the uploaded document
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending'); // Document status
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('provider_documents');
    }
};
