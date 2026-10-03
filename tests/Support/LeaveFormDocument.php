<?php

namespace Kukux\DigitalSignature\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Kukux\DigitalSignature\Documents\AbstractSignableDocument;

/**
 * A document definition built the way the docs tell an app to build one:
 * locate/open from a subject + context, a preflight guard, defaults injected.
 */
class LeaveFormDocument extends AbstractSignableDocument
{
    public function __construct(private SupervisorDefaults $defaults)
    {
    }

    public function template(): string
    {
        return 'leave-form';
    }

    public function preflight(): array
    {
        return [HasLeaveDays::class];
    }

    public function locate(mixed $person, array $context = []): ?Model
    {
        return LeaveForm::query()
            ->where('person_id', $person->getKey())
            ->where('period', $context['period'])
            ->first();
    }

    public function open(mixed $person, array $context = []): Model
    {
        $form = $this->locate($person, $context)
            ?? new LeaveForm(['person_id' => $person->getKey(), 'period' => $context['period']]);

        $form->snapshotSignatories($this->defaults);
        $form->save();

        return $form;
    }

    public function messages(): array
    {
        return ['missing_signatories' => ['body' => 'Ask HR to set your :roles first.']];
    }
}
