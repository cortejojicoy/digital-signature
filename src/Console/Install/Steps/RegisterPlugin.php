<?php

namespace Kukux\DigitalSignature\Console\Install\Steps;

use Kukux\DigitalSignature\Console\Install\InstallContext;
use Kukux\DigitalSignature\Console\Install\InstallStep;
use Kukux\DigitalSignature\Console\Install\PhpFileEditor;
use Kukux\DigitalSignature\Console\Install\StepResult;

/**
 * Adds SignaturePlugin::make() to the app's Filament panel providers.
 *
 * Edits only a shape it recognises: one `->plugins([` call, or a single
 * `return $panel` chain to start one on. Anything else gets the snippet to
 * paste instead of a guess.
 */
class RegisterPlugin implements InstallStep
{
    public const IMPORT = 'use Kukux\\DigitalSignature\\SignaturePlugin;';

    public function label(): string
    {
        return 'Registering plugin';
    }

    public function summary(): string
    {
        return 'register the plugin on your Filament panels';
    }

    public function enabled(InstallContext $context): bool
    {
        return ! $context->option('no-panel');
    }

    public function run(InstallContext $context): StepResult
    {
        $providers = $this->providers($context);

        if ($providers === []) {
            return StepResult::manual(array_merge(
                ['No panel providers found in app/Providers/Filament. Add this to yours:'],
                $this->snippet(),
            ));
        }

        $todo = array_filter($providers, fn (string $path) => ! $this->registered((string) file_get_contents($path)));

        if ($todo === []) {
            return StepResult::skipped('already registered on '.$this->list($context, $providers));
        }

        $picked = $context->pickMany(
            'Register the plugin on which panels?',
            array_combine($todo, array_map(fn (string $path) => $this->panelLabel($path), $todo)),
        );

        $editor = new PhpFileEditor($context);
        $done = $manual = $planned = [];

        foreach ($picked as $path) {
            $relative = $context->relative($path);
            $updated = $this->edit((string) file_get_contents($path));

            if ($updated === null) {
                $manual[] = "{$relative}: couldn't find where to add it safely";

                continue;
            }

            match ($editor->write($path, $updated)) {
                PhpFileEditor::WRITTEN     => $done[] = $relative,
                PhpFileEditor::DRY_RUN     => $planned[] = "would add SignaturePlugin::make() to {$relative}",
                PhpFileEditor::LINT_FAILED => $manual[] = "{$relative}: the edit didn't lint, so the file was left as it was",
                default                    => $manual[] = "{$relative}: not edited",
            };
        }

        if ($context->dryRun()) {
            return StepResult::dryRun(array_merge($planned, $manual));
        }

        if ($manual !== []) {
            return StepResult::manual(array_merge(
                $done,
                $manual,
                ['Add this to the panels above:'],
                $this->snippet(),
            ));
        }

        if ($done === []) {
            return StepResult::skipped('no panels picked');
        }

        return StepResult::done($done);
    }

    /**
     * The provider with the plugin added, or null if the file isn't a shape
     * this can edit with confidence.
     */
    public function edit(string $source): ?string
    {
        if ($this->registered($source)) {
            return $source;
        }

        $calls = preg_match_all('/->plugins\(\s*\[/', $source);

        if ($calls === 1) {
            $updated = preg_replace_callback(
                '/(\n([ \t]*)->plugins\(\s*\[)([ \t]*\n([ \t]*))?/',
                function (array $m) {
                    // Match the first entry's indent when there is one,
                    // otherwise one level in from ->plugins(.
                    $indent = $m[4] ?? '';
                    $indent = $indent !== '' ? $indent : $m[2].'    ';

                    return $m[1]."\n".$indent.'SignaturePlugin::make(),'."\n".($m[4] ?? $m[2]);
                },
                $source,
                1,
            );
        } elseif ($calls === 0 && preg_match_all('/return\s+\$panel\s*\n([ \t]*)->/', $source, $m) === 1) {
            $indent = $m[1][0];

            $updated = preg_replace(
                '/(return\s+\$panel)(\s*\n)/',
                "$1\n{$indent}->plugins([\n{$indent}    SignaturePlugin::make(),\n{$indent}])$2",
                $source,
                1,
            );
        } else {
            return null;
        }

        return $updated === null ? null : $this->addImport($updated);
    }

    protected function addImport(string $source): ?string
    {
        if (str_contains($source, self::IMPORT)) {
            return $source;
        }

        // After the last top-level import, or after the namespace line.
        if (preg_match_all('/^use [^;]+;\s*$/m', $source, $m, PREG_OFFSET_CAPTURE)) {
            $last = $m[0][count($m[0]) - 1];
            $at = $last[1] + strlen(rtrim($last[0]));

            return substr($source, 0, $at)."\n".self::IMPORT.substr($source, $at);
        }

        if (preg_match('/^namespace [^;]+;\s*$/m', $source, $m, PREG_OFFSET_CAPTURE)) {
            $at = $m[0][1] + strlen(rtrim($m[0][0]));

            return substr($source, 0, $at)."\n\n".self::IMPORT.substr($source, $at);
        }

        return null;
    }

    protected function registered(string $source): bool
    {
        return str_contains($source, 'SignaturePlugin::make(')
            || str_contains($source, 'new SignaturePlugin');
    }

    /** @return list<string> */
    protected function providers(InstallContext $context): array
    {
        $files = glob($context->path('app/Providers/Filament/*PanelProvider.php')) ?: [];
        sort($files);

        $only = $context->option('panel');

        if (is_string($only) && $only !== '') {
            $files = array_values(array_filter(
                $files,
                fn (string $path) => $this->panelId((string) file_get_contents($path)) === $only,
            ));
        }

        return $files;
    }

    protected function panelId(string $source): ?string
    {
        return preg_match('/->id\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $source, $m) ? $m[1] : null;
    }

    protected function panelLabel(string $path): string
    {
        $id = $this->panelId((string) file_get_contents($path));

        return basename($path, '.php').($id !== null ? " ({$id})" : '');
    }

    /** @param  list<string>  $paths */
    protected function list(InstallContext $context, array $paths): string
    {
        return implode(', ', array_map(fn (string $path) => $context->relative($path), $paths));
    }

    /** @return list<string> */
    protected function snippet(): array
    {
        return [
            '    '.self::IMPORT,
            '    ->plugins([',
            '        SignaturePlugin::make(),',
            '    ])',
        ];
    }
}
