<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddSearchRadiusFieldsToRequestsTable extends Migration
{
    public function up()
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->integer('current_search_radius')->nullable()->after('status');
            $table->integer('expansion_attempts')->default(0)->after('current_search_radius');
            $table->timestamp('last_expansion_at')->nullable()->after('expansion_attempts');
        });
    }

    public function down()
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->dropColumn(['current_search_radius', 'expansion_attempts', 'last_expansion_at']);
        });
    }
}