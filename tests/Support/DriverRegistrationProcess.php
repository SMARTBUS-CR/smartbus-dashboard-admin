<?php

namespace Tests\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

final class DriverRegistrationProcess
{
    private Process $process;

    /** @var resource */
    private $socket;

    /** @param array<string, mixed> $input */
    public function __construct(array $input)
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);

        if ($server === false) {
            throw new RuntimeException('Cannot open concurrency barrier: '.$errorMessage);
        }

        try {
            $input['barrier'] = stream_socket_get_name($server, false);
            $this->process = new Process([
                PHP_BINARY, '-d', 'opcache.enable_cli=0', '-d', 'memory_limit=128M',
                __DIR__.'/driver-registration-worker.php',
            ], dirname(__DIR__, 2), ['APP_ENV' => 'testing'], json_encode($input, JSON_THROW_ON_ERROR), 45);
            $this->process->start();
            $socket = stream_socket_accept($server, 20);

            if ($socket === false) {
                $this->process->stop(0);
                throw new RuntimeException('Worker did not connect: '.$this->process->getErrorOutput().$this->process->getOutput());
            }

            $this->socket = $socket;
            stream_set_timeout($this->socket, 20);
        } finally {
            fclose($server);
        }
    }

    /** @return array<string, mixed> */
    public function await(string $event): array
    {
        $line = fgets($this->socket);

        if ($line === false) {
            throw new RuntimeException('Worker stopped or barrier timed out: '.$this->process->getErrorOutput().$this->process->getOutput());
        }

        $message = json_decode($line, true, flags: JSON_THROW_ON_ERROR);

        if (($message['event'] ?? null) !== $event) {
            throw new RuntimeException('Unexpected worker result: '.$line);
        }

        return $message;
    }

    public function release(): void
    {
        fwrite($this->socket, "continue\n");
        fflush($this->socket);
    }

    public function close(): void
    {
        if ($this->process->isRunning()) {
            $this->process->stop(0);
        }

        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
    }
}
