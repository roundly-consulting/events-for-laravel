<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Tests\Install;

use Illuminate\Support\Facades\File;
use RoundlyConsulting\Events\Tests\TestCase;

/**
 * `events:install` publishes into a throwaway config and database directory, never the shared
 * Testbench skeleton, and runs without the package's tables (as a fresh app would).
 */
abstract class InstallTestCase extends TestCase
{
    private string $sandbox = '';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $this->sandbox = sys_get_temp_dir().'/events-install-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->sandbox.'/config');
        File::ensureDirectoryExists($this->sandbox.'/database/migrations');

        $app->useConfigPath($this->sandbox.'/config');
        $app->useDatabasePath($this->sandbox.'/database');
    }

    /**
     * @return list<string>
     */
    protected function migrationSources(): array
    {
        return [];
    }

    protected function keepsMigratedSchema(): bool
    {
        return false;
    }

    protected function sandbox(): string
    {
        return $this->sandbox;
    }

    protected function tearDown(): void
    {
        if ($this->sandbox !== '') {
            File::deleteDirectory($this->sandbox);
        }

        parent::tearDown();
    }
}
