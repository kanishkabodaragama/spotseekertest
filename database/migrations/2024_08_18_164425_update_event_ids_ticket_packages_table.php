<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        DB::statement('
            UPDATE ticket_packages ts
            JOIN event_package ep ON ts.id = ep.package_id
            SET ts.event_id = ep.event_id
            where ts.id = ep.package_id
        ');
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        DB::statement('
            UPDATE ticket_packages
            SET event_id = NULL
        ');
    }
};
