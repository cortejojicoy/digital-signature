<?php

namespace Kukux\DigitalSignature\Routing;

use Illuminate\Database\Eloquent\Model;
use Kukux\DigitalSignature\Models\SigningSession;

/**
 * What happened when a document was routed for signatures, and how to say it.
 *
 * The outcome is a stable `reason()` code that tests and callers branch on.
 * The words are looked up separately, so an app can change them without
 * touching code. First match wins:
 *
 *   1. an explicit title/body on the result (a guard or the document definition)
 *   2. the app's lang/vendor/signature/{locale}/routing.php
 *   3. the package's lang/en/routing.php
 *
 * Placeholders are `:name` style, as in Laravel translations.
 */
final class RoutingResult
{
    public const ROUTED = 'routed';

    public const ALREADY_ROUTED = 'already_routed';

    public const MISSING_SIGNATORIES = 'missing_signatories';

    public const MARKERS_MISSING = 'markers_missing';

    public const NOT_READY = 'not_ready';

    /**
     * @param  array<string, string>  $replace
     */
    private function __construct(
        private readonly bool $ok,
        private readonly string $reason,
        private readonly array $replace = [],
        private readonly ?Model $record = null,
        private readonly ?SigningSession $session = null,
        private readonly ?string $title = null,
        private readonly ?string $body = null,
        private readonly ?string $variant = null,
    ) {
    }

    /** @param  array<string, string>  $replace */
    public static function routed(Model $record, SigningSession $session, array $replace = []): self
    {
        return new self(
            ok: true,
            reason: self::ROUTED,
            replace: $replace,
            record: $record,
            session: $session,
            // "They'll sign in that order" is only true of a sequential session.
            variant: $session->isSequential() ? null : 'parallel',
        );
    }

    public static function alreadyRouted(Model $record, SigningSession $session): self
    {
        return new self(ok: true, reason: self::ALREADY_ROUTED, record: $record, session: $session);
    }

    /**
     * Stop routing. `$reason` is the stable code; give `$title` / `$body`
     * only when the wording can't live in a lang file.
     *
     * @param  array<string, string>  $replace
     */
    public static function refused(string $reason, array $replace = [], ?string $title = null, ?string $body = null): self
    {
        return new self(ok: false, reason: $reason, replace: $replace, title: $title, body: $body);
    }

    /**
     * Apply a document definition's wording, keyed by reason:
     * `['missing_signatories' => ['body' => 'Set :roles under "Update Signatures" first.']]`.
     * Only fills what the result doesn't already say explicitly.
     *
     * @param  array<string, array{title?: string, body?: string}>  $messages
     */
    public function withMessages(array $messages): self
    {
        $override = $messages[$this->reason] ?? [];

        if ($override === []) {
            return $this;
        }

        return new self(
            ok: $this->ok,
            reason: $this->reason,
            replace: $this->replace,
            record: $this->record,
            session: $this->session,
            title: $this->title ?? ($override['title'] ?? null),
            body: $this->body ?? ($override['body'] ?? null),
            variant: $this->variant,
        );
    }

    /** Same outcome, against the record routing actually used. */
    public function forRecord(Model $record): self
    {
        return new self(
            ok: $this->ok,
            reason: $this->reason,
            replace: $this->replace,
            record: $record,
            session: $this->session,
            title: $this->title,
            body: $this->body,
            variant: $this->variant,
        );
    }

    public function ok(): bool
    {
        return $this->ok;
    }

    public function wasRefused(): bool
    {
        return ! $this->ok;
    }

    /** True when this call opened the session, rather than finding one open. */
    public function wasRouted(): bool
    {
        return $this->reason === self::ROUTED;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    /** @return array<string, string> */
    public function replacements(): array
    {
        return $this->replace;
    }

    public function record(): ?Model
    {
        return $this->record;
    }

    public function session(): ?SigningSession
    {
        return $this->session;
    }

    public function title(): string
    {
        return $this->resolve('title', $this->title);
    }

    public function body(): string
    {
        return $this->resolve('body', $this->body);
    }

    private function resolve(string $part, ?string $explicit): string
    {
        if ($explicit !== null) {
            return self::fill($explicit, $this->replace);
        }

        $keys = $this->variant !== null
            ? ["signature::routing.{$this->reason}.{$part}_{$this->variant}", "signature::routing.{$this->reason}.{$part}"]
            : ["signature::routing.{$this->reason}.{$part}"];

        foreach ($keys as $key) {
            if (trans()->has($key)) {
                return (string) trans($key, $this->replace);
            }
        }

        // A guard's own code with no wording anywhere: still say something.
        return $part === 'title'
            ? ucfirst(str_replace(['_', '-'], ' ', $this->reason))
            : '';
    }

    /** @param  array<string, string>  $replace */
    private static function fill(string $line, array $replace): string
    {
        $pairs = [];

        foreach ($replace as $key => $value) {
            $pairs[':'.$key] = (string) $value;
        }

        // Longest first, so `:names` isn't eaten by a `:name` replacement.
        uksort($pairs, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return strtr($line, $pairs);
    }
}
