<?php

namespace Kukux\DigitalSignature\Filament\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Kukux\DigitalSignature\Contracts\Signable;
use Kukux\DigitalSignature\Exceptions\ForgedSignatureException;
use Kukux\DigitalSignature\Exceptions\MachineBindingException;
use Kukux\DigitalSignature\Filament\Fields\SignaturePad;
use Kukux\DigitalSignature\Services\SignatureManager;

class SignDocumentAction extends Action
{
    protected array $defaultPosition = [];

    protected bool $queued = false;

    public function queued(bool $condition = true): static
    {
        $this->queued = $condition;

        return $this;
    }

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
                ->label('Signature'),

            TextInput::make('password')
                ->label('Certificate Password')
                ->password()
                ->required()
                ->hint('Protects your signing certificate')
                ->hintIcon('heroicon-m-lock-closed')
                ->placeholder('Enter your certificate password'),

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

            if (empty($inputData)) {
                Notification::make()
                    ->title('Signature required')
                    ->body('Please draw or upload your signature before submitting.')
                    ->danger()
                    ->send();

                $this->halt();

                return;
            }

            // Device fingerprint is stored in the session by the blade's
            // x-init when machineFingerprint.js resolves.
            // Falls back to '' — server-side signals (UA + IP) still apply.
            $inputFp = session()->pull('sig_device_fp', '');

            /** @var SignatureManager $manager */
            $manager = app(SignatureManager::class);

            $signable = ($record instanceof Signable) ? $record : null;

            try {
                $signature = $manager->store(
                    userId:   Auth::id(),
                    input:    $inputData,
                    source:   $inputSrc,
                    signable: $signable,
                    position: $this->defaultPosition ?: null,
                    deviceFp: $inputFp,
                );

                if ($this->queued) {
                    $manager->sign($signature, $inputPwd);
                } else {
                    $manager->embedAndFinalize($signature, $inputPwd);
                }
            } catch (MachineBindingException $e) {
                Notification::make()
                    ->title('Signature rejected — wrong device')
                    ->body('This signature image was created on a different device. Please draw a new signature on this device.')
                    ->danger()
                    ->send();

                $this->halt();

                return;
            } catch (ForgedSignatureException $e) {
                Notification::make()
                    ->title('Invalid signature image')
                    ->body('The uploaded image failed security verification. It may have been tampered with or does not originate from this system.')
                    ->danger()
                    ->send();

                $this->halt();

                return;
            }
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
