<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Kukux\DigitalSignature\Contracts\DocumentOfRecordGate;
use Kukux\DigitalSignature\DocumentOfRecord\DocumentOfRecordResolver;
use Kukux\DigitalSignature\DocumentOfRecord\DocumentState;
use Kukux\DigitalSignature\DocumentOfRecord\DocumentVersion;
use Kukux\DigitalSignature\Events\DocumentTampered;
use Kukux\DigitalSignature\Exceptions\DocumentRetainedException;
use Kukux\DigitalSignature\Services\DocumentRouter;
use Kukux\DigitalSignature\Services\SigningSessionManager;
use Kukux\DigitalSignature\Tests\Support\LeaveForm;
use Kukux\DigitalSignature\Tests\Support\LeaveLedger;
use Kukux\DigitalSignature\Tests\Support\TestUser;

/**
 * The document of record is a signing history: every signatory can go back to
 * the exact file they signed, and nothing is re-rendered once routed.
 */
describe('Document of record', function () {

    beforeEach(function () {
        Storage::fake('testing');
        leaveFormSchema();
        registerLeaveForm();
        $this->signedSources = stubSignatureEmbedding();

        $this->boss = signingPerson(21, 'Dr Reyes');
        $this->juana = signingPerson(22, 'Juana Cruz', supervisorId: 21);
        app()->instance(LeaveLedger::class, new LeaveLedger([22 => 3]));

        $this->period = ['period' => '2026-09'];

        $this->route = fn () => app(DocumentRouter::class)->route('leave-form', $this->juana, $this->period);
        $this->signAs = function (string $slot, int $userId) {
            $session = $this->form->latestSigningSession();
            $request = $session->requests()->where('slot_key', $slot)->first();

            return app(SigningSessionManager::class)->sign($request, $userId);
        };
    });

    afterEach(fn () => Mockery::close());

    it('is null until the document is routed', function () {
        expect(app(DocumentOfRecordResolver::class)->locate('leave-form', $this->juana, $this->period))->toBeNull();
    });

    it('walks through pending, in progress and complete, always serving the latest version', function () {
        $this->form = ($this->route)()->record();

        $record = $this->form->documentOfRecord();
        expect($record->state)->toBe(DocumentState::Pending)
            ->and($record->current->number)->toBe(0)
            ->and($record->current->isBase())->toBeTrue();

        $first = ($this->signAs)('applicant', $this->juana->user_id);

        $record = $this->form->documentOfRecord();
        expect($record->state)->toBe(DocumentState::InProgress)
            ->and($record->current->number)->toBe(1)
            ->and($record->current->path)->toBe($first->signed_document_path)
            ->and($record->label())->toBe('1 of 2 signed · waiting on Dr Reyes');

        ($this->signAs)('reviewer', $this->boss->user_id);

        $record = $this->form->documentOfRecord();
        expect($record->state)->toBe(DocumentState::Complete)
            ->and($record->current->number)->toBe(2)
            ->and($record->isFinal())->toBeTrue()
            ->and($record->label())->toStartWith('Signed by all 2');
    });

    it('keeps a withdrawn document viewable, as it was last signed', function () {
        $this->form = ($this->route)()->record();
        $first = ($this->signAs)('applicant', $this->juana->user_id);

        app(SigningSessionManager::class)->cancel($this->form->latestSigningSession());

        $record = $this->form->documentOfRecord();

        expect($record->state)->toBe(DocumentState::Withdrawn)
            ->and($record->current->path)->toBe($first->signed_document_path)
            ->and($record->contents())->toContain("signed by signature {$first->id}");
    });

    it('keeps every version: the base, and each signature\'s own copy, with its hash', function () {
        $this->form = ($this->route)()->record();
        $first = ($this->signAs)('applicant', $this->juana->user_id);
        $second = ($this->signAs)('reviewer', $this->boss->user_id);

        $versions = $this->form->documentHistory()->versions();

        expect($versions->pluck('number')->all())->toBe([0, 1, 2])
            ->and($versions[1]->signature->id)->toBe($first->id)
            ->and($versions[2]->signature->id)->toBe($second->id)
            ->and($versions[1]->hash)->toBe($first->signed_document_hash)
            ->and($versions->every(fn (DocumentVersion $v): bool => $v->verify()))->toBeTrue()
            // The copy Juana signed is still exactly what she signed, after Dr Reyes signed on top.
            ->and($this->form->documentHistory()->producedBy($first)->contents())
                ->toBe(Storage::disk('testing')->get($first->signed_document_path));
    });

    it('notices a version that no longer matches what was signed, and still serves it', function () {
        Event::fake([DocumentTampered::class]);

        $this->form = ($this->route)()->record();
        $first = ($this->signAs)('applicant', $this->juana->user_id);

        Storage::disk('testing')->put($first->signed_document_path, '%PDF-1.4 swapped');

        $record = $this->form->documentOfRecord();

        expect($record->current->verify())->toBeFalse()
            ->and($record->contents())->toBe('%PDF-1.4 swapped');

        Event::assertDispatched(DocumentTampered::class, fn ($e) => $e->version->number === 1);
    });

    it('renders the history: each version, who produced it, and whether it still matches', function () {
        $this->form = ($this->route)()->record();
        ($this->signAs)('applicant', $this->juana->user_id);

        $html = view('signature::components.document-history', ['history' => $this->form->documentHistory()])->render();

        expect($html)->toContain('v0')
            ->and($html)->toContain('Frozen for signing')
            ->and($html)->toContain('v1')
            ->and($html)->toContain('Intact')
            ->and($html)->toContain('/versions/1');
    });

    it('serves the stored document and never calls the live renderer once routed', function () {
        $this->form = ($this->route)()->record();

        $resolved = app(DocumentOfRecordResolver::class)->resolve(
            'leave-form',
            $this->juana,
            $this->period,
            fn () => throw new LogicException('rendered live after routing'),
        );

        expect($resolved->isOfRecord())->toBeTrue()
            ->and($resolved->contents())->toStartWith('%PDF')
            ->and((string) $resolved->banner())->toContain('Document of record');
    });

    it('renders live for a draft that was never routed', function () {
        $resolved = app(DocumentOfRecordResolver::class)->resolve(
            'leave-form',
            $this->juana,
            $this->period,
            fn () => '%PDF-1.4 draft',
        );

        expect($resolved->isOfRecord())->toBeFalse()
            ->and($resolved->contents())->toBe('%PDF-1.4 draft')
            ->and($resolved->banner())->toBeNull()
            // Looking at a draft must not file one.
            ->and(LeaveForm::count())->toBe(0);
    });

    it('refuses to delete a routed record, and lets an unrouted one go', function () {
        $this->form = ($this->route)()->record();

        expect(fn () => $this->form->delete())->toThrow(DocumentRetainedException::class);

        $draft = LeaveForm::create(['person_id' => 22, 'period' => '2026-10']);
        $draft->delete();

        expect(LeaveForm::find($draft->id))->toBeNull();
    });

    describe('over HTTP', function () {

        it('streams the current version to a signatory, with its integrity', function () {
            $this->form = ($this->route)()->record();
            $first = ($this->signAs)('applicant', $this->juana->user_id);
            $session = $this->form->latestSigningSession();

            $response = $this->actingAs(TestUser::find($this->boss->user_id))
                ->get(route('signature.documents.show', ['session' => $session->uuid]));

            $response->assertOk()
                ->assertHeader('Content-Type', 'application/pdf')
                ->assertHeader('X-Document-Version', '1')
                ->assertHeader('X-Document-Integrity', 'verified');

            expect($response->getContent())->toBe(Storage::disk('testing')->get($first->signed_document_path));
        });

        it('serves one version by number, and 404s past the end', function () {
            $this->form = ($this->route)()->record();
            ($this->signAs)('applicant', $this->juana->user_id);
            $session = $this->form->latestSigningSession();

            $this->actingAs(TestUser::find($this->juana->user_id))
                ->get(route('signature.documents.version', ['session' => $session->uuid, 'version' => 0]))
                ->assertOk()
                ->assertHeader('X-Document-Version', '0');

            $this->actingAs(TestUser::find($this->juana->user_id))
                ->get(route('signature.documents.version', ['session' => $session->uuid, 'version' => 5]))
                ->assertNotFound();
        });

        it('turns away someone the document has nothing to do with', function () {
            $this->form = ($this->route)()->record();
            $stranger = makeUser(99, 'Someone Else');

            $this->actingAs($stranger)
                ->get(route('signature.documents.show', ['session' => $this->form->latestSigningSession()->uuid]))
                ->assertForbidden();
        });

        it('always lets a signatory open the copy they signed, whatever else the gate says', function () {
            $this->form = ($this->route)()->record();
            ($this->signAs)('applicant', $this->juana->user_id);
            ($this->signAs)('reviewer', $this->boss->user_id);
            $session = $this->form->latestSigningSession();

            // An app whose gate shuts everyone out of the record itself.
            app()->bind(DocumentOfRecordGate::class, fn () => new class extends \Kukux\DigitalSignature\DocumentOfRecord\DefaultDocumentOfRecordGate {
                public function canView(?Authenticatable $user, Model $record): bool
                {
                    return false;
                }
            });

            $juana = TestUser::find($this->juana->user_id);

            $this->actingAs($juana)
                ->get(route('signature.documents.version', ['session' => $session->uuid, 'version' => 1]))
                ->assertOk();

            $this->actingAs($juana)
                ->get(route('signature.documents.version', ['session' => $session->uuid, 'version' => 2]))
                ->assertForbidden();
        });

        it('opens a signed request on the copy that signatory signed, or the current one on request', function () {
            $this->form = ($this->route)()->record();
            $first = ($this->signAs)('applicant', $this->juana->user_id);
            $second = ($this->signAs)('reviewer', $this->boss->user_id);

            $request = $this->form->latestSigningSession()->requests()->where('slot_key', 'applicant')->first();
            $juana = TestUser::find($this->juana->user_id);

            $mine = $this->actingAs($juana)->get(route('signature.request.document', ['signatureRequest' => $request->id]));
            $now = $this->actingAs($juana)->get(route('signature.request.document', [
                'signatureRequest' => $request->id,
                'version'          => 'current',
            ]));

            expect($mine->streamedContent())->toBe(Storage::disk('testing')->get($first->signed_document_path))
                ->and($now->streamedContent())->toBe(Storage::disk('testing')->get($second->signed_document_path));
        });
    });
});
