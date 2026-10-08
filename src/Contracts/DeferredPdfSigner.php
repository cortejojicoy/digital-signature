<?php

namespace Kukux\DigitalSignature\Contracts;

use Kukux\DigitalSignature\Drivers\PdfSigners\PreparedPdf;

/**
 * Client mode, hash-only signing in two steps:
 *
 *   prepare()  stamp the image, reserve an empty signature (/Contents
 *              placeholder + /ByteRange), and return the digest to sign;
 *   inject()   put the CMS the hub returned into the placeholder.
 *
 * Implemented by Drivers\PdfSigners\DeferredPdfSigner.
 */
interface DeferredPdfSigner
{
    /**
     * @param  string  $pdfPath  Disk-relative path on signature.storage_disk.
     * @param  string  $imagePath  Path of the stamp image on $imageDisk.
     * @param  array{page?:int,x?:float,y?:float,width?:float,height?:float}  $position
     * @param  array<int, string>  $caption
     * @param  array<int, array{page?:int,x?:float,y?:float,width?:float,height?:float}>  $extraPositions
     * @param  string|null  $imageDisk  Disk holding the image; null = storage_disk.
     */
    public function prepare(
        string $pdfPath,
        string $imagePath,
        array $position,
        string $reason,
        array $caption = [],
        array $extraPositions = [],
        ?string $imageDisk = null,
    ): PreparedPdf;

    /**
     * @param  string  $preparedPath  PreparedPdf::$path.
     * @param  string  $cmsDer  DER ContentInfo from DigestSigner.
     * @return string  Disk-relative path of the signed PDF.
     */
    public function inject(string $preparedPath, string $cmsDer): string;
}
