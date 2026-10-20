<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

it('publishes the config and every migration, then prints the next steps', function (): void {
    expect(Artisan::call('events:install'))->toBe(0);
    $output = Artisan::output();

    $migrations = array_map('basename', glob($this->sandbox().'/database/migrations/*.php') ?: []);

    expect(is_file($this->sandbox().'/config/events.php'))->toBeTrue()
        ->and(count(array_filter($migrations, static fn (string $file): bool => str_contains($file, 'event'))))->toBeGreaterThanOrEqual(7)
        ->and($output)->toContain('events.primary_key_type')
        ->and($output)->toContain("Schedule::command('events:sweep')->everyMinute();")
        ->and($output)->toContain('events:doctor');

    // Re-running is safe: nothing is overwritten.
    file_put_contents($this->sandbox().'/config/events.php', "<?php\n\nreturn ['kept' => true];\n");
    expect(Artisan::call('events:install'))->toBe(0)
        ->and((string) file_get_contents($this->sandbox().'/config/events.php'))->toContain('kept');
});

it('publishes sluggable history migrations when slug history is on', function (): void {
    config()->set('events.slugs.history', 'on');

    expect(Artisan::call('events:install'))->toBe(0)
        ->and(Artisan::output())->toContain('sluggable-migrations');
});

test('install_reruns_after_migrate', function (): void {
    expect(Artisan::call('events:install'))->toBe(0)
        ->and(Artisan::call('migrate', ['--force' => true]))->toBe(0)
        ->and(Schema::hasTable('events'))->toBeTrue();

    // Run again later — to publish slug history's migrations, say: the tables are the package's own.
    config()->set('events.slugs.history', 'on');

    expect(Artisan::call('events:install'))->toBe(0)
        ->and(Artisan::output())->toContain('sluggable-migrations')
        ->and(glob($this->sandbox().'/database/migrations/*_create_slug_history_table.php') ?: [])->not->toBeEmpty();
});

test('install_still_refuses_a_table_of_the_host', function (): void {
    // The host's own `events` table: installing would collide with it.
    Schema::create('events', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });

    expect(Artisan::call('events:install'))->toBe(1)
        ->and(Artisan::output())->toContain('The table [events] already exists');
});
