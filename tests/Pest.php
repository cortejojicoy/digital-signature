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
