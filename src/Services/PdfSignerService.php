<?php

namespace Kukux\DigitalSignature\Services;

use Illuminate\Support\Facades\Storage;
use Kukux\DigitalSignature\Drivers\PdfSigners\Contracts\PdfSignerDriver;
use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Support\DiskPath;

class PdfSignerService
{
    public function __construct(protected PdfSignerDriver $driver) {}

    /**
     * @param  string|null  $sourcePdfPath  Disk-relative path of the PDF to sign.
     *   Multi-signatory sessions pass the session's running document so each
     *   signature lands on top of the previous one's output; without it the
     *   signable is re-rendered and every earlier stamp is lost.
     */
    public function sign(Signature $signature, array $certData, ?string $sourcePdfPath = null): string
    {
        // Every place this signature is drawn, not just the first. Falls back
        // to the singular relation so a Signature loaded the old way — or one
        // built by a host's own code — still stamps.
        $stamps = $signature->exists ? $signature->positions()->get() : collect();

        if ($stamps->isEmpty() && $signature->position) {
            $stamps = collect([$signature->position]);
        }

        $all = $stamps
            ->map(fn ($p): array => $p->only(['page', 'x', 'y', 'width', 'height']))
            ->values();

        $position = $all->first() ?? [];

        $output = $this->driver->sign(
            // Drivers read through Storage::disk()->path(), so they need the
            // disk-relative form; getSignablePdfPath() returns an absolute one.
            pdfPath:   DiskPath::relative($sourcePdfPath ?? $signature->signable->getSignablePdfPath()),
            imagePath: $signature->image_path,
            position:  $position,
            certData:  $certData,
            reason:    'Signed via '.config('app.name'),
            caption:   $this->buildCaption($signature),
            extraPositions: $all->slice(1)->values()->all(),
        );

        return $this->keepAsVersion($signature, $output);
    }

    /**
     * Keep a PDF signed some other way (client mode: the hub's CMS injected
     * by DeferredPdfSigner) as this signature's version, by the same rules
     * as sign().
     */
    public function keepSignedVersion(Signature $signature, string $output): string
    {
        return $this->keepAsVersion($signature, $output);
    }

    /**
     * Move the driver's output to a path that belongs to this signature alone.
     *
     * Each signed file is a version in the document's history: the copy that
     * signatory reviewed and signed. Drivers name their output after the
     * source and a timestamp, so two signers working from the same source in
     * the same second (a parallel session) would get the same name, and the
     * second write would replace the first signer's copy. The signature's
     * uuid can't collide, and a version that somehow exists already is
     * refused rather than overwritten.
     *
     *   {signed_docs_path}/{session uuid}/{signature uuid}.pdf   in a session
     *   {signed_docs_path}/{signature uuid}.pdf                  otherwise
     */
    protected function keepAsVersion(Signature $signature, string $output): string
    {
        if (! $signature->uuid) {
            return $output;
        }

        $disk = Storage::disk(config('signature.storage_disk'));
        $output = DiskPath::relative($output);

        // A custom or test driver that wrote nowhere we can see: leave it be.
        if (! $disk->exists($output)) {
            return $output;
        }

        $folder = rtrim((string) config('signature.signed_docs_path', 'signed-docs'), '/');
        $session = $signature->signing_session_id ? $signature->session : null;

        $target = $session?->uuid
            ? "{$folder}/{$session->uuid}/{$signature->uuid}.pdf"
            : "{$folder}/{$signature->uuid}.pdf";

        if ($target === $output) {
            return $output;
        }

        if ($disk->exists($target)) {
            throw new \RuntimeException(sprintf(
                'Refusing to overwrite the signed version at [%s]. A signed document is never replaced.',
                $target,
            ));
        }

        $disk->move($output, $target);

        return $target;
    }

    /**
     * The provenance a person can actually read.
     *
     * Built by SignatureCaption, shared with the placement UI so the box a
     * signatory aligns against the form holds the text that actually prints.
     *
     * @return array<int, string>
     */
    private function buildCaption(Signature $signature): array
    {
        return app(SignatureCaption::class)->linesFor($signature);
    }
}
