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
        Schema::create('sub_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('sale_id');
            $table->integer('package_id');
            $table->string('sub_order_id')->unique();
            $table->string('e_ticket_url')->nullable();
            $table->string('booking_status')->default('pending');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('sub_tickets');
    }
};
