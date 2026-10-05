{{-- One computer per account: shown before pairing, so a second computer's refusal isn't a surprise. --}}
@if ($computers->count() === 1)
    <div class="dsd-note dsd-note--info" role="status">
        Your account is paired with <strong>{{ $computers->first()->displayName() }}</strong>.
        You can only re-pair that computer. To use a different one, revoke it first.
    </div>
@elseif ($computers->count() > 1)
    <div class="dsd-note dsd-note--info" role="status">
        Only one computer can be paired with your account now.
        Revoke the ones you no longer use before pairing again.
    </div>
@endif
