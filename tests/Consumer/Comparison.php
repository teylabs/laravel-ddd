<?php

namespace Tey\LaravelDDD\Tests\Consumer;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Symfony\Component\Process\Process;
use ZipArchive;

final class Comparison
{
    public const BASELINE = '5710dea91808f4b786a89892e7206d38d7045901';

    public static function archive(string $destination): void
    {
        $zipPath = $destination.'.zip';
        $process = new Process(['git', 'archive', '--format=zip', '--output='.$zipPath, self::BASELINE], dirname(__DIR__, 2));
        $process->mustRun();

        $zip = new ZipArchive;
        $opened = false;

        try {
            if ($zip->open($zipPath) !== true) {
                throw new RuntimeException('Cannot open frozen consumer baseline archive.');
            }
            $opened = true;

            if (! $zip->extractTo($destination)) {
                throw new RuntimeException('Cannot extract frozen consumer baseline.');
            }
        } finally {
            if ($opened) {
                $zip->close();
            }
            (new Filesystem)->delete($zipPath);
        }
    }

    public static function capture(string $packageRoot): array
    {
        $process = new Process([PHP_BINARY, __DIR__.'/capture.php', $packageRoot], dirname(__DIR__, 2));
        $process->setTimeout(120)->mustRun();

        try {
            return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('Consumer capture returned invalid JSON: '.$process->getOutput().$process->getErrorOutput(), previous: $exception);
        }
    }
}
