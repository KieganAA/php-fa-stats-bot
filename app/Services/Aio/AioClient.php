<?php

namespace App\Services\Aio;

use App\Services\Aio\Dto\Field;
use App\Services\Aio\Dto\Landing;
use App\Services\Aio\Dto\LandingType;
use App\Services\Aio\Dto\PivotResponse;
use App\Services\Aio\Dto\User;
use App\Services\Aio\Http\AioHttpClient;
use App\Services\Aio\Http\Paginator;
use Generator;

class AioClient
{
    public function __construct(
        private readonly AioHttpClient $http,
    ) {}

    /** @return Generator<int, Landing> */
    public function streamLandings(int $limit = 100): Generator
    {
        foreach ($this->streamList('api/v1/data/landings', $limit) as $row) {
            yield Landing::fromArray($row);
        }
    }

    /** @return Generator<int, LandingType> */
    public function streamLandingTypes(int $limit = 100): Generator
    {
        foreach ($this->streamList('api/v1/data/landing-types', $limit) as $row) {
            yield LandingType::fromArray($row);
        }
    }

    /** @return Generator<int, User> */
    public function streamUsers(int $limit = 100): Generator
    {
        foreach ($this->streamList('api/v1/data/users', $limit) as $row) {
            yield User::fromArray($row);
        }
    }

    /** @return Generator<int, Field> */
    public function streamFields(int $limit = 200): Generator
    {
        foreach ($this->streamTable('Settings\\Fields', $limit) as $row) {
            yield Field::fromArray($row);
        }
    }

    /** @return array<int, Landing> */
    public function listLandings(int $limit = 100): array
    {
        return iterator_to_array($this->streamLandings($limit), false);
    }

    /** @return array<int, LandingType> */
    public function listLandingTypes(int $limit = 100): array
    {
        return iterator_to_array($this->streamLandingTypes($limit), false);
    }

    /** @return array<int, User> */
    public function listUsers(int $limit = 100): array
    {
        return iterator_to_array($this->streamUsers($limit), false);
    }

    /** @return array<int, Field> */
    public function listFields(int $limit = 200): array
    {
        return iterator_to_array($this->streamFields($limit), false);
    }

    public function runLanderCreateAction(array $uuids): array
    {
        return $this->http->post('api/v1/actions/data', [
            'uuids' => $uuids,
            'action' => 'Lander\\Create',
            'repository' => 'Eloquent\\LanderRepository',
        ], cacheTtl: 0);
    }

    /**
     * POST /api/v1/pivot-report/data with the wrapped request body.
     * Pass $heavy=true for wide/long-window reports.
     */
    public function pivotReport(array $request, bool $heavy = true, ?int $cacheTtl = null): PivotResponse
    {
        $response = $this->http->post(
            'api/v1/pivot-report/data',
            ['request' => $request],
            cacheTtl: $cacheTtl,
            heavy: $heavy,
        );

        return PivotResponse::fromArray($response);
    }

    /**
     * POST /api/v1/tables/data with `{request: {TableName: filters}}`.
     *
     * @return array<int, array<string, mixed>>
     */
    public function queryTable(string $table, array $filters = [], int $limit = 100): array
    {
        $rows = [];

        foreach (Paginator::iterate(
            fn (int $page) => $this->http->post('api/v1/tables/data', [
                'request' => [
                    $table => array_merge(['page' => $page, 'limit' => $limit], $filters),
                ],
            ]),
            limit: $limit,
        ) as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    /** @return Generator<int, array<string, mixed>> */
    private function streamTable(string $table, int $limit): Generator
    {
        yield from Paginator::iterate(
            fn (int $page) => $this->http->post('api/v1/tables/data', [
                'request' => [
                    $table => ['page' => $page, 'limit' => $limit],
                ],
            ]),
            limit: $limit,
        );
    }

    /** @return Generator<int, array<string, mixed>> */
    private function streamList(string $path, int $limit): Generator
    {
        yield from Paginator::iterate(
            fn (int $page) => $this->http->get($path, ['page' => $page, 'limit' => $limit]),
            limit: $limit,
        );
    }
}
