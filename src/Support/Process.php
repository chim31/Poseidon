<?php

declare(strict_types=1);

namespace Poseidon\Support;

/**
 * Exécution d'un binaire externe sans shell, avec délai maximal :
 * un fichier qui fait « tourner en rond » l'outil est interrompu.
 */
final class Process
{
    /**
     * @param list<string> $command
     * @return array{code: int, stdout: string, stderr: string, timedOut: bool}
     */
    public static function run(array $command, int $timeoutSeconds = 30): array
    {
        $proc = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return ['code' => -1, 'stdout' => '', 'stderr' => 'Lancement impossible.', 'timedOut' => false];
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $timeoutSeconds;
        $timedOut = false;

        while (true) {
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            $left = $deadline - microtime(true);
            if ($left <= 0) {
                $timedOut = true;
                break;
            }
            $ready = @stream_select($read, $write, $except, (int) $left, (int) (fmod($left, 1) * 1e6));
            if ($ready === false) {
                break;
            }
            foreach ($read as $stream) {
                $chunk = fread($stream, 65536);
                if ($stream === $pipes[1]) {
                    $stdout .= (string) $chunk;
                } else {
                    $stderr .= (string) $chunk;
                }
            }
            if (feof($pipes[1]) && feof($pipes[2])) {
                break;
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        if ($timedOut) {
            proc_terminate($proc, 9);
            proc_close($proc);

            return ['code' => -1, 'stdout' => $stdout, 'stderr' => $stderr, 'timedOut' => true];
        }
        $code = proc_close($proc);

        return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr, 'timedOut' => false];
    }

    /** Cherche un exécutable dans le PATH (Linux, macOS, Windows). */
    public static function which(string $name): ?string
    {
        $exts = DIRECTORY_SEPARATOR === '\\' ? ['.exe', '.bat', '.cmd', ''] : [''];
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            foreach ($exts as $ext) {
                $candidate = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name . $ext;
                if ($dir !== '' && is_file($candidate) && is_executable($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }
}
