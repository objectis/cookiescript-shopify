<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columns for Shopify's expiring offline access tokens (required for public apps from January 1, 2027):
 * the access token expires after 1 hour and is renewed with a refresh token.
 */
class AddExpiringOfflineTokenColumnsToSessions extends Migration
{
    public function up()
    {
        Schema::table('sessions', function (Blueprint $table) {
            $table->dateTime('access_token_expires_at')->nullable();
            $table->text('refresh_token')->nullable();
            $table->dateTime('refresh_token_expires_at')->nullable();
        });
    }

    public function down()
    {
        Schema::table('sessions', function (Blueprint $table) {
            $table->dropColumn(['access_token_expires_at', 'refresh_token', 'refresh_token_expires_at']);
        });
    }
}
