<?php

use Kukux\DigitalSignature\Jobs\EmbedSignatureJob;
use Kukux\DigitalSignature\Services\SignatureManager;
use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Events\DocumentSigned;

describe('EmbedSignatureJob', function () {

    beforeEach(function () {
        Storage::fake('testing');
        Event::fake();
    });

    it('marks signature as failed when job fails', function () {
        $user = makeFakeUser();
        $sig  = Signature::create([
            'user_id'    => $user->id,
            'image_path' => 'signatures/x.png',
            'image_hash' => str_repeat('f', 64),
            'source'     => 'draw',
            'status'     => 'pending',
        ]);

        $job = new EmbedSignatureJob($sig->id, 'secret');
        $job->failed(new \RuntimeException('PDF engine error'));

        $sig->refresh();
        expect($sig->status)->toBe('failed');
    });

    it('skips processing if signature is no longer pending', function () {
        $user = makeFakeUser();
        $sig  = Signature::create([
            'user_id'    => $user->id,
            'image_path' => 'signatures/x.png',
            'image_hash' => str_repeat('e', 64),
            'source'     => 'draw',
            'status'     => 'signed',   // already done
        ]);

        $managerMock = Mockery::mock(SignatureManager::class);
        $managerMock->shouldNotReceive('embedAndFinalize');

        $job = new EmbedSignatureJob($sig->id, 'secret');
        $job->handle($managerMock);

        Event::assertNotDispatched(DocumentSigned::class);
        Mockery::close();
    });
});
