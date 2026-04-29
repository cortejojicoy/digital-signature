<?php

namespace Kukux\DigitalSignature\Filament\Actions;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Kukux\DigitalSignature\Contracts\Signable;
use Kukux\DigitalSignature\Exceptions\ForgedSignatureException;
use Kukux\DigitalSignature\Filament\Fields\SignaturePickerField;
use Kukux\DigitalSignature\Models\Signature;
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

        $this->modalHeading('Sign Document');

        $this->modalWidth('lg');

        $this->form(fn () => [
            SignaturePickerField::make('signature_id')
                ->label('Select Signature')
                ->required(),
        ]);

        $this->action(function (array $data, $record = null) {
            $userId = Auth::id();

            if (! $userId) {
                Notification::make()
                    ->title('Not authenticated')
                    ->body('You must be logged in to sign a document.')
                    ->danger()
                    ->send();

                $this->halt();

                return;
            }

            $signatureId = $data['signature_id'] ?? null;

            if (! $signatureId) {
                Notification::make()
                    ->title('Signature required')
                    ->body('Please select a signature to use.')
                    ->danger()
                    ->send();

                $this->halt();

                return;
            }

            $signature = Signature::find($signatureId);

            if (! $signature || (int) $signature->user_id !== (int) $userId) {
                Notification::make()
                    ->title('Signature not yours')
                    ->body('You can only use signatures registered to your own account.')
                    ->danger()
                    ->send();

                $this->halt();

                return;
            }

            if ($signature->isRevoked()) {
                Notification::make()
                    ->title('Signature revoked')
                    ->body('This signature has been revoked and cannot be used.')
                    ->danger()
                    ->send();

                $this->halt();

                return;
            }

            $signingPassword = $signature->getCertificatePassword();

            if (! $signingPassword) {
                Notification::make()
                    ->title('Certificate password missing')
                    ->body('This signature has no stored certificate password. Re-create it from the Signatures page.')
                    ->danger()
                    ->send();

                $this->halt();

                return;
            }

            $signable = ($record instanceof Signable) ? $record : null;

            if (! $signable) {
                Notification::make()
                    ->title('No document selected')
                    ->body('This action must be used from a record that implements the Signable contract.')
                    ->danger()
                    ->send();

                $this->halt();

                return;
            }

            /** @var SignatureManager $manager */
            $manager = app(SignatureManager::class);

            try {
                $documentSignature = $manager->storeForDocument(
                    source: $signature,
                    signerUserId: (int) $userId,
                    signable: $signable,
                    position: $this->defaultPosition ?: null,
                );

                if ($this->queued) {
                    $manager->sign($documentSignature, $signingPassword);
                } else {
                    $manager->embedAndFinalize($documentSignature, $signingPassword);
                }

                Notification::make()
                    ->title('Document signed')
                    ->body('The document has been signed successfully.')
                    ->success()
                    ->send();
            } catch (ForgedSignatureException $e) {
                Notification::make()
                    ->title('Signature rejected')
                    ->body($e->getMessage())
                    ->danger()
                    ->send();

                $this->halt();
            } catch (\Exception $e) {
                Notification::make()
                    ->title('Signing failed')
                    ->body('An error occurred while signing: '.$e->getMessage())
                    ->danger()
                    ->send();

                $this->halt();
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
