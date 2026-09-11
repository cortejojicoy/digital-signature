{{--
    Render-hook mount point for the floating launcher.

    Guarded on the *panel's* guard rather than @auth: a panel frequently
    authenticates against a guard other than the app default, and mounting the
    component for a guest would render a button whose queue query has no user
    to scope to.
--}}
@if (filament()->auth()->check())
    @livewire('kukux-digital-signature.launcher')
@endif
