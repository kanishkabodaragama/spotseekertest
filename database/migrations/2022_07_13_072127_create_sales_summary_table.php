<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateSalesSummaryTable extends Migration {

	public function up()
	{
		Schema::create('sales_summary', function(Blueprint $table) {
			$table->increments('id');
			$table->timestamps();
			$table->integer('event_id')->unsigned();
			$table->integer('tot_ticket_count');
			$table->integer('tot_ticket_sale_count')->default('0');
			$table->integer('package_id')->unsigned();
		});
	}

	public function down()
	{
		Schema::drop('sales_summary');
	}
}