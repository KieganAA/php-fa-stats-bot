<?php

namespace App\Console\Commands;

use App\Services\Aio\AioClient;
use App\Services\Aio\Exceptions\AioException;
use Illuminate\Console\Command;
use Throwable;

class AioPingCommand extends Command
{
    protected $signature = 'aio:ping {--limit=3 : rows to fetch per endpoint}';

    protected $description = 'Smoke-test the AIO client: hits landings, landing-types, users and fields.';

    public function handle(AioClient $client): int
    {
        $limit = (int) $this->option('limit');

        $probes = [
            'landings' => fn () => $this->sample($client->streamLandings($limit), $limit),
            'landing-types' => fn () => $this->sample($client->streamLandingTypes($limit), $limit),
            'users' => fn () => $this->sample($client->streamUsers($limit), $limit),
        ];

        $failures = 0;

        foreach ($probes as $label => $probe) {
            $this->line("→ {$label}");
            try {
                $rows = $probe();
                $this->info('  ok: '.count($rows).' rows');
                foreach ($rows as $row) {
                    $this->line('  · '.($row->name ?: $row->uuid));
                }
            } catch (AioException $e) {
                $failures++;
                $this->error('  aio error: '.$e->getMessage());
            } catch (Throwable $e) {
                $failures++;
                $this->error('  unexpected: '.$e::class.': '.$e->getMessage());
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function sample(iterable $generator, int $limit): array
    {
        $out = [];
        foreach ($generator as $item) {
            $out[] = $item;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }
}
