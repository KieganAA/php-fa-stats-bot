<?php

namespace App\Jobs;

use App\Models\LandingSnapshot;
use App\Models\User;
use App\Services\Tracking\LandingSnapshotComparer;
use App\Services\Tracking\LandingSnapshotFormatter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;
use Throwable;

/**
 * Sends one user the diff between a freshly-captured LandingSnapshot and its
 * predecessor. Runs on the redis queue (worker container). Each notify is
 * independent — one user's network failure does not stall the rest.
 */
class NotifyLandingSnapshotJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public readonly int $userId,
        public readonly int $landingSnapshotId,
    ) {}

    public function handle(
        Nutgram $bot,
        LandingSnapshotComparer $comparer,
        LandingSnapshotFormatter $formatter,
    ): void {
        $user = User::query()->find($this->userId);
        $snapshot = LandingSnapshot::query()->with('trackedLanding.landing')->find($this->landingSnapshotId);

        if ($user === null || $snapshot === null) {
            return; // Cleaned up between dispatch and run — nothing to do.
        }
        if (! $user->telegram_user_id) {
            return; // User somehow lost their TG identity — can't deliver.
        }

        $tracked = $snapshot->trackedLanding;
        if ($tracked === null) {
            return;
        }

        try {
            $comparison = $comparer->compare($snapshot);
            $html = $formatter->format($tracked, $comparison);

            $bot->sendMessage(
                text: $html,
                chat_id: (int) $user->telegram_user_id,
                parse_mode: 'HTML',
                disable_web_page_preview: true,
            );
        } catch (Throwable $e) {
            Log::warning('snapshot notify failed', [
                'user_id' => $this->userId,
                'snapshot_id' => $this->landingSnapshotId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
