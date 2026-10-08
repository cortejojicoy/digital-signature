<?php

namespace Kukux\DigitalSignature\Drivers\PdfSigners;

use setasign\Fpdi\TcpdfFpdi;
use TCPDF_STATIC;

/**
 * TCPDF + FPDI that lays out a signature but does not sign.
 *
 * TCPDF's own signing lives inside Output(): it writes the signature
 * dictionary with a zero-filled /Contents, fills in /ByteRange, then runs
 * openssl_pkcs7_sign() with a local key. Hash-only signing needs everything
 * up to that last step and nothing after it, so outputWithReservedSignature()
 * repeats Output()'s layout arithmetic and stops there — no throwaway key, no
 * PKCS#7 temp files to parse. The document TCPDF writes is otherwise
 * byte-for-byte what FpdiDriver produces.
 *
 * Coupled to three TCPDF internals, all stable since 5.x: the public
 * TCPDF_STATIC::$byterange_string marker, `$signature_max_length` and
 * getBuffer(). The round-trip tests break if a TCPDF upgrade moves them.
 */
class ReservedSignatureTcpdf extends TcpdfFpdi
{
    /**
     * Hex characters reserved for the CMS. Must be set before the document is
     * closed: _putsignature() writes the placeholder at that point.
     */
    public function reserveSignatureSpace(int $hexChars): static
    {
        if ($hexChars < 2 || $hexChars % 2 !== 0) {
            throw new \InvalidArgumentException('The signature placeholder must be a positive, even number of hex characters.');
        }

        if ($this->state >= 3) {
            throw new \LogicException('The document is already closed; reserve the signature space before writing it.');
        }

        $this->signature_max_length = $hexChars;

        return $this;
    }

    public function reservedSignatureLength(): int
    {
        return $this->signature_max_length;
    }

    /**
     * Turn on TCPDF's signature layout without a key.
     *
     * setSignature() only stores the certificate and key until Output() needs
     * them, which this class never lets happen, so a marker string stands in.
     *
     * @param  int  $certType  DocMDP P value (1–3), or 0 for UR3 — as setSignature().
     * @param  array<string, string>  $info  Name, Location, Reason, ContactInfo.
     */
    public function reserveSignature(int $certType = 2, array $info = []): static
    {
        $this->setSignature('deferred', 'deferred', '', '', $certType, $info);

        return $this;
    }

    /**
     * Output() would try to sign with the stand-in key; a document with a
     * reserved signature is only ever written by outputWithReservedSignature().
     */
    public function Output($name = 'doc.pdf', $dest = 'I')
    {
        if ($this->sign) {
            throw new \LogicException('This document reserves a signature; use outputWithReservedSignature().');
        }

        return parent::Output($name, $dest);
    }

    /**
     * Close the document and return its bytes with /ByteRange filled in and
     * /Contents left as zeros.
     *
     * @return array{bytes: string, byteRange: array{0:int,1:int,2:int,3:int}}
     */
    public function outputWithReservedSignature(): array
    {
        if (! $this->sign) {
            throw new \LogicException('setSignature() must be called first: it is what makes TCPDF write the signature dictionary.');
        }

        if ($this->state < 3) {
            $this->Close();
        }

        // Same as Output(): the trailing newline after %%EOF is dropped, and
        // the byte range is computed on what is left.
        $doc    = substr($this->getBuffer(), 0, -1);
        $marker = TCPDF_STATIC::$byterange_string;
        $at     = strpos($doc, $marker);

        if ($at === false) {
            throw new \RuntimeException('TCPDF did not write a /ByteRange placeholder.');
        }

        // The marker is followed by ' /Contents<': +10 lands on the '<', and
        // the excluded gap runs through the closing '>'.
        $gapStart = $at + strlen($marker) + 10;
        $gapEnd   = $gapStart + $this->signature_max_length + 2;

        if (substr($doc, $gapStart, 1) !== '<' || substr($doc, $gapEnd - 1, 1) !== '>') {
            throw new \RuntimeException('Unexpected /Contents layout; this TCPDF version is not supported.');
        }

        $byteRange = [0, $gapStart, $gapEnd, strlen($doc) - $gapEnd];

        // Padded with spaces to the marker's length, so no offset moves.
        $filled = sprintf('/ByteRange[0 %u %u %u]', $byteRange[1], $byteRange[2], $byteRange[3]);
        $filled = str_pad($filled, strlen($marker), ' ');

        return [
            'bytes'     => substr_replace($doc, $filled, $at, strlen($marker)),
            'byteRange' => $byteRange,
        ];
    }
}
