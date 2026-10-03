<?php

namespace Kukux\DigitalSignature\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Kukux\DigitalSignature\Testing\SignableDocumentContract;
use Kukux\DigitalSignature\Tests\Support\LeaveLedger;
use Kukux\DigitalSignature\Tests\TestCase;

/**
 * The shared contract an app runs against its own document, run here against
 * the leave-form fixture so the trait itself is known to work.
 */
class SignableDocumentContractTest extends TestCase
{
    use SignableDocumentContract;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('testing');
        leaveFormSchema();
        registerLeaveForm();

        signingPerson(21, 'Dr Reyes');
        signingPerson(22, 'Juana Cruz', supervisorId: 21);
        signingPerson(23, 'No Supervisor');

        $this->app->instance(LeaveLedger::class, new LeaveLedger([22 => 3, 23 => 1]));
    }

    protected function documentKey(): string
    {
        return 'leave-form';
    }

    protected function routableSubject(): array
    {
        return [\Kukux\DigitalSignature\Tests\Support\Person::find(22), ['period' => '2026-09']];
    }

    protected function unroutableSubject(): ?array
    {
        return [\Kukux\DigitalSignature\Tests\Support\Person::find(23), ['period' => '2026-09']];
    }
}
