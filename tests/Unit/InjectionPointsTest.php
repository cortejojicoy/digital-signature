<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Kukux\DigitalSignature\Drivers\PdfSigners\Contracts\PdfSignerDriver;
use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Pdf\BladePdfTemplate;
use Kukux\DigitalSignature\Pdf\Renderers\DomPdfRenderer;
use Kukux\DigitalSignature\Pdf\SlotDefinition;
use Kukux\DigitalSignature\Routing\RoutingResult;
use Kukux\DigitalSignature\Security\DocumentIntegrity;
use Kukux\DigitalSignature\Services\PdfSignerService;
use Kukux\DigitalSignature\Services\PdfTemplateRegistry;
use Kukux\DigitalSignature\Signatories\CallableResolver;
use Kukux\DigitalSignature\Signatories\IdentityUserMapper;
use Kukux\DigitalSignature\Signatories\RelationUserMapper;
use Kukux\DigitalSignature\SignaturePlugin;
use Kukux\DigitalSignature\Tests\Support\Person;
use Kukux\DigitalSignature\Tests\Support\TestUser;

describe('Signatory user mappers', function () {

    beforeEach(fn () => leaveFormSchema());

    it('passes a login through and refuses anything else by default', function () {
        $user = makeUser(5, 'A User');
        $person = Person::create(['name' => 'A Person', 'user_id' => 5]);

        expect((new IdentityUserMapper)->toUser($user))->toBe($user)
            // The fix for D4: a Personnel-like row is never mistaken for a user id.
            ->and((new IdentityUserMapper)->toUser($person))->toBeNull();
    });

    it('follows a relation to the login, and passes logins through', function () {
        makeUser(5, 'A User');
        $person = Person::create(['name' => 'A Person', 'user_id' => 5]);
        $orphan = Person::create(['name' => 'No Login']);

        $mapper = RelationUserMapper::using('user');

        expect($mapper->toUser($person))->toBeInstanceOf(TestUser::class)
            ->and($mapper->toUser($person)->getKey())->toBe(5)
            ->and($mapper->toUser($orphan))->toBeNull()
            ->and($mapper->toUser(TestUser::find(5))->getKey())->toBe(5);
    });
});

describe('RoutingResult wording', function () {

    it('uses the package default, then the definition, then an explicit line', function () {
        $default = RoutingResult::refused(RoutingResult::MISSING_SIGNATORIES, ['roles' => 'Noted By']);

        expect($default->title())->toBe('Signatories not set')
            ->and($default->body())->toBe('Set Noted By before routing this document.');

        $overridden = $default->withMessages(['missing_signatories' => ['body' => 'Set :roles under "Update Signatures".']]);

        expect($overridden->body())->toBe('Set Noted By under "Update Signatures".')
            ->and($overridden->title())->toBe('Signatories not set');

        $explicit = RoutingResult::refused('custom', ['n' => '3'], title: 'Custom', body: ':n things');

        expect($explicit->withMessages(['custom' => ['body' => 'ignored']])->body())->toBe('3 things');
    });

    it('still says something for a guard code with no wording anywhere', function () {
        expect(RoutingResult::refused('itinerary_not_approved')->title())->toBe('Itinerary not approved');
    });
});

describe('A1: SignaturePlugin::templates() keeps Blade template keys', function () {

    it('registers a keyed array template alongside a class', function () {
        $panel = Filament\Panel::make()->id('t');

        SignaturePlugin::make()
            ->withoutResource()
            ->withoutFloatingLauncher()
            ->templates(['payslip' => ['view' => 'blank', 'slots' => ['employee']]])
            ->register($panel);

        expect(app(PdfTemplateRegistry::class)->has('payslip'))->toBeTrue();
    });
});

describe('A3: one path rule', function () {

    it('hashes a document given by its absolute path', function () {
        Storage::fake('testing');
        Storage::disk('testing')->put('generated/x/doc.pdf', '%PDF-1.4 abc');

        $absolute = Storage::disk('testing')->path('generated/x/doc.pdf');

        expect(app(DocumentIntegrity::class)->hash($absolute))
            ->toBe(app(DocumentIntegrity::class)->hash('generated/x/doc.pdf'));
    });
});

describe('A4: a closure binding that throws means nobody is assigned', function () {

    it('returns null instead of crashing', function () {
        $resolver = new CallableResolver(fn (Model $record) => $record->department->head);
        $record = new class extends Model {};

        expect($resolver->resolve($record, new SlotDefinition(key: 'head', label: 'Head')))->toBeNull();
    });
});

describe('H1: every signed version gets a file of its own', function () {

    it('moves two outputs with the same driver name to separate versions', function () {
        Storage::fake('testing');
        makeUser(1, 'Signer');

        $driver = new class implements PdfSignerDriver {
            public int $calls = 0;

            public function sign(string $pdfPath, string $imagePath, array $position, array $certData, string $reason = '', string $qrPayload = '', array $caption = [], array $extraPositions = []): string
            {
                $this->calls++;

                // The old naming: same source, same second, same name.
                Storage::disk('testing')->put('signed-docs/base_signed_1700000000.pdf', "%PDF signer {$this->calls}");

                return 'signed-docs/base_signed_1700000000.pdf';
            }
        };

        $service = new PdfSignerService($driver);
        $paths = [];

        foreach ([1, 2] as $n) {
            $signature = Signature::create([
                'uuid' => (string) Str::uuid(), 'user_id' => 1, 'image_path' => 'signatures/x.png',
                'image_hash' => str_repeat('a', 64), 'source' => 'draw', 'status' => 'pending',
            ]);

            $paths[] = $service->sign($signature, [], 'documents/base.pdf');
        }

        expect($paths[0])->not->toBe($paths[1])
            ->and(Storage::disk('testing')->get($paths[0]))->toBe('%PDF signer 1')
            ->and(Storage::disk('testing')->get($paths[1]))->toBe('%PDF signer 2');
    });
});

describe('Paper size', function () {

    it('pins the stock renderer\'s paper from template config', function () {
        $template = BladePdfTemplate::fromConfig('legal-form', ['view' => 'blank', 'paper' => 'legal', 'orientation' => 'landscape']);

        $detect = new ReflectionMethod($template, 'detectRenderer');

        if (! DomPdfRenderer::isAvailable()) {
            // No DomPDF in the package's own dev dependencies: check the
            // values the renderer would be built with.
            $paper = new ReflectionProperty($template, 'paper');
            $orientation = new ReflectionProperty($template, 'orientation');

            expect($paper->getValue($template))->toBe('legal')
                ->and($orientation->getValue($template))->toBe('landscape');

            return;
        }

        $renderer = $detect->invoke($template);

        expect((new ReflectionProperty($renderer, 'paper'))->getValue($renderer))->toBe('legal');
    });
});
