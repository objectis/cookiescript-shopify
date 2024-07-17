<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateScriptSrcTable extends Migration
{
    public function up()
    {
        Schema::create('script_src', function (Blueprint $table) {
            $table->id();
            $table->string('shop_domain');
            $table->string('src');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('script_src');
    }
}
