<?php

namespace Fanswoo\CloudTasksGuard\Tests\Support;

use Exception;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Symfony\Component\ErrorHandler\Error\FatalError;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reproduces, inside a running job, what Laravel does after a fatal error ends
 * a request: its shutdown handler reports the error to the exception handler
 * and then renders it as the response.
 *
 * @see HandleExceptions::handleException()
 *
 * A real fatal error cannot be raised inside a test process, so the end-to-end
 * tests cover it with PHP's built-in web server.
 */
final class TimeoutSimulator
{
    public const string TIMEOUT_MESSAGE = 'Maximum execution time of 1 second exceeded';

    /**
     * The response that the shutdown handler rendered.
     */
    public static ?Response $response = null;

    public static function reset(): void
    {
        self::$response = null;
    }

    public static function fatalError(string $message = self::TIMEOUT_MESSAGE): FatalError
    {
        return new FatalError($message, 0, [
            'type' => E_ERROR,
            'message' => $message,
            'file' => __FILE__,
            'line' => __LINE__,
        ]);
    }

    /**
     * Report and render a fatal error the way the shutdown handler does.
     */
    public static function shutDownWith(string $message = self::TIMEOUT_MESSAGE): void
    {
        $error = self::fatalError($message);
        $handler = app(ExceptionHandler::class);

        try {
            $handler->report($error);
        } catch (Exception) {
            // The shutdown handler goes on to render the error when reporting fails.
        }

        self::$response = $handler->render(app('request'), $error);
    }
}
