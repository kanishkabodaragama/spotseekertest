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
        Schema::table('ticket_sales', function (Blueprint $table) {
            $table->string('tot_amount');
            $table->string('payment_status')->default('pending');
            $table->string('payment_ref_no')->nullable();
            $table->string('order_id')->nullable();
            $table->string('transaction_date_time')->nullable();
            $table->string('comment')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('ticket_sales', function (Blueprint $table) {
            $table->dropColumn('tot_amount');
            $table->dropColumn('payment_status');
            $table->dropColumn('payment_ref_no');
            $table->dropColumn('order_id');
            $table->dropColumn('transaction_date_time');
            $table->dropColumn('comment');
        });
    }
};
