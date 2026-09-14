<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Kukux\DigitalSignature\Contracts\Signable;
use Kukux\DigitalSignature\Filament\Actions\SignDocumentAction;
use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Services\SignatureManager;

describe('SignDocumentAction', function () {

    beforeEach(function () {
        Storage::fake('testing');
        Queue::fake();
    });

    it('has the correct default name and label', function () {
        $action = SignDocumentAction::make();
        expect($action->getName())->toBe('sign_document');
    });

    it('stampAt sets position correctly', function () {
        $action = SignDocumentAction::make()
            ->stampAt(page: 2, x: 100.0, y: 650.0, w: 200.0, h: 80.0);

        $ref = new ReflectionClass($action);
        $prop = $ref->getProperty('defaultPosition');
        $prop->setAccessible(true);
        $pos = $prop->getValue($action);

        expect($pos['page'])->toBe(2)
            ->and($pos['x'])->toBe(100.0)
            ->and($pos['width'])->toBe(200.0);
    });

    it('creates a document signature and finalizes it on action execute', function () {
        $user = makeFakeUser();
        Auth::login($user);

        Storage::disk('testing')->put('signatures/stored.png', base64_decode(substr(fakePng(), strlen('data:image/png;base64,'))));

        $storedSignature = Signature::create([
            'user_id' => $user->id,
            'image_path' => 'signatures/stored.png',
            'image_hash' => str_repeat('a', 64),
            'source' => 'draw',
            'status' => 'pending',
            'certificate_password' => 'secret',
        ]);

        $document = new class extends Model implements Signable
        {
            public $exists = true;

            protected $table = 'contracts';

            public function getSignableTitle(): string
            {
                return 'Contract';
            }

            public function getSignablePdfPath(): string
            {
                return 'documents/contract.pdf';
            }

            public function getSignableId(): int|string
            {
                return 123;
            }
        };
        $document->id = 123;

        $managerMock = Mockery::mock(SignatureManager::class);
        $documentSignature = new Signature(['id' => 99, 'status' => 'pending']);

        // The action reuses an already-registered signature rather than
        // storing a new image, so it calls storeForDocument() — not store().
        $managerMock->shouldReceive('storeForDocument')
            ->once()
            ->withArgs(function (
                Signature $source,
                int $signerUserId,
                Signable $signable,
                ?array $position = null,
                ?string $sourcePdfPath = null,
                ?array $chain = null,
            ) use ($user, $document, $storedSignature) {
                return $source->is($storedSignature)
                    && $signerUserId === $user->id
                    && $signable === $document
                    && $position === null
                    && $sourcePdfPath === null
                    && $chain === null;
            })
            ->andReturn($documentSignature);

        // Two arguments: the action signs the record's own PDF, so it leaves
        // the optional session source-document path unset.
        $managerMock->shouldReceive('embedAndFinalize')
            ->once()
            ->with($documentSignature, 'secret');

        $this->app->instance(SignatureManager::class, $managerMock);

        $action = SignDocumentAction::make();

        $action->call([
            'data' => [
                'signature_id' => $storedSignature->id,
                'password' => 'secret',
            ],
            'record' => $document,
        ]);

        expect($storedSignature->exists)->toBeTrue();

        Mockery::close();
    });
});
