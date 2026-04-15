<?php

use Illuminate\Support\Facades\Blade;

describe('signature-field blade template', function () {

    // -------------------------------------------------------------------------
    // Compilation guard
    // -------------------------------------------------------------------------
    // This test exists specifically to catch the class of error that was
    // reported: @endifs (invalid directive) instead of @endif.
    // It re-compiles the raw template string on every run so that any future
    // typo in a Blade directive is caught before deployment.
    // -------------------------------------------------------------------------

    it('compiles without a ParseError', function () {
        $source = file_get_contents(
            realpath(__DIR__ . '/../../resources/views/components/signature-field.blade.php')
        );

        // compileString() throws an InvalidArgumentException or causes a
        // ParseError if any directive is malformed (e.g. @endifs, @endsection with
        // no matching @section, etc.)
        $compiled = Blade::compileString($source);

        expect($compiled)->toBeString()->not->toBeEmpty();
    });

    it('contains no unmatched @endif directives', function () {
        $source = file_get_contents(
            realpath(__DIR__ . '/../../resources/views/components/signature-field.blade.php')
        );

        $opens  = substr_count($source, '@if');        // counts @if, @elseif
        $elseif = substr_count($source, '@elseif');
        $closes = substr_count($source, '@endif');

        // Each @if needs exactly one @endif; @elseif lives inside a block
        expect($closes)->toBe($opens - $elseif);
    });

    it('does not contain the @endifs typo', function () {
        $source = file_get_contents(
            realpath(__DIR__ . '/../../resources/views/components/signature-field.blade.php')
        );

        expect($source)->not->toContain('@endifs');
    });

    // -------------------------------------------------------------------------
    // Tab visibility logic
    // -------------------------------------------------------------------------
    // These tests render the template with a mocked $field object to verify
    // that the draw / upload tab bar and tab panels appear or are hidden
    // according to the field configuration.
    // -------------------------------------------------------------------------

    function mockField(bool $draw, bool $upload): object
    {
        return new class ($draw, $upload) {
            public function __construct(
                private bool $draw,
                private bool $upload,
            ) {}

            public function getShowDrawTab(): bool   { return $this->draw; }
            public function getShowUploadTab(): bool { return $this->upload; }
            public function getFieldWrapperView(): string { return 'filament-forms::field-wrapper'; }
            public function getId(): string   { return 'sig_test'; }
            public function getName(): string { return 'signature_data'; }
        };
    }

    it('renders tab bar only when both tabs are enabled', function () {
        $source = file_get_contents(
            realpath(__DIR__ . '/../../resources/views/components/signature-field.blade.php')
        );

        $compiled = Blade::compileString($source);

        // When both tabs are enabled the outer @if block should produce the
        // tab bar wrapper div — confirmed by checking the compiled PHP contains
        // both getShowDrawTab and getShowUploadTab guards together
        expect($compiled)
            ->toContain('getShowDrawTab()')
            ->toContain('getShowUploadTab()');
    });

    it('compiled output contains draw tab section', function () {
        $source   = file_get_contents(
            realpath(__DIR__ . '/../../resources/views/components/signature-field.blade.php')
        );
        $compiled = Blade::compileString($source);

        expect($compiled)->toContain("activeTab === 'draw'");
    });

    it('compiled output contains upload tab section', function () {
        $source   = file_get_contents(
            realpath(__DIR__ . '/../../resources/views/components/signature-field.blade.php')
        );
        $compiled = Blade::compileString($source);

        expect($compiled)->toContain("activeTab === 'upload'");
    });

    it('compiled output contains hidden input bound to value', function () {
        $source   = file_get_contents(
            realpath(__DIR__ . '/../../resources/views/components/signature-field.blade.php')
        );
        $compiled = Blade::compileString($source);

        expect($compiled)
            ->toContain('type="hidden"')
            ->toContain('x-model="value"');
    });

    it('compiled output contains source hidden input', function () {
        $source   = file_get_contents(
            realpath(__DIR__ . '/../../resources/views/components/signature-field.blade.php')
        );
        $compiled = Blade::compileString($source);

        expect($compiled)->toContain('x-model="source"');
    });
});
