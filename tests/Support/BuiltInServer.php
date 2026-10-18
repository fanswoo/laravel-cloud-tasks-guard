<?php

namespace Fanswoo\CloudTasksGuard\Tests\Support;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Queue\Failed\FileFailedJobProvider;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Runs tests/EndToEnd/server.php with PHP's built-in web server.
 */
final class BuiltInServer
{
    private function __construct(
        private readonly Process $process,
        public readonly int $port,
        public readonly string $stateDirectory,
    ) {}

    /**
     * Start the server and wait until it accepts connections.
     *
     * @param  array<string, string>  $environment
     */
    public static function start(array $environment = []): self
    {
        $stateDirectory = sys_get_temp_dir().'/cloud-tasks-guard-e2e-'.bin2hex(random_bytes(8));

        if (! mkdir($stateDirectory, 0777, true) && ! is_dir($stateDirectory)) {
            throw new RuntimeException("Unable to create [{$stateDirectory}].");
        }

        $port = self::freePort();

        $process = new Process(
            command: [
                PHP_BINARY,
                // As on a production server, PHP does not print errors into the response.
                '-d', 'display_errors=0',
                '-S', "127.0.0.1:{$port}",
                __DIR__.'/../EndToEnd/server.php',
            ],
            env: ['CLOUD_TASKS_GUARD_E2E_STATE' => $stateDirectory, ...$environment],
            timeout: null,
        );

        $process->start();

        $server = new self($process, $port, $stateDirectory);
        $server->waitUntilReady();

        return $server;
    }

    public function url(string $path): string
    {
        return "http://127.0.0.1:{$this->port}/".ltrim($path, '/');
    }

    public function stop(): void
    {
        $this->process->stop(1);

        (new Filesystem)->deleteDirectory($this->stateDirectory);
    }

    /**
     * The lines that the job wrote to its log.
     *
     * @return list<string>
     */
    public function jobLog(): array
    {
        return $this->lines('job.log');
    }

    /**
     * The payloads of the tasks that the application created.
     *
     * @return list<array<string, mixed>>
     */
    public function createdTaskPayloads(): array
    {
        return array_map(
            fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            $this->lines('created-tasks.log'),
        );
    }

    /**
     * The jobs that the application wrote to its failed job store.
     *
     * @return array<int, object>
     */
    public function failedJobs(): array
    {
        return (new FileFailedJobProvider($this->stateDirectory.'/failed-jobs.json'))->all();
    }

    /**
     * The server output and the application log, to explain a failed test.
     */
    public function diagnostics(): string
    {
        $log = is_file($this->stateDirectory.'/laravel.log')
            ? (string) file_get_contents($this->stateDirectory.'/laravel.log')
            : '';

        return "Server output:\n".$this->process->getOutput().$this->process->getErrorOutput()."\nApplication log:\n".$log;
    }

    /**
     * @return list<string>
     */
    private function lines(string $file): array
    {
        $path = $this->stateDirectory.'/'.$file;

        if (! is_file($path)) {
            return [];
        }

        return array_values(array_filter(explode(PHP_EOL, (string) file_get_contents($path))));
    }

    private function waitUntilReady(): void
    {
        $giveUpAt = microtime(true) + 10;

        while (microtime(true) < $giveUpAt) {
            $connection = @fsockopen('127.0.0.1', $this->port, $errorCode, $errorMessage, 0.1);

            if ($connection !== false) {
                fclose($connection);

                return;
            }

            if (! $this->process->isRunning()) {
                break;
            }

            usleep(50_000);
        }

        $diagnostics = $this->diagnostics();

        $this->stop();

        throw new RuntimeException("The built-in server did not start.\n".$diagnostics);
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);

        if ($socket === false) {
            throw new RuntimeException("Unable to find a free port: {$errorMessage}");
        }

        $address = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($address, strrpos($address, ':') + 1);
    }
}
