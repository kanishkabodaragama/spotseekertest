<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('ticket_packages', function (Blueprint $table) {
            $table->unsignedInteger('event_id')->after('id')->nullable(false);
            $table->boolean('sold_out')->default(false);
            $table->boolean('private')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('ticket_packages', function (Blueprint $table) {
            $table->dropColumn('event_id');
            $table->boolean('sold_out')->default(false);
            $table->dropColumn('private');
        });
    }
};
