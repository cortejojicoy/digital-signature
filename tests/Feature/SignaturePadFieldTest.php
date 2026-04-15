<?php

use Kukux\DigitalSignature\Filament\Fields\SignaturePad;
use Kukux\DigitalSignature\Tests\TestCase;
use Livewire\Livewire;

describe('SignaturePad Filament field', function () {

    it('renders without errors', function () {
        $field = SignaturePad::make('signature_data');

        expect($field->getName())->toBe('signature_data')
            ->and($field->getView())->toBe('signature::components.signature-field');
    });

    it('fluent API sets canvas dimensions', function () {
        $field = SignaturePad::make('sig')
            ->canvasWidth(400)
            ->canvasHeight(150);

        expect($field->getCanvasWidth())->toBe(400)
            ->and($field->getCanvasHeight())->toBe(150);
    });

    it('fluent API sets pen config', function () {
        $field = SignaturePad::make('sig')
            ->penColor('#ff0000')
            ->penWidth(0.5, 4.0);

        expect($field->getPenColor())->toBe('#ff0000')
            ->and($field->getMinPenWidth())->toBe(0.5)
            ->and($field->getMaxPenWidth())->toBe(4.0);
    });

    it('withoutUploadTab disables upload tab', function () {
        $field = SignaturePad::make('sig')->withoutUploadTab();
        expect($field->getShowUploadTab())->toBeFalse();
    });

    it('withoutDrawTab disables draw tab', function () {
        $field = SignaturePad::make('sig')->withoutDrawTab();
        expect($field->getShowDrawTab())->toBeFalse();
    });

    it('dehydrates empty string state as null', function () {
        $field = SignaturePad::make('sig');
        // Access dehydrateStateUsing closure behaviour via reflection
        $ref    = new \ReflectionClass($field);
        $prop   = $ref->getProperty('dehydrateStateUsing');
        $prop->setAccessible(true);
        $closure = $prop->getValue($field);
        expect($closure(''))->toBeNull()
            ->and($closure('data:image/png;base64,abc'))->toBe('data:image/png;base64,abc');
    });
});
