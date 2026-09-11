<?php

namespace Kukux\DigitalSignature\Filament\Actions\Concerns;

use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Kukux\DigitalSignature\Contracts\Signable;
use Kukux\DigitalSignature\Exceptions\ForgedSignatureException;
use Kukux\DigitalSignature\Filament\Fields\SignaturePickerField;
use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Services\SignatureManager;

/**
 * The entire behaviour of SignDocumentAction, independent of which Filament
 * base class the action extends.
 *
 * v3 table actions descend from Filament\Tables\Actions\Action; v4 and v5
 * unified everything under Filament\Actions\Action. Both bases expose the
 * same fluent surface this trait uses (label / modalHeading / modalWidth /
 * form / action / halt), so the version split is purely the `extends` line.
 */
trait SignsDocuments
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

    /**
     * Fixed stamp coordinates, in PDF points with y measured from the bottom
     * of the page. Omit to let the signer place the signature instead.
     */
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

        $this->action(fn (array $data, $record = null) => $this->handleSigning($data, $record));
    }

    protected function handleSigning(array $data, mixed $record): void
    {
        $userId = Auth::id();

        if (! $userId) {
            $this->fail('Not authenticated', 'You must be logged in to sign a document.');

            return;
        }

        $signatureId = $data['signature_id'] ?? null;

        if (! $signatureId) {
            $this->fail('Signature required', 'Please select a signature to use.');

            return;
        }

        $signature = Signature::find($signatureId);

        if (! $signature || (int) $signature->user_id !== (int) $userId) {
            $this->fail('Signature not yours', 'You can only use signatures registered to your own account.');

            return;
        }

        if ($signature->isRevoked()) {
            $this->fail('Signature revoked', 'This signature has been revoked and cannot be used.');

            return;
        }

        $signingPassword = $signature->getCertificatePassword();

        if (! $signingPassword) {
            $this->fail(
                'Certificate password missing',
                'This signature has no stored certificate password. Re-create it from the Signatures page.',
            );

            return;
        }

        if (! $record instanceof Signable) {
            $this->fail(
                'No document selected',
                'This action must be used from a record that implements the Signable contract.',
            );

            return;
        }

        /** @var SignatureManager $manager */
        $manager = app(SignatureManager::class);

        try {
            $documentSignature = $manager->storeForDocument(
                source: $signature,
                signerUserId: (int) $userId,
                signable: $record,
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
            $this->fail('Signature rejected', $e->getMessage());
        } catch (\Exception $e) {
            $this->fail('Signing failed', 'An error occurred while signing: '.$e->getMessage());
        }
    }

    /**
     * Send a danger notification and abort the action so Filament keeps the
     * modal open instead of reporting success.
     */
    protected function fail(string $title, string $body): void
    {
        Notification::make()
            ->title($title)
            ->body($body)
            ->danger()
            ->send();

        $this->halt();
    }
}
