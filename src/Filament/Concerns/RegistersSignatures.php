<?php

namespace Kukux\DigitalSignature\Filament\Concerns;

use Filament\Notifications\Notification;
use Kukux\DigitalSignature\Client\Exceptions\SignatureManagedAtHubException;
use Kukux\DigitalSignature\Exceptions\PrimarySignatureExistsException;
use Kukux\DigitalSignature\Exceptions\UnregisteredDeviceException;
use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Services\SignatureManager;
use Kukux\DigitalSignature\Support\SignatureMode;

/**
 * Register a reusable signature for the signed-in user.
 *
 * Shared by the Signatures resource's "Add Signature" action and the drawer's
 * library tab, for the same reason sign/decline is shared by the inbox and the
 * launcher: two surfaces that create signing credentials must not be able to
 * disagree about the rules. The single-primary guard, the race it loses to,
 * and the notifications a user sees are all decided once, here.
 *
 * @see \Kukux\DigitalSignature\Filament\Concerns\ActsOnSignatureRequests
 */
trait RegistersSignatures
{
    /**
     * @param  string|null  $input  A data: URL from the signature pad, or an
     *                              uploaded image already normalised to one.
     * @param  string|null  $source Leave null to infer. Inference resolves to
     *                              `upload` for anything that arrives as a
     *                              data: URL — which is every path through the
     *                              pad, drawn or uploaded. That is deliberate
     *                              rather than lazy: `upload` is the stricter
     *                              branch in SignatureManager::store(), the one
     *                              that validates embedded metadata and cross-
     *                              checks the originating record, and a drawn
     *                              signature simply has no metadata for those
     *                              checks to object to.
     */
    protected function registerSignature(
        ?string $input,
        ?string $certificatePassword,
        ?string $source = null,
    ): ?Signature {
        $userId = auth()->id();

        // Client mode: signatures are created only at the hub. Said before
        // anything else, so nobody draws one only to be refused after.
        if (SignatureMode::isClient()) {
            $this->signatureFailure(
                'Managed at UPLB Signature',
                'Add or change your signature at UPLB Signature. It appears here on its own.',
            );

            return null;
        }

        if (! $userId) {
            $this->signatureFailure('Not authenticated', 'You must be signed in to register a signature.');

            return null;
        }

        if (blank($input)) {
            $this->signatureFailure('Signature required', 'Draw or upload your signature first.');

            return null;
        }

        if (blank($certificatePassword)) {
            $this->signatureFailure(
                'Certificate password required',
                'Your signing certificate needs a password. Without one the signature cannot be used to sign.',
            );

            return null;
        }

        $source ??= str_contains($input, 'data:image') ? 'upload' : 'draw';

        try {
            $signature = app(SignatureManager::class)->store(
                userId: (int) $userId,
                input: $input,
                source: $source,
                certificatePassword: $certificatePassword,
            );
        } catch (SignatureManagedAtHubException $e) {
            $this->signatureFailure('Managed at UPLB Signature', $e->getMessage());

            return null;
        } catch (PrimarySignatureExistsException $e) {
            // The visibility check and the submit are separated by however long
            // the user spent drawing, which is plenty of time to have created
            // one in another tab.
            $this->signatureFailure('Signature already exists', $e->getMessage());

            return null;
        } catch (UnregisteredDeviceException $e) {
            $this->signatureFailure('Device not allowed to sign', $e->getMessage());

            return null;
        } catch (\Throwable $e) {
            report($e);
            $this->signatureFailure('Could not save signature', $e->getMessage());

            return null;
        }

        Notification::make()
            ->title('Signature added')
            ->body('Your signature has been saved successfully.')
            ->success()
            ->send();

        return $signature;
    }

    /**
     * True when this user may register another primary signature.
     *
     * One active primary at a time: document-signing paths default to "the
     * user's signature", and that phrase has to name exactly one row.
     *
     * Never in client mode: the hub is where signatures are registered.
     */
    protected function canRegisterSignature(): bool
    {
        if (SignatureMode::isClient()) {
            return false;
        }

        $userId = auth()->id();

        return $userId
            ? ! Signature::primaryActiveFor((int) $userId)->exists()
            : false;
    }

    protected function signatureFailure(string $title, string $body): void
    {
        Notification::make()->title($title)->body($body)->danger()->send();
    }
}
