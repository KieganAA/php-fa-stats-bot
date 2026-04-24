<?php

namespace App\Services\Aio\Exceptions;

class RateLimitExceededException extends AioException
{
    public function __construct(public readonly string $limit, string $message = '')
    {
        parent::__construct($message !== '' ? $message : "AIO rate limit exceeded: {$limit}");
    }
}
