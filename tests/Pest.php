<?php

use Kukux\DigitalSignature\Tests\TestCase;

uses(TestCase::class)->in('Unit', 'Feature');

// Shared helpers
function makeFakeUser(): \Illuminate\Foundation\Auth\User
{
    $user = new class extends \Illuminate\Foundation\Auth\User {
        protected $table    = 'users';
        protected $fillable = ['name', 'email', 'password'];
    };
    $user->forceFill(['id' => 1, 'name' => 'Test User', 'email' => 'test@example.com'])->save();
    return $user;
}

function fakePng(): string
{
    // 1×1 white PNG as base64 data URI
    return 'data:image/png;base64,'
        .base64_encode(base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwADhQGAWjR9awAAAABJRU5ErkJggg=='
        ));
}

/**
 * A user row with an explicit id, so a test can build several distinct
 * signatories (makeFakeUser() always creates user #1).
 */
function makeUser(int $id, string $name, ?string $email = null): \Illuminate\Foundation\Auth\User
{
    $user = new class extends \Illuminate\Foundation\Auth\User {
        protected $table    = 'users';
        protected $fillable = ['name', 'email', 'password'];
    };

    $user->forceFill([
        'id'    => $id,
        'name'  => $name,
        'email' => $email ?? \Illuminate\Support\Str::slug($name).'@example.test',
    ])->save();

    return $user;
}

/**
 * An active primary signature for a user — the thing routing looks for when
 * deciding whether a tagged person can actually sign.
 */
function makePrimarySignature(int $userId, ?string $password = 'secret'): \Kukux\DigitalSignature\Models\Signature
{
    return \Kukux\DigitalSignature\Models\Signature::create([
        'uuid'                 => (string) \Illuminate\Support\Str::uuid(),
        'user_id'              => $userId,
        'image_path'           => "signatures/user-{$userId}.png",
        'image_hash'           => hash('sha256', "user-{$userId}"),
        'source'               => 'draw',
        'status'               => 'active',
        'certificate_password' => $password,
    ]);
}

/**
 * Register a template whose slots are routed to relations on the test record.
 *
 * @param  array<string, array<string, mixed>>  $slots
 */
function registerRoutedTemplate(string $key, array $slots, array $extra = []): \Kukux\DigitalSignature\Pdf\BladePdfTemplate
{
    $template = \Kukux\DigitalSignature\Pdf\BladePdfTemplate::fromConfig($key, array_merge([
        'view'  => 'blank',
        'slots' => $slots,
    ], $extra));

    app(\Kukux\DigitalSignature\Services\PdfTemplateRegistry::class)->register($template);

    return $template;
}

/**
 * A small OPAQUE RGB PNG, as raw bytes.
 *
 * fakePng()'s 1x1 transparent pixel cannot go through TCPDF: Image() routes
 * any alpha PNG to ImagePngAlpha(), which fatals with
 * `imagecolorat(): Argument #1 ($image) must be of type GdImage, false given`.
 * Inside PHPUnit's output buffer that fatal kills the runner silently — the
 * suite exits 0 with every later test file unrun. Anything that actually
 * renders a signature into a PDF must use this instead.
 */
function stampablePng(int $width = 120, int $height = 40): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
    imageline($image, 5, $height - 10, $width - 5, 10, imagecolorallocate($image, 0, 0, 0));

    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

/**
 * A syntactically valid single-page PDF that FPDI can import.
 */
function minimalPdf(): string
{
    return base64_decode(
        'JVBERi0xLjQKMSAwIG9iago8PC9UeXBlIC9DYXRhbG9nIC9QYWdlcyAyIDAgUj4+CmVuZG9iagoy'
        .'IDAgb2JqCjw8L1R5cGUgL1BhZ2VzIC9LaWRzIFszIDAgUl0gL0NvdW50IDE+PgplbmRvYmoKMyAw'
        .'IG9iago8PC9UeXBlIC9QYWdlIC9QYXJlbnQgMiAwIFIgL01lZGlhQm94IFswIDAgNjEyIDc5Ml0+'
        .'PgplbmRvYmoKeHJlZgowIDQKMDAwMDAwMDAwMCA2NTUzNSBmIAowMDAwMDAwMDA5IDAwMDAwIG4g'
        .'CjAwMDAwMDAwNTYgMDAwMDAgbiAKMDAwMDAwMDExMSAwMDAwMCBuIAp0cmFpbGVyCjw8L1NpemUg'
        .'NCAvUm9vdCAxIDAgUj4+CnN0YXJ0eHJlZgoxODAKJSVFT0YK'
    );
}

/**
 * Registers the AR template with a renderer stub, so these tests exercise the
 * session state machine without needing DomPDF/Imagick installed.
 */
function arSessionTemplate(array $extra = []): void
{
    registerRoutedTemplate('accomplishment-report', [
        'prepared_by' => ['label' => 'Prepared by', 'signatory' => 'preparedBy', 'order' => 1, 'required' => true],
        'attested_by' => ['label' => 'Attested by', 'signatory' => 'attestedBy', 'order' => 2, 'required' => true],
        'noted_by'    => ['label' => 'Noted by',    'signatory' => 'notedBy',    'order' => 3, 'required' => true],
    ], array_merge(['renderer' => \Kukux\DigitalSignature\Tests\Support\StubPdfRenderer::class], $extra));

    foreach (['prepared_by', 'attested_by', 'noted_by'] as $i => $slot) {
        // updateOrCreate so a test can re-register the template with
        // different options without tripping the (template, slot) unique key.
        \Kukux\DigitalSignature\Models\PdfTemplateSlot::updateOrCreate(
            ['template_key' => 'accomplishment-report', 'slot_key' => $slot],
            [
                'page'   => 1,
                'x'      => 100.0 + ($i * 200),
                'y'      => 90.0,
                'width'  => 160.0,
                'height' => 50.0,
            ],
        );
    }
}

/**
 * Tables for the leave-form fixture: people kept apart from logins, and a
 * document that snapshots its reviewer (see tests/Support/LeaveForm).
 */
function leaveFormSchema(): void
{
    \Illuminate\Support\Facades\Schema::create('people', function ($table) {
        $table->id();
        $table->string('name');
        $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
        $table->unsignedBigInteger('supervisor_id')->nullable();
        $table->timestamps();
    });

    \Illuminate\Support\Facades\Schema::create('leave_forms', function ($table) {
        $table->id();
        $table->foreignId('person_id')->constrained('people');
        $table->string('period');
        \Kukux\DigitalSignature\Database\SignatoryColumns::add($table, ['reviewer'], references: 'people');
        $table->timestamps();
        $table->unique(['person_id', 'period']);
    });
}

/**
 * Registers the leave-form template and document, with people mapped to their
 * logins the way a host app binds it.
 *
 * @param  array<string, mixed>  $extra  template config overrides
 */
function registerLeaveForm(array $extra = []): void
{
    registerRoutedTemplate('leave-form', [
        'applicant' => ['label' => 'Applicant', 'signatory' => 'person', 'order' => 1, 'required' => true,
            'page' => 1, 'x' => 60, 'y' => 120, 'width' => 150, 'height' => 40],
        'reviewer' => ['label' => 'Reviewer', 'signatory' => 'reviewer', 'order' => 2, 'required' => true,
            'page' => 1, 'x' => 320, 'y' => 120, 'width' => 150, 'height' => 40],
    ], array_merge(['renderer' => \Kukux\DigitalSignature\Tests\Support\StubPdfRenderer::class], $extra));

    app()->bind(
        \Kukux\DigitalSignature\Contracts\SignatoryUserMapper::class,
        fn () => \Kukux\DigitalSignature\Signatories\RelationUserMapper::using('user'),
    );

    app(\Kukux\DigitalSignature\Services\DocumentRegistry::class)
        ->register('leave-form', \Kukux\DigitalSignature\Tests\Support\LeaveFormDocument::class);
}

/**
 * A person with a login and a registered signature, ready to sign.
 */
function signingPerson(int $id, string $name, ?int $supervisorId = null): \Kukux\DigitalSignature\Tests\Support\Person
{
    $user = makeUser($id, $name);
    makePrimarySignature($user->id);

    return \Kukux\DigitalSignature\Tests\Support\Person::create([
        'id'            => $id,
        'name'          => $name,
        'user_id'       => $user->id,
        'supervisor_id' => $supervisorId,
    ]);
}

/**
 * Replace the cryptographic embed with a stub that writes a distinct PDF per
 * signature, so session and history tests run without certificates. Returns
 * the paths each signature was asked to sign, in order.
 */
function stubSignatureEmbedding(): \ArrayObject
{
    $signed = new \ArrayObject;

    $manager = Mockery::mock(
        \Kukux\DigitalSignature\Services\SignatureManager::class.'[embedAndFinalize]',
        [
            app(\Kukux\DigitalSignature\Services\CertificateService::class),
            app(\Kukux\DigitalSignature\Services\PdfSignerService::class),
            app(\Kukux\DigitalSignature\Security\DuplicateSignatureGuard::class),
            app(\Kukux\DigitalSignature\Security\CrlValidator::class),
            app(\Kukux\DigitalSignature\Security\DocumentIntegrity::class),
            app(\Kukux\DigitalSignature\Security\SignatureMetadataService::class),
        ],
    );
    $manager->shouldAllowMockingProtectedMethods();
    $manager->shouldReceive('embedAndFinalize')
        ->andReturnUsing(function (\Kukux\DigitalSignature\Models\Signature $sig, string $pw, ?string $source = null) use ($signed) {
            $signed[] = $source;
            $bytes = "%PDF-1.4\n% signed by signature {$sig->id} on top of {$source}\n";
            $out = 'signed-docs/'.$sig->uuid.'.pdf';
            \Illuminate\Support\Facades\Storage::disk('testing')->put($out, $bytes);
            $sig->update([
                'signed_document_path' => $out,
                'signed_document_hash' => hash('sha256', $bytes),
                'status'               => 'signed',
                'signed_at'            => now(),
            ]);
        });

    app()->instance(\Kukux\DigitalSignature\Services\SignatureManager::class, $manager);
    app()->forgetInstance(\Kukux\DigitalSignature\Services\SigningSessionManager::class);
    app()->forgetInstance(\Kukux\DigitalSignature\Services\DocumentRouter::class);

    return $signed;
}
