{{-- No entry wrapper, like the signatory panel: the wrapper's API differs across Filament majors. --}}
<div class="fi-sig-history">
    @include('signature::components.document-history', ['history' => $getHistory()])
</div>
