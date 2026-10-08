{{--
    Client mode's SignaturePad: signatures are drawn and uploaded only at the
    hub, so the field links there instead of offering a pad.
--}}
<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div style="padding: .75rem 1rem; border-radius: .5rem; border: 1px dashed rgb(0 0 0 / .2); font-size: .875rem;">
        Your signature is managed at UPLB Signature.
        <a href="{{ \Kukux\DigitalSignature\Client\HubLinks::profile() }}" target="_blank" rel="noopener" style="font-weight: 600; text-decoration: underline;">
            Add or change it there
        </a>.
    </div>
</x-dynamic-component>
