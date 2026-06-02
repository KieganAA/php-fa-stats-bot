<?php

namespace App\Http\Controllers;

use App\Services\Support\ExtensionZipBuilder;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * GET /extension.zip — streams a fresh ZIP of `extension/`.
 *
 * Note: behind `ngrok-free.app` browsers hit an HTML interstitial before
 * the actual response. That's why the primary install path is the bot's
 * /extension command — it sends the same zip as a Telegram document, no
 * HTTP traversal of any tunnel. This endpoint stays for curl/wget/CI use.
 */
class ExtensionDownloadController
{
    public function __invoke(ExtensionZipBuilder $builder): StreamedResponse|Response
    {
        try {
            $built = $builder->build();
        } catch (Throwable $e) {
            return response($e->getMessage(), 500);
        }

        return response()->streamDownload(
            function () use ($built) {
                readfile($built['path']);
                @unlink($built['path']);
            },
            $built['filename'],
            [
                'Content-Type' => 'application/zip',
                'Content-Length' => (string) $built['size'],
                // Hint to ngrok-free that this is an API hit, not a browser
                // session — some setups will let the binary through without
                // the interstitial when this header is present.
                'ngrok-skip-browser-warning' => '1',
            ],
        );
    }
}
