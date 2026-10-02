<?php

namespace Kukux\DigitalSignature\Console\Install;

/**
 * Reads and appends to a .env file without reformatting it. It never rewrites
 * or removes an existing line: new keys go in one block at the end.
 */
final class EnvFile
{
    private string $contents;

    public function __construct(public readonly string $path)
    {
        $this->contents = is_file($path) ? (string) file_get_contents($path) : '';
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    public function has(string $key): bool
    {
        return preg_match('/^\s*(?:export\s+)?'.preg_quote($key, '/').'\s*=/m', $this->contents) === 1;
    }

    /** The raw value of $key, without quotes, or null if it's not set. */
    public function get(string $key): ?string
    {
        if (! preg_match('/^\s*(?:export\s+)?'.preg_quote($key, '/').'\s*=(.*)$/m', $this->contents, $m)) {
            return null;
        }

        $value = trim((string) preg_replace('/\s+#.*$/', '', $m[1]));

        return trim($value, '"\'');
    }

    /**
     * The block of lines that would be appended for the missing keys, grouped
     * under their comments. Empty when every key is already there.
     *
     * @param  list<array{comment: list<string>, keys: array<string, string>}>  $groups
     */
    public function missingBlock(array $groups, string $heading): string
    {
        $lines = [];

        foreach ($groups as $group) {
            $missing = array_filter(
                $group['keys'],
                fn (string $key) => ! $this->has($key),
                ARRAY_FILTER_USE_KEY,
            );

            if ($missing === []) {
                continue;
            }

            $lines[] = '';

            foreach ($group['comment'] as $comment) {
                $lines[] = '# '.$comment;
            }

            foreach ($missing as $key => $value) {
                $lines[] = "{$key}={$value}";
            }
        }

        if ($lines === []) {
            return '';
        }

        return "\n".$heading."\n".implode("\n", array_slice($lines, 1))."\n";
    }

    public function append(string $block): void
    {
        $separator = $this->contents === '' || str_ends_with($this->contents, "\n") ? '' : "\n";

        $this->contents .= $separator.$block;
        file_put_contents($this->path, $this->contents);
    }
}
