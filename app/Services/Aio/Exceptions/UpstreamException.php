<?php

namespace App\Services\Aio\Exceptions;

class UpstreamException extends AioException
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        string $message = '',
    ) {
        parent::__construct(
            $message !== '' ? $message : "AIO upstream error {$status}: ".substr($body, 0, 200)
        );
    }
}
