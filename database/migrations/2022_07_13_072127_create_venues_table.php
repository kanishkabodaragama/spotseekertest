<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateVenuesTable extends Migration {

	public function up()
	{
		Schema::create('venues', function(Blueprint $table) {
			$table->increments('id');
			$table->string('name');
			$table->string('desc');
			$table->integer('seating_capacity');
			$table->timestamps();
			$table->softDeletes();
		});
	}

	public function down()
	{
		Schema::drop('venues');
	}
}