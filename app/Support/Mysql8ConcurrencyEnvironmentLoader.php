<?php

declare(strict_types=1);

namespace App\Support;

use Dotenv\Dotenv;
use Dotenv\Exception\InvalidFileException;
use Dotenv\Exception\InvalidPathException;
use Dotenv\Repository\RepositoryBuilder;
use RuntimeException;

/**
 * Loads the isolated MySQL 8 concurrency-test environment
 * (docker-compose.mysql8.yml) from its own local, gitignored file -
 * entirely separate from the application's real .env, and never
 * merged into it.
 *
 * Structurally restricted to exactly the variable names the
 * mysql_concurrency connection (config/database.php) actually reads -
 * MYSQL8_ROOT_PASSWORD is deliberately excluded, since Laravel never
 * needs the container's root credential. Keep ALLOWED_VARIABLES in sync
 * with that connection's env() calls if either one changes.
 */
final class Mysql8ConcurrencyEnvironmentLoader
{
    private const ALLOWED_VARIABLES = [
        'MYSQL8_HOST',
        'MYSQL8_PORT',
        'MYSQL8_DATABASE',
        'MYSQL8_USERNAME',
        'MYSQL8_PASSWORD',
        'MYSQL8_ATTR_SSL_CA',
    ];

    /**
     * Load the given env file from the given directory, if it exists.
     *
     * A missing file is a safe no-op. A malformed file fails closed with a
     * generic exception that never carries the original parser message -
     * that message can embed the raw offending line, including a value.
     *
     * @throws RuntimeException when the file exists but cannot be parsed
     */
    public static function load(string $directory, string $fileName = '.env.mysql8.local'): void
    {
        if (! is_file($directory.DIRECTORY_SEPARATOR.$fileName)) {
            return;
        }

        $repository = RepositoryBuilder::createWithDefaultAdapters()
            ->immutable()
            ->allowList(self::ALLOWED_VARIABLES)
            ->make();

        try {
            Dotenv::create($repository, $directory, $fileName)->load();
        } catch (InvalidPathException) {
            // Vanished between the is_file() check and the read - treat
            // exactly like "missing", not an error.
            return;
        } catch (InvalidFileException) {
            // Deliberately not chained as a previous exception: its own
            // message embeds the raw offending line, which can include a
            // credential value.
            throw new RuntimeException('The local MySQL 8 environment file is invalid.');
        }
    }
}
