<?php

/**
 * The docs' PHP snippets name real things.
 *
 * Snippets drift: a class moves, a method is renamed, a named argument
 * changes, and the docs keep teaching the old one. Twelve such drifts were
 * found by reading before 2.0. This reads every ```php block in the docs,
 * README and UPGRADE, and for every package class it can identify (imported
 * with `use Kukux\…` or written fully qualified) checks that:
 *
 *  - the class, interface, trait or enum exists;
 *  - `Class::method(` exists on it;
 *  - each `->method(` chained onto `Class::make(…)` exists on it;
 *  - each named argument in `new Class(…)` / `Class::method(…)` is a real parameter.
 *
 * App classes in the snippets (App\…, placeholders) are not checked: they're
 * the reader's to write.
 */

/** @return array<string, list<string>> file => php blocks */
function docsPhpBlocks(): array
{
    $root = dirname(__DIR__, 2);

    $files = array_merge(
        glob($root.'/docs/*.md') ?: [],
        glob($root.'/docs/*/*.md') ?: [],
        glob($root.'/docs/*/*/*.md') ?: [],
        [$root.'/README.md', $root.'/UPGRADE-2.0.md'],
    );

    $blocks = [];

    foreach ($files as $file) {
        preg_match_all('/```php\n(.*?)```/s', (string) file_get_contents($file), $m);
        $blocks[str_replace($root.'/', '', $file)] = $m[1];
    }

    return $blocks;
}

/** @return array<string, string> short name => FQCN, from `use Kukux\…;` lines */
function docsImports(string $code): array
{
    preg_match_all('/^use\s+(Kukux\\\\DigitalSignature\\\\[\w\\\\]+)(?:\s+as\s+(\w+))?;/m', $code, $m, PREG_SET_ORDER);

    $imports = [];

    foreach ($m as $match) {
        $imports[$match[2] ?? '' ?: class_basename($match[1])] = $match[1];
    }

    return $imports;
}

function docsTypeExists(string $fqcn): bool
{
    return class_exists($fqcn) || interface_exists($fqcn) || trait_exists($fqcn) || enum_exists($fqcn);
}

/** The argument list starting at $offset (just after the opening paren), balanced. */
function docsArgumentList(string $code, int $offset): string
{
    $depth = 1;

    for ($i = $offset, $n = strlen($code); $i < $n; $i++) {
        $c = $code[$i];

        if ($c === '(' || $c === '[') {
            $depth++;
        } elseif ($c === ')' || $c === ']') {
            $depth--;

            if ($depth === 0) {
                return substr($code, $offset, $i - $offset);
            }
        }
    }

    return substr($code, $offset);
}

/** Named arguments at the top level of an argument list. */
function docsNamedArguments(string $arguments): array
{
    $names = [];
    $depth = 0;
    $token = '';

    foreach (str_split($arguments.',') as $c) {
        if (in_array($c, ['(', '[', '{'], true)) {
            $depth++;
        } elseif (in_array($c, [')', ']', '}'], true)) {
            $depth--;
        }

        if ($c === ',' && $depth === 0) {
            if (preg_match('/^\s*(\w+)\s*:(?!:)/', $token, $m)) {
                $names[] = $m[1];
            }

            $token = '';

            continue;
        }

        $token .= $c;
    }

    return $names;
}

/** Eloquent forwards statics to its query builder and to `scope…` methods. */
function docsCallable(string $class, string $method): bool
{
    if (method_exists($class, $method)) {
        return true;
    }

    if (! is_subclass_of($class, \Illuminate\Database\Eloquent\Model::class)) {
        return false;
    }

    return method_exists($class, 'scope'.ucfirst($method))
        || method_exists(\Illuminate\Database\Eloquent\Builder::class, $method)
        || method_exists(\Illuminate\Database\Query\Builder::class, $method);
}

/** The expression starting at $offset: up to a top-level `,` or `;`, or the bracket that closes it. */
function docsExpression(string $code, int $offset): string
{
    $depth = 0;

    for ($i = $offset, $n = strlen($code); $i < $n; $i++) {
        $c = $code[$i];

        if ($c === '(' || $c === '[' || $c === '{') {
            $depth++;
        } elseif ($c === ')' || $c === ']' || $c === '}') {
            if ($depth === 0) {
                return substr($code, $offset, $i - $offset);
            }

            $depth--;
        } elseif (($c === ',' || $c === ';') && $depth === 0) {
            return substr($code, $offset, $i - $offset);
        }
    }

    return substr($code, $offset);
}

function docsParameterNames(string $class, string $method): ?array
{
    if (! method_exists($class, $method)) {
        return docsCallable($class, $method) ? [] : null;
    }

    return array_map(fn (ReflectionParameter $p) => $p->getName(), (new ReflectionMethod($class, $method))->getParameters());
}

/**
 * @param  array<string, list<string>>  $files  file => php blocks
 * @return list<string>
 */
function docsSnippetProblems(array $files): array
{
    $problems = [];

    foreach ($files as $file => $blocks) {
        foreach ($blocks as $code) {
            $imports = docsImports($code);

            // Fully qualified references, imported or inline.
            preg_match_all('/\\\\?(Kukux\\\\DigitalSignature\\\\[\w\\\\]*\w)/', $code, $m);

            foreach (array_unique($m[1]) as $fqcn) {
                if (! docsTypeExists($fqcn) && ! docsTypeExists(rtrim($fqcn, '\\'))) {
                    // A namespace prefix in a `use` group or prose is fine.
                    if (! str_ends_with($fqcn, 'DigitalSignature')) {
                        $problems[] = "{$file}: no such class {$fqcn}";
                    }
                }
            }

            // Short names this block resolves: imported ones, and inline FQCNs.
            $known = $imports;
            foreach (array_unique($m[1]) as $fqcn) {
                if (docsTypeExists($fqcn)) {
                    $known[class_basename($fqcn)] ??= $fqcn;
                }
            }

            foreach ($known as $short => $fqcn) {
                if (! docsTypeExists($fqcn)) {
                    continue;
                }

                $q = preg_quote($short, '/');

                // new Class(named: …)
                if (preg_match_all('/(?<![\w\\\\])new\s+(?:\\\\?[\w\\\\]*\\\\)?'.$q.'\s*\(/', $code, $news, PREG_OFFSET_CAPTURE)) {
                    foreach ($news[0] as [$match, $at]) {
                        $params = docsParameterNames($fqcn, '__construct') ?? [];

                        foreach (docsNamedArguments(docsArgumentList($code, $at + strlen($match))) as $name) {
                            if (! in_array($name, $params, true)) {
                                $problems[] = "{$file}: new {$short}(…) has no parameter \${$name}";
                            }
                        }
                    }
                }

                // Class::method(named: …), and the chain after Class::make(…)
                if (preg_match_all('/(?<![\w\\\\])(?:\\\\?[\w\\\\]*\\\\)?'.$q.'::(\w+)\s*\(/', $code, $calls, PREG_OFFSET_CAPTURE)) {
                    foreach ($calls[1] as $i => [$method, $at]) {
                        if ($method === 'class') {
                            continue;
                        }

                        $params = docsParameterNames($fqcn, $method);

                        if ($params === null) {
                            $problems[] = "{$file}: {$short}::{$method}() does not exist";

                            continue;
                        }

                        $open = $calls[0][$i][1] + strlen($calls[0][$i][0]);

                        foreach (docsNamedArguments(docsArgumentList($code, $open)) as $name) {
                            // [] = forwarded by Eloquent: names can't be checked.
                            if ($params !== [] && ! in_array($name, $params, true)) {
                                $problems[] = "{$file}: {$short}::{$method}(…) has no parameter \${$name}";
                            }
                        }

                        if ($method !== 'make') {
                            continue;
                        }

                        // The fluent chain: `->method(` at the start of a line or
                        // straight after a closing paren, within this expression.
                        $statement = docsExpression($code, $calls[0][$i][1]);

                        preg_match_all('/(?:^\s*|\))->(\w+)\(/m', $statement, $chain);

                        foreach ($chain[1] as $link) {
                            if (! method_exists($fqcn, $link)) {
                                $problems[] = "{$file}: {$short}::make()->{$link}() does not exist";
                            }
                        }
                    }
                }
            }
        }
    }

    return array_values(array_unique($problems));
}

it('names only package classes, methods and arguments that exist', function () {
    expect(docsSnippetProblems(docsPhpBlocks()))->toBe([]);
});

it('catches the drift it exists for', function () {
    $problems = docsSnippetProblems(['example.md' => [<<<'PHP'
        use Kukux\DigitalSignature\Filament\SignatoryPanel;
        use Kukux\DigitalSignature\Pdf\SlotDefinition;
        use Kukux\DigitalSignature\Filament\Actions\RouteForSignaturesAction;

        new SlotDefinition(key: 'a', label: 'A', signer: 'b');

        RouteForSignaturesAction::make()
            ->document('dtr')
            ->routeLater();
        PHP]]);

    expect($problems)->toBe([
        'example.md: no such class Kukux\DigitalSignature\Filament\SignatoryPanel',
        'example.md: new SlotDefinition(…) has no parameter $signer',
        'example.md: RouteForSignaturesAction::make()->routeLater() does not exist',
    ]);
});
