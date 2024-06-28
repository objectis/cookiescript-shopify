<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddGoogleConsentEnabledToConsentSettingsTable extends Migration
{
    public function up()
    {
        Schema::table('consent_settings', function (Blueprint $table) {
            $table->boolean('google_consent_enabled')->default(false);
        });
    }

    public function down()
    {
        Schema::table('consent_settings', function (Blueprint $table) {
            $table->dropColumn('google_consent_enabled');
        });
    }
}
