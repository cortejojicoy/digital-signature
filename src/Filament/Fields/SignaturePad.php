<?php

namespace Kukux\DigitalSignature\Filament\Fields;

use Filament\Forms\Components\Field;

class SignaturePad extends Field
{
    protected string $view = 'signature::components.signature-field';

    protected int $canvasWidth  = 600;
    protected int $canvasHeight = 200;
    protected string $penColor  = '#000000';
    protected float $minPenWidth = 0.5;
    protected float $maxPenWidth = 2.5;
    protected bool $showUploadTab = true;
    protected bool $showDrawTab   = true;
    protected bool $showClearBtn  = true;
    protected bool $showUndoBtn   = true;
    protected string $confirmLabel = 'Confirm';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dehydrateStateUsing(fn (?string $state): ?string =>
            ($state === '' || $state === null) ? null : $state
        );
    }

    public function canvasWidth(int $width): static
    {
        $this->canvasWidth = $width;
        return $this;
    }

    public function canvasHeight(int $height): static
    {
        $this->canvasHeight = $height;
        return $this;
    }

    public function penColor(string $color): static
    {
        $this->penColor = $color;
        return $this;
    }

    public function penWidth(float $min, float $max): static
    {
        $this->minPenWidth = $min;
        $this->maxPenWidth = $max;
        return $this;
    }

    public function withoutUploadTab(): static
    {
        $this->showUploadTab = false;
        return $this;
    }

    public function withoutDrawTab(): static
    {
        $this->showDrawTab = false;
        return $this;
    }

    public function withoutClearBtn(): static
    {
        $this->showClearBtn = false;
        return $this;
    }

    public function withoutUndoBtn(): static
    {
        $this->showUndoBtn = false;
        return $this;
    }

    public function confirmLabel(string $label): static
    {
        $this->confirmLabel = $label;
        return $this;
    }

    public function getCanvasWidth(): int     { return $this->canvasWidth; }
    public function getCanvasHeight(): int    { return $this->canvasHeight; }
    public function getPenColor(): string     { return $this->penColor; }
    public function getMinPenWidth(): float   { return $this->minPenWidth; }
    public function getMaxPenWidth(): float   { return $this->maxPenWidth; }
    public function getShowUploadTab(): bool  { return $this->showUploadTab; }
    public function getShowDrawTab(): bool    { return $this->showDrawTab; }
    public function getShowClearBtn(): bool   { return $this->showClearBtn; }
    public function getShowUndoBtn(): bool    { return $this->showUndoBtn; }
    public function getConfirmLabel(): string { return $this->confirmLabel; }
}
