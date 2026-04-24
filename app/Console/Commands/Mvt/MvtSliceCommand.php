<?php

namespace App\Console\Commands\Mvt;

use App\Models\TrackedLanding;
use App\Services\Aio\Pivot\MvtComparer;
use App\Services\Aio\Pivot\MvtReportFormatter;
use App\Services\Aio\Pivot\MvtSlicer;
use App\Services\Aio\Pivot\TelegramReportBroadcaster;
use Illuminate\Console\Command;
use Throwable;

class MvtSliceCommand extends Command
{
    protected $signature = 'mvt:slice
        {--id=* : restrict to specific tracked_landings.id values}
        {--broadcast : send formatted reports to TELEGRAM_REPORT_CHAT_IDS}
        {--dry : capture & format but do not broadcast (overrides --broadcast)}';

    protected $description = 'Capture 3h + since-start MVT slices for active tracked landings, optionally broadcast.';

    public function handle(
        MvtSlicer $slicer,
        MvtComparer $comparer,
        MvtReportFormatter $formatter,
        TelegramReportBroadcaster $broadcaster,
    ): int {
        $tracked = $this->loadTargets();
        if ($tracked->isEmpty()) {
            $this->info('No active tracked landings.');

            return self::SUCCESS;
        }

        $broadcast = $this->option('broadcast') && ! $this->option('dry');
        $failures = 0;

        foreach ($tracked as $landing) {
            $label = "{$landing->id}/{$landing->landing_uuid}@{$landing->position}";
            $this->line("→ slicing {$label}");
            try {
                [$threeHour, $sinceStart] = $slicer->captureBoth($landing);
                $comparison = $comparer->compare($threeHour);
                $html = $formatter->format($landing, $comparison);

                $this->info('  3h slice #'.$threeHour->id.' · since-start slice #'.$sinceStart->id);
                $this->line('  rows: '.count($comparison['rows']));

                if ($broadcast) {
                    $result = $broadcaster->broadcast($html);
                    $this->line("  broadcast: sent={$result['sent']} failed={$result['failed']}");
                } elseif ($this->getOutput()->isVerbose()) {
                    $this->line(strip_tags($html));
                }
            } catch (Throwable $e) {
                $failures++;
                $this->error("  failed: {$e->getMessage()}");
                report($e);
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function loadTargets()
    {
        $query = TrackedLanding::query()->whereNull('paused_at');
        $ids = (array) $this->option('id');
        if ($ids !== []) {
            $query->whereIn('id', array_map('intval', $ids));
        }

        return $query->orderBy('id')->get();
    }
}
