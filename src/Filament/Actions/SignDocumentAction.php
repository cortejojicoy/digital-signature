<?php

namespace Kukux\DigitalSignature\Filament\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\Auth;
use Kukux\DigitalSignature\Contracts\Signable;
use Kukux\DigitalSignature\Filament\Fields\SignaturePad;
use Kukux\DigitalSignature\Services\SignatureManager;

class SignDocumentAction extends Action
{
    protected array $defaultPosition = [];

    public static function getDefaultName(): ?string
    {
        return 'sign_document';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Sign Document');

        $this->form([
            SignaturePad::make('signature_data')
                ->label('Signature')
                ->required(),

            TextInput::make('password')
                ->label('Certificate Password')
                ->password()
                ->required(),

            // source is set by the SignaturePad Alpine component
            Hidden::make('source')->default('draw'),
        ]);

        $this->action(function (
            array  $data     = [],
            string $signature_data = '',
            string $password       = '',
            string $source         = 'draw',
            $record = null,
        ) {
            // Support both form submission ($data) and direct call() in tests
            $inputData = $data['signature_data'] ?? $signature_data;
            $inputPwd  = $data['password']        ?? $password;
            $inputSrc  = $data['source']          ?? $source;

            // Device fingerprint is stored in the session by the blade's
            // x-init when machineFingerprint.js resolves.
            // Falls back to '' — server-side signals (UA + IP) still apply.
            $inputFp = session()->pull('sig_device_fp', '');

            /** @var SignatureManager $manager */
            $manager = app(SignatureManager::class);

            $signable = ($record instanceof Signable) ? $record : null;

            $signature = $manager->store(
                userId:   Auth::id(),
                input:    $inputData,
                source:   $inputSrc,
                signable: $signable,
                position: $this->defaultPosition ?: null,
                deviceFp: $inputFp,
            );

            $manager->sign($signature, $inputPwd);
        });
    }

    public function stampAt(int $page, float $x, float $y, float $w, float $h): static
    {
        $this->defaultPosition = [
            'page'   => $page,
            'x'      => $x,
            'y'      => $y,
            'width'  => $w,
            'height' => $h,
        ];

        return $this;
    }
}
