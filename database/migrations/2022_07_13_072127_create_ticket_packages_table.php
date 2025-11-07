<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateTicketPackagesTable extends Migration {

	public function up()
	{
		Schema::create('ticket_packages', function(Blueprint $table) {
			$table->increments('id');
			$table->string('name');
			$table->string('price');
			$table->json('seating_range');
			$table->string('desc')->nullable();
			$table->integer('tot_tickets')->unsigned();
			$table->timestamps();
			$table->softDeletes();
		});
	}

	public function down()
	{
		Schema::drop('ticket_packages');
	}
}