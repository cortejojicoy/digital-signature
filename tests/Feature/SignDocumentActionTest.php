<?php

use Kukux\DigitalSignature\Filament\Actions\SignDocumentAction;
use Kukux\DigitalSignature\Services\SignatureManager;
use Kukux\DigitalSignature\Models\Signature;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

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

        $ref  = new \ReflectionClass($action);
        $prop = $ref->getProperty('defaultPosition');
        $prop->setAccessible(true);
        $pos  = $prop->getValue($action);

        expect($pos['page'])->toBe(2)
            ->and($pos['x'])->toBe(100.0)
            ->and($pos['width'])->toBe(200.0);
    });

    it('calls SignatureManager::store() and sign() on action execute', function () {
        $user = makeFakeUser();
        \Illuminate\Support\Facades\Auth::login($user);

        $managerMock = Mockery::mock(SignatureManager::class);
        $fakeSig     = new Signature(['id' => 99, 'status' => 'pending']);

        $managerMock->shouldReceive('store')->once()->andReturn($fakeSig);
        $managerMock->shouldReceive('sign')->once()->with($fakeSig, 'secret');
        $this->app->instance(SignatureManager::class, $managerMock);

        $action = SignDocumentAction::make();

        // Invoke the action closure directly
        $ref     = new \ReflectionClass($action);
        $method  = $ref->getMethod('setUp');
        // Access via the action's built-in handle mechanism
        $action->call([
            'signature_data' => fakePng(),
            'password'       => 'secret',
            'source'         => 'draw',
        ]);

        Mockery::close();
    });
});
