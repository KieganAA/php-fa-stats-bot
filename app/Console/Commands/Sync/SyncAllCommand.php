<?php

namespace App\Console\Commands\Sync;

use App\Services\Aio\Exceptions\AioException;
use App\Services\Aio\Sync\FieldSyncer;
use App\Services\Aio\Sync\LandingSyncer;
use App\Services\Aio\Sync\LandingTypeSyncer;
use App\Services\Aio\Sync\UserSyncer;
use Illuminate\Console\Command;
use Throwable;

class SyncAllCommand extends Command
{
    protected $signature = 'aio:sync:all';

    protected $description = 'Run every AIO reference-data sync in order.';

    public function handle(
        LandingTypeSyncer $types,
        UserSyncer $users,
        FieldSyncer $fields,
        LandingSyncer $landings,
    ): int {
        $jobs = [
            'landing-types' => fn () => $types->sync(),
            'users' => fn () => $users->sync(),
            'fields' => fn () => $fields->sync(),
            'landings' => fn () => $landings->sync(),
        ];

        $failed = 0;

        foreach ($jobs as $label => $job) {
            $this->info("→ {$label}");
            try {
                $this->info('  '.$job()->summary());
            } catch (AioException $e) {
                $failed++;
                $this->error('  aio error: '.$e->getMessage());
            } catch (Throwable $e) {
                $failed++;
                $this->error('  unexpected: '.$e::class.': '.$e->getMessage());
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
