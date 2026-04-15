<?php

use Kukux\DigitalSignature\Traits\HasSignatures;
use Kukux\DigitalSignature\Contracts\Signable;
use Kukux\DigitalSignature\Models\Signature;
use Illuminate\Database\Eloquent\Model;

// Stub signable model
class FakeDocument extends Model implements Signable
{
    use HasSignatures;
    protected $table    = 'fake_documents';
    protected $fillable = ['title', 'pdf_path'];

    public function getSignableTitle(): string  { return $this->title; }
    public function getSignablePdfPath(): string { return $this->pdf_path; }
    public function getSignableId(): int|string  { return $this->id; }
}

describe('HasSignatures trait', function () {

    beforeEach(function () {
        Storage::fake('testing');
        \Illuminate\Support\Facades\Schema::create('fake_documents', function ($t) {
            $t->id();
            $t->string('title');
            $t->string('pdf_path');
            $t->timestamps();
        });
    });

    afterEach(function () {
        \Illuminate\Support\Facades\Schema::dropIfExists('fake_documents');
    });

    it('isSigned returns false when no signed signatures exist', function () {
        $doc = FakeDocument::create(['title' => 'Test', 'pdf_path' => 'docs/a.pdf']);
        expect($doc->isSigned())->toBeFalse();
    });

    it('isSigned returns true after a signed signature is attached', function () {
        $user = makeFakeUser();
        $doc  = FakeDocument::create(['title' => 'Test', 'pdf_path' => 'docs/a.pdf']);

        Signature::create([
            'user_id'       => $user->id,
            'signable_type' => FakeDocument::class,
            'signable_id'   => $doc->id,
            'image_path'    => 'signatures/x.png',
            'image_hash'    => str_repeat('b', 64),
            'source'        => 'draw',
            'status'        => 'signed',
            'signed_at'     => now(),
        ]);

        expect($doc->isSigned())->toBeTrue();
    });

    it('latestSignature returns the most recent record', function () {
        $user = makeFakeUser();
        $doc  = FakeDocument::create(['title' => 'Test', 'pdf_path' => 'docs/a.pdf']);

        Signature::create([
            'user_id' => $user->id, 'signable_type' => FakeDocument::class,
            'signable_id' => $doc->id, 'image_path' => 'signatures/1.png',
            'image_hash' => str_repeat('1', 64), 'source' => 'draw', 'status' => 'pending',
        ]);
        $latest = Signature::create([
            'user_id' => $user->id, 'signable_type' => FakeDocument::class,
            'signable_id' => $doc->id, 'image_path' => 'signatures/2.png',
            'image_hash' => str_repeat('2', 64), 'source' => 'upload', 'status' => 'pending',
        ]);

        expect($doc->latestSignature()->id)->toBe($latest->id);
    });
});
