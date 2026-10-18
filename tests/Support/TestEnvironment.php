<?php

namespace Fanswoo\CloudTasksGuard\Tests\Support;

use Google\Auth\Credentials\InsecureCredentials;
use Illuminate\Contracts\Config\Repository;

/**
 * The Cloud Tasks queue configuration shared by the in-process tests and the
 * application that the end-to-end tests serve with PHP's built-in web server.
 */
final class TestEnvironment
{
    public const string CONNECTION = 'cloudtasks';

    public const string PROJECT = 'guard-project';

    public const string LOCATION = 'europe-west1';

    public const string QUEUE = 'guard-queue';

    public static function configure(Repository $config): void
    {
        $config->set('queue.default', self::CONNECTION);

        $config->set('queue.connections.'.self::CONNECTION, [
            'driver' => 'cloudtasks',
            'project' => self::PROJECT,
            'location' => self::LOCATION,
            'queue' => self::QUEUE,
            'handler' => 'https://guard.test',
            'service_account_email' => 'tasks@guard-project.iam.gserviceaccount.com',
        ]);

        // The Cloud Tasks API is faked, so the client never needs real credentials.
        $config->set('cloud-tasks.client_options', [
            'credentials' => new InsecureCredentials,
        ]);
    }
}
