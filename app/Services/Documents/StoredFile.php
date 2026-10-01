<?php

namespace App\Services\Documents;

use Illuminate\Support\Facades\Storage;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RuntimeException;

/**
 * Bridges the default filesystem disk and libraries that need a real file
 * path (the PDF parser, the .pptx writer). On a local disk the stored file is
 * used directly; on a cloud disk (e.g. an S3-compatible bucket) it goes
 * through a temporary file that is removed afterwards.
 */
class StoredFile
{
    /**
     * Run $callback with an absolute local path to a stored file.
     *
     * @template T
     *
     * @param  callable(string): T  $callback
     * @return T
     */
    public static function withLocalPath(string $path, callable $callback): mixed
    {
        $disk = Storage::disk();

        if ($disk->getAdapter() instanceof LocalFilesystemAdapter) {
            return $callback($disk->path($path));
        }

        $stream = $disk->readStream($path);

        if (! is_resource($stream)) {
            throw new RuntimeException('The stored file could not be read.');
        }

        $temporaryPath = self::temporaryPath();

        try {
            $target = fopen($temporaryPath, 'wb');
            stream_copy_to_stream($stream, $target);
            fclose($target);

            return $callback($temporaryPath);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }

            @unlink($temporaryPath);
        }
    }

    /**
     * Let $writer produce a file at a temporary local path, then store it
     * on the default disk at $path.
     *
     * @param  callable(string): void  $writer
     */
    public static function writeFromLocalPath(string $path, callable $writer): void
    {
        $temporaryPath = self::temporaryPath();

        try {
            $writer($temporaryPath);

            $stream = fopen($temporaryPath, 'rb');
            $stored = Storage::disk()->put($path, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }

            if (! $stored) {
                throw new RuntimeException('The generated file could not be stored.');
            }
        } finally {
            @unlink($temporaryPath);
        }
    }

    private static function temporaryPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'lq-');

        if ($path === false) {
            throw new RuntimeException('A temporary file could not be created.');
        }

        return $path;
    }
}
