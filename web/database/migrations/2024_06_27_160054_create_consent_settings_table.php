<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateConsentSettingsTable extends Migration
{
    public function up()
    {
        Schema::create('consent_settings', function (Blueprint $table) {
            $table->id();
            $table->string('shop_domain')->unique();
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
        Schema::dropIfExists('consent_settings');
    }
}
