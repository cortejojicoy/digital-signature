<?php

/*
|--------------------------------------------------------------------------
| Routing a document for signatures: the default wording
|--------------------------------------------------------------------------
| Keyed by RoutingResult::reason(). Override any line app-wide by publishing
| this file (php artisan vendor:publish --tag=signature-lang), or for a single
| document from its SignableDocument::messages().
|
| `title_parallel` / `body_parallel` are used instead when the session lets
| signatories sign in any order.
*/

return [

    'routed' => [
        'title'         => 'Routed for signatures',
        'body'          => 'Sent to :names. They\'ll sign in that order.',
        'body_parallel' => 'Sent to :names. They can sign in any order.',
    ],

    'already_routed' => [
        'title' => 'Already routed',
        'body'  => 'This document is already with its signatories.',
    ],

    'missing_signatories' => [
        'title' => 'Signatories not set',
        'body'  => 'Set :roles before routing this document.',
    ],

    'markers_missing' => [
        'title' => 'Signature lines not found',
        'body'  => 'The document has no place for :slots. Mark each signature space in the view with data-signature-slot, or position it in the template designer.',
    ],

    'not_ready' => [
        'title' => 'Not ready for signatures',
        'body'  => ':blockers',
    ],

    'failed' => [
        'title' => 'Could not route this document',
        'body'  => ':error',
    ],

    /*
    |----------------------------------------------------------------------
    | Document of record
    |----------------------------------------------------------------------
    | The banner over a routed document. :signed and :total count required
    | slots; :waiting names whoever is next.
    */
    'document_of_record' => [
        'complete'    => 'Signed by all :total · :date',
        'in_progress' => ':signed of :total signed · waiting on :waiting',
        'pending'     => 'Routed for signatures · waiting on :waiting',
        'withdrawn'   => 'Routing withdrawn · showing the document as it was last signed',
        'tampered'    => 'This file no longer matches what was signed.',
    ],

];
