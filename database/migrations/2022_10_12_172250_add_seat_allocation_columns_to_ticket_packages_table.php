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
            $table->json('reserved_seats')->nullable();
            $table->json('available_seats')->nullable();
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
            $table->dropColumn('reserved_seats');
            $table->dropColumn('available_seats');
        });
    }
};
