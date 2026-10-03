{{--
    One line over a routed document: what it is and where it stands.

    Inline styles rather than utility classes: this is dropped into host
    modals and pages whose compiled theme may not contain the classes, and a
    banner that silently loses its colour stops being a warning.
--}}
@php
    /** @var \Kukux\DigitalSignature\DocumentOfRecord\DocumentOfRecord $document */
    $intact = $document->current->verify();
    $tone = match (true) {
        ! $intact => ['#fef2f2', '#b91c1c', '#fecaca'],
        $document->state === \Kukux\DigitalSignature\DocumentOfRecord\DocumentState::Complete => ['#f0fdf4', '#15803d', '#bbf7d0'],
        $document->state === \Kukux\DigitalSignature\DocumentOfRecord\DocumentState::Withdrawn => ['#f4f4f5', '#3f3f46', '#e4e4e7'],
        default => ['#fffbeb', '#b45309', '#fde68a'],
    };
@endphp

<div
    role="status"
    data-document-state="{{ $document->state->value }}"
    data-document-integrity="{{ $intact ? 'verified' : 'mismatch' }}"
    style="display:flex;flex-wrap:wrap;align-items:center;gap:.5rem 1rem;margin-bottom:.75rem;padding:.625rem .875rem;border-radius:.5rem;font-size:.875rem;line-height:1.35;background:{{ $tone[0] }};color:{{ $tone[1] }};border:1px solid {{ $tone[2] }};"
>
    <span style="font-weight:600;">Document of record</span>
    <span>{{ $document->label() }}</span>

    @unless ($intact)
        <strong>{{ trans('signature::routing.document_of_record.tampered') }}</strong>
    @endunless

    <a href="{{ $document->url() }}" target="_blank" rel="noopener" style="margin-inline-start:auto;color:inherit;text-decoration:underline;">
        Open v{{ $document->current->number }}
    </a>
</div>
