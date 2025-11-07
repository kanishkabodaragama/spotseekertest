<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateEventsTable extends Migration {

	public function up()
	{
		Schema::create('events', function(Blueprint $table) {
			$table->increments('id');		
			$table->string('name');
			$table->string('organizer');
			$table->string('manager')->nullable();
			$table->integer('venue_id')->unsigned();
			$table->string('start_date');
			$table->string('end_date');
			$table->string('status')->default('pending');
			$table->timestamps();
			$table->softDeletes();
		});
	}

	public function down()
	{
		Schema::drop('events');
	}
}