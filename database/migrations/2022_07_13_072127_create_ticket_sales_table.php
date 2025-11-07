<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateTicketSalesTable extends Migration {

	public function up()
	{
		Schema::create('ticket_sales', function(Blueprint $table) {
			$table->increments('id');
			$table->timestamps();
			$table->softDeletes();
			$table->integer('user_id');
			$table->integer('event_id')->unsigned();
			$table->integer('package_id')->unsigned();
			$table->integer('tot_ticket_count');
		});
	}

	public function down()
	{
		Schema::drop('ticket_sales');
	}
}