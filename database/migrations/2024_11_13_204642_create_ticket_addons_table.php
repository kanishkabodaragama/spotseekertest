<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ticket_addons', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('sale_id');
            $table->unsignedBigInteger('addon_id');
            $table->unsignedInteger('quantity');

            $table->foreign('sale_id')
                  ->references('id')
                  ->on('ticket_sales')
                  ->onDelete('cascade')
                  ->onUpdate('cascade');

            $table->foreign('addon_id')
                  ->references('id')
                  ->on('event_addons')
                  ->onDelete('cascade')
                  ->onUpdate('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ticket_addons', function (Blueprint $table) {
            $table->dropForeign(['sale_id']);
            $table->dropForeign(['addon_id']);
        });

        Schema::dropIfExists('ticket_addons');
    }
};
