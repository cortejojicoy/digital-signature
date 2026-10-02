<?php

namespace Kukux\DigitalSignature\Console\Install;

/**
 * Writes an edit to one of the user's PHP files, carefully: show what's being
 * added, ask, write, lint, and put the original back if the lint fails.
 */
final class PhpFileEditor
{
    public const WRITTEN = 'written';

    public const DECLINED = 'declined';

    public const LINT_FAILED = 'lint-failed';

    public const DRY_RUN = 'dry-run';

    public function __construct(private readonly InstallContext $context) {}

    public function write(string $path, string $updated): string
    {
        $original = (string) file_get_contents($path);
        $relative = $this->context->relative($path);

        if ($this->context->dryRun()) {
            return self::DRY_RUN;
        }

        // Editing code unattended is the one thing -n doesn't say yes to.
        if (! $this->context->interactive() && ! $this->context->force()) {
            return self::DECLINED;
        }

        if ($this->context->interactive()) {
            $this->context->line('');
            $this->context->line("  <options=bold>{$relative}</>");

            foreach (self::addedLines($original, $updated) as [$number, $line]) {
                $this->context->line(sprintf('  <fg=green>%4d + %s</>', $number, $line));
            }

            $question = $this->hasUncommittedChanges($path)
                ? "{$relative} has uncommitted changes. Apply this edit anyway?"
                : "Apply this edit to {$relative}?";

            if (! $this->context->confirm($question)) {
                return self::DECLINED;
            }
        }

        file_put_contents($path, $updated);

        if (! self::lints($path)) {
            file_put_contents($path, $original);

            return self::LINT_FAILED;
        }

        return self::WRITTEN;
    }

    public static function lints(string $path): bool
    {
        exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($path).' 2>&1', $output, $code);

        return $code === 0;
    }

    /**
     * Lines present in $updated but not $original, with their new line number.
     * Edits here only ever insert, so a line-level LCS is all it takes.
     *
     * @return list<array{0: int, 1: string}>
     */
    public static function addedLines(string $original, string $updated): array
    {
        $a = explode("\n", $original);
        $b = explode("\n", $updated);
        $n = count($a);
        $m = count($b);

        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));

        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $a[$i] === $b[$j]
                    ? $lcs[$i + 1][$j + 1] + 1
                    : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }

        $added = [];
        $i = $j = 0;

        while ($j < $m) {
            if ($i < $n && $a[$i] === $b[$j]) {
                $i++;
                $j++;
            } elseif ($i < $n && $lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                $i++;
            } else {
                $added[] = [$j + 1, $b[$j]];
                $j++;
            }
        }

        return $added;
    }

    private function hasUncommittedChanges(string $path): bool
    {
        if (! is_dir($this->context->path('.git'))) {
            return false;
        }

        exec(
            'git -C '.escapeshellarg($this->context->basePath).' status --porcelain -- '.escapeshellarg($path).' 2>/dev/null',
            $output,
        );

        return $output !== [];
    }
}
