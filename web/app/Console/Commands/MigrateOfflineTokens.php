<?php

namespace App\Console\Commands;

use App\Lib\OfflineTokenManager;
use App\Models\Session;
use Illuminate\Console\Command;

/**
 * Converts the stored non-expiring offline access tokens of all installed shops into expiring tokens.
 *
 * Shops are also converted automatically the next time they save settings in the app; this command converts the
 * shops that don't open the app before Shopify's January 1, 2027 deadline. It is safe to run more than once.
 */
class MigrateOfflineTokens extends Command
{
    protected $signature = 'shopify:migrate-offline-tokens';

    protected $description = 'Convert non-expiring Shopify offline access tokens into expiring tokens';

    public function handle()
    {
        $shops = Session::where('is_online', false)
            ->whereNotNull('access_token')
            ->whereNull('refresh_token')
            ->pluck('shop')
            ->unique()
            ->values();

        if ($shops->isEmpty()) {
            $this->info('All offline access tokens already expire. Nothing to migrate.');
            return 0;
        }

        $migrated = 0;
        $failed = 0;

        foreach ($shops as $shop) {
            OfflineTokenManager::accessToken($shop);

            $record = Session::where('is_online', false)->where('shop', $shop)->first();

            if ($record && $record->refresh_token) {
                $migrated++;
                $this->line("Migrated: $shop");
            } else {
                $failed++;
                $this->warn("Not migrated (will be retried automatically, see the log): $shop");
            }
        }

        $this->info("Done. Migrated: $migrated, not migrated: $failed.");

        return 0;
    }
}
