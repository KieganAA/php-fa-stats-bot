<?php

namespace App\Services\Support;

use RuntimeException;
use ZipArchive;

/**
 * Builds a fresh ZIP of the `extension/` directory.
 *
 * Used by:
 *   - ExtensionDownloadController (browser /extension.zip)
 *   - /extension Telegram command (sendDocument with the file attached)
 *
 * Caller is responsible for unlinking the tmp file when done.
 */
final class ExtensionZipBuilder
{
    /**
     * Build into a temp file and return its absolute path. Throws on any
     * I/O error so the caller can surface a meaningful message.
     *
     * @return array{path: string, size: int, filename: string}
     */
    public function build(): array
    {
        $source = base_path('extension');
        if (! is_dir($source)) {
            throw new RuntimeException('extension/ not found in repo');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'botstats-ext-');
        if ($tmp === false) {
            throw new RuntimeException('cannot create temp file');
        }
        // tempnam gives us an extensionless file; rename so ZipArchive is happy
        // and so the file actually looks like a zip if anything reads its tail.
        $path = $tmp.'.zip';
        rename($tmp, $path);

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            @unlink($path);
            throw new RuntimeException('cannot open zip for write');
        }

        $this->addDirToZip($zip, $source, 'bot-stats-extension');
        $zip->close();

        $size = filesize($path);
        if ($size === false || $size === 0) {
            @unlink($path);
            throw new RuntimeException('zip produced empty file');
        }

        return [
            'path' => $path,
            'size' => $size,
            'filename' => sprintf('bot-stats-extension-%s.zip', date('Ymd')),
        ];
    }

    private function addDirToZip(ZipArchive $zip, string $source, string $rootName): void
    {
        $source = rtrim($source, '/');
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iter as $file) {
            $relative = substr($file->getRealPath(), strlen($source) + 1);
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);

            if ($this->shouldSkip($relative)) {
                continue;
            }

            $zip->addFile($file->getRealPath(), $rootName.'/'.$relative);
        }
    }

    private function shouldSkip(string $relative): bool
    {
        $skipParts = ['.DS_Store', 'Thumbs.db', '.git'];
        foreach ($skipParts as $needle) {
            if (str_contains($relative, $needle)) {
                return true;
            }
        }

        return false;
    }
}
