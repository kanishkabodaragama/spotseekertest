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
        Schema::table('event_invitations', function (Blueprint $table) {
            $table->unsignedInteger('package_id')->after('user_id')->nullable(false);
            $table->json('seat_nos')->after('package_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('event_invitations', function (Blueprint $table) {
            $table->dropColumn('package_id');
            $table->dropColumn('seat_nos');
        });
    }
};
