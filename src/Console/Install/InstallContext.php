<?php

namespace Kukux\DigitalSignature\Console\Install;

use Illuminate\Console\Command;

/**
 * Everything a step needs from the run: where the app lives, the flags, and a
 * way to ask the user. Steps never read `$command->option()` themselves, so a
 * test can drive one with a hand-built context.
 */
final class InstallContext
{
    /** @var list<string> Shown under "Next:" when the install finishes. */
    public array $next = [];

    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public readonly Command $command,
        public readonly string $basePath,
        public readonly array $options = [],
    ) {}

    public function path(string $relative = ''): string
    {
        return rtrim($this->basePath, '/').($relative === '' ? '' : '/'.ltrim($relative, '/'));
    }

    /** Path relative to the app, for log lines. */
    public function relative(string $absolute): string
    {
        $base = rtrim($this->basePath, '/').'/';

        return str_starts_with($absolute, $base) ? substr($absolute, strlen($base)) : $absolute;
    }

    public function option(string $name, mixed $default = null): mixed
    {
        return $this->options[$name] ?? $default;
    }

    public function dryRun(): bool
    {
        return (bool) $this->option('dry-run', false);
    }

    public function force(): bool
    {
        return (bool) $this->option('force', false);
    }

    public function interactive(): bool
    {
        return (bool) $this->option('interactive', false);
    }

    /**
     * Ask yes/no. Without a terminal (`-n`, CI) the answer is $whenNonInteractive.
     */
    public function confirm(string $question, bool $default = true, bool $whenNonInteractive = true): bool
    {
        if (! $this->interactive()) {
            return $whenNonInteractive;
        }

        return $this->command->confirm($question, $default);
    }

    /**
     * Pick several. Without a terminal, every option is picked.
     *
     * @param  array<string, string>  $options  value => label
     * @return list<string>  picked values
     */
    public function pickMany(string $question, array $options): array
    {
        if (! $this->interactive() || count($options) < 2) {
            return array_keys($options);
        }

        $labels = array_values($options);
        $picked = (array) $this->command->choice($question, $labels, implode(',', array_keys($labels)), null, true);

        return array_values(array_keys(array_intersect($options, $picked)));
    }

    /**
     * Run another Artisan command without its output. Returns its exit code.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function call(string $name, array $arguments = []): int
    {
        return $this->command->callSilently($name, $arguments);
    }

    public function isProduction(): bool
    {
        return $this->command->getLaravel()->environment() === 'production';
    }

    public function line(string $text): void
    {
        $this->command->line($text);
    }
}
