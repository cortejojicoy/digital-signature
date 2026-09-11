<?php

namespace Kukux\DigitalSignature\Contracts;

/**
 * Optional companion to PdfTemplate: lets a template override how its
 * documents get signed, instead of inheriting the package defaults.
 *
 * Implement it when one template needs different rules from the rest — e.g.
 * payslips sign in any order while an accomplishment report must go
 * prepared → attested → noted.
 */
interface ConfiguresSigningSession
{
    /**
     * Consent model: 'approval' | 'delegated' | 'implicit'.
     * See docs/signatory-routing.md for what each one permits.
     */
    public function autoAffixMode(): string;

    /**
     * 'sequential' honours SlotDefinition::$order; 'parallel' lets any
     * signatory act at any time.
     */
    public function sequenceMode(): string;
}
