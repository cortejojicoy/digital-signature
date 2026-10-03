{{--
    Every version of a routed document, oldest first: who produced it, when,
    its hash, and whether the file still matches that hash.

    Rendered by DocumentHistoryEntry (infolists) and ViewDocumentHistoryAction
    (a modal). Inline styles for the reason given in the banner view.
--}}
@php
    /** @var \Kukux\DigitalSignature\DocumentOfRecord\DocumentHistory|null $history */
    $versions = $history?->versions() ?? collect();
    $sessions = $versions->groupBy(fn ($v) => $v->session->id);
@endphp

@if ($versions->isEmpty())
    <p style="font-size:.875rem;opacity:.7;">This document hasn't been routed for signatures, so it has no history yet.</p>
@else
    <div style="display:flex;flex-direction:column;gap:1rem;font-size:.875rem;">
        @foreach ($sessions as $run)
            @php $session = $run->first()->session; @endphp

            <section>
                <p style="margin:0 0 .375rem;font-size:.75rem;opacity:.7;">
                    Routed {{ $session->created_at?->format('j M Y, g:i a') }}
                    · {{ $session->status }}
                    @if ($sessions->count() > 1)
                        · session {{ \Illuminate\Support\Str::limit($session->uuid, 8, '') }}
                    @endif
                </p>

                <ol style="margin:0;padding:0;list-style:none;border:1px solid rgba(127,127,127,.25);border-radius:.5rem;overflow:hidden;">
                    @foreach ($run as $version)
                        @php $intact = $version->verify(); @endphp

                        <li style="display:flex;flex-wrap:wrap;align-items:center;gap:.25rem .75rem;padding:.5rem .75rem;{{ $loop->last ? '' : 'border-bottom:1px solid rgba(127,127,127,.2);' }}">
                            <span style="font-weight:600;min-width:2.25rem;">v{{ $version->number }}</span>

                            <span style="flex:1;min-width:10rem;">
                                @if ($version->isBase())
                                    Frozen for signing
                                @else
                                    Signed by {{ \Kukux\DigitalSignature\Signatories\SignatoryRoute::nameOf($version->signature?->user) ?? 'a signatory' }}
                                    @if ($version->signature?->slot_key)
                                        <span style="opacity:.7;">({{ str_replace('_', ' ', $version->signature->slot_key) }})</span>
                                    @endif
                                @endif
                                <span style="opacity:.7;">· {{ $version->createdAt->format('j M Y, g:i a') }}</span>
                            </span>

                            <code title="{{ $version->hash }}" style="font-size:.75rem;opacity:.7;">{{ \Illuminate\Support\Str::limit((string) $version->hash, 12, '…') }}</code>

                            <span style="font-size:.75rem;font-weight:600;color:{{ $intact ? '#15803d' : '#b91c1c' }};">
                                {{ $intact ? 'Intact' : 'Does not match' }}
                            </span>

                            <a href="{{ $version->url() }}" target="_blank" rel="noopener" style="text-decoration:underline;">Open</a>
                        </li>
                    @endforeach
                </ol>
            </section>
        @endforeach
    </div>
@endif
