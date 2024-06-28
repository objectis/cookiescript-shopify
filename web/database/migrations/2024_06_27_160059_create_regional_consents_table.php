<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRegionalConsentsTable extends Migration
{
    public function up()
    {
        Schema::create('regional_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consent_setting_id')->constrained('consent_settings')->onDelete('cascade');
            $table->string('region');
            $table->string('ad_storage');
            $table->string('analytics_storage');
            $table->string('ad_user_data');
            $table->string('ad_personalization');
            $table->string('functionality_storage');
            $table->string('personalization_storage');
            $table->string('security_storage');
            $table->string('wait_for_update');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('regional_consents');
    }
}
