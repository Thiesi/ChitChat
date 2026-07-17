<?php

declare(strict_types=1);

namespace ChitChat\Backup;

final class ProcessRunner
{
    /**
     * @param list<string> $command
     * @param array<string, string> $environment
     */
    public function run(array $command, array $environment = []): string
    {
        if ($command === []) {
            throw new BackupException('Cannot execute an empty command.');
        }

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        /** @var array<string, string>|false $inherited */
        $inherited = getenv();
        if ($inherited === false) {
            $inherited = [];
        }

        /** @var array<int, resource> $pipes */
        $pipes = [];
        $process = proc_open(
            $command,
            $descriptorSpec,
            $pipes,
            null,
            array_merge($inherited, $environment),
            ['bypass_shell' => true],
        );

        if (!is_resource($process)) {
            throw new BackupException('Unable to start command: ' . $this->displayCommand($command));
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $exitCode = null;

        while (true) {
            $status = proc_get_status($process);

            /** @var list<resource> $read */
            $read = [];
            if (!feof($pipes[1])) {
                $read[] = $pipes[1];
            }
            if (!feof($pipes[2])) {
                $read[] = $pipes[2];
            }

            if ($read !== []) {
                $write = null;
                $except = null;
                $selected = @stream_select($read, $write, $except, 0, 200_000);
                if ($selected === false) {
                    proc_terminate($process);
                    break;
                }

                foreach ($read as $stream) {
                    $chunk = stream_get_contents($stream);
                    if ($chunk === false || $chunk === '') {
                        continue;
                    }
                    if ($stream === $pipes[1]) {
                        $stdout .= $chunk;
                    } else {
                        $stderr .= $chunk;
                    }
                }
            }

            if (!$status['running']) {
                $exitCode = $status['exitcode'];
                break;
            }
        }

        $remainingStdout = stream_get_contents($pipes[1]);
        if ($remainingStdout !== false) {
            $stdout .= $remainingStdout;
        }
        $remainingStderr = stream_get_contents($pipes[2]);
        if ($remainingStderr !== false) {
            $stderr .= $remainingStderr;
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $closedExitCode = proc_close($process);
        if ($exitCode === null || $exitCode < 0) {
            $exitCode = $closedExitCode;
        }

        if ($exitCode !== 0) {
            $message = trim($stderr);
            if ($message === '') {
                $message = 'Command exited with status ' . $exitCode . '.';
            }

            throw new BackupException(sprintf(
                "Command failed: %s\n%s",
                $this->displayCommand($command),
                $message,
            ));
        }

        return $stdout;
    }

    /** @param list<string> $command */
    private function displayCommand(array $command): string
    {
        return implode(' ', array_map(
            static fn (string $argument): string => escapeshellarg($argument),
            $command,
        ));
    }
}
