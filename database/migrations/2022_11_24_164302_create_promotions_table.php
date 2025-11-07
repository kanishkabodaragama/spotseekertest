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
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('event_id');
            $table->bigInteger('package_id');
            $table->string('coupon_code')->unique();
            $table->double('discount_amount');
            $table->boolean('percentage')->default(0);
            $table->integer('min_tickets');
            $table->double('min_amount');
            $table->integer('max_tickets');
            $table->double('max_amount');
            $table->string('start_date');
            $table->string('end_date');
            $table->boolean('per_ticket')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('promotions');
    }
};
