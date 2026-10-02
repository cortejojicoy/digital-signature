<?php

namespace Kukux\DigitalSignature\Console\Install\Steps;

use Kukux\DigitalSignature\Agent\AgentServer;
use Kukux\DigitalSignature\Console\Install\EnvFile;
use Kukux\DigitalSignature\Console\Install\InstallContext;
use Kukux\DigitalSignature\Console\Install\InstallStep;
use Kukux\DigitalSignature\Console\Install\StepResult;

/**
 * Appends the starter SIGNATURE_* settings (the ones docs/installation.md
 * recommends deciding up front) to .env and .env.example. Only missing keys
 * are added; an existing value is never touched.
 */
class UpdateEnv implements InstallStep
{
    protected const HEADING = "###########################################################\n"
        ."# Digital signatures (kukux/digital-signature)\n"
        .'###########################################################';

    public function label(): string
    {
        return 'Updating .env';
    }

    public function summary(): string
    {
        return 'add the SIGNATURE_* settings to .env';
    }

    public function enabled(InstallContext $context): bool
    {
        return true;
    }

    public function run(InstallContext $context): StepResult
    {
        $env = new EnvFile($context->path('.env'));

        if (! $env->exists()) {
            return StepResult::manual([
                'No .env file. Copy .env.example to .env (php artisan key:generate), then rerun.',
            ]);
        }

        $groups = $this->groups($context, forExample: false);
        $block = $env->missingBlock($groups, self::HEADING);
        $example = new EnvFile($context->path('.env.example'));
        $exampleBlock = $example->exists() ? $example->missingBlock($this->groups($context, forExample: true), self::HEADING) : '';

        if (($env->get('SIGNATURE_TSA_URL') ?? '') === '') {
            $context->next[] = 'Set SIGNATURE_TSA_URL if you want trusted timestamps';
        }

        $total = array_sum(array_map(fn (array $g) => count($g['keys']), $groups));
        $added = preg_match_all('/^SIGNATURE_[A-Z_]+=/m', $block);
        $kept = $total - $added;

        if ($block === '' && $exampleBlock === '') {
            return StepResult::skipped('every SIGNATURE_* setting is already in .env');
        }

        if ($context->dryRun()) {
            return StepResult::dryRun(["would add {$added} keys to .env, keeping {$kept} you already have"]);
        }

        if ($block !== '') {
            $env->append($block);
        }

        if ($exampleBlock !== '') {
            $example->append($exampleBlock);
        }

        $details = ["added {$added} keys, kept {$kept} you already had"];

        if ($exampleBlock !== '') {
            $details[] = 'and the same keys to .env.example';
        }

        return StepResult::done($details);
    }

    /**
     * @return list<array{comment: list<string>, keys: array<string, string>}>
     */
    protected function groups(InstallContext $context, bool $forExample): array
    {
        $groups = [
            [
                'comment' => ['Certificates and signed PDFs. Must be a PRIVATE disk; `local` is', 'storage/app/private. Never `public`.'],
                'keys'    => ['SIGNATURE_DISK' => 'local', 'SIGNATURE_CERT_DRIVER' => 'openssl', 'SIGNATURE_PDF_DRIVER' => 'fpdi'],
            ],
            [
                'comment' => ['Consent. `approval` never signs on someone\'s behalf: routing finds the', 'signatory and places the block, but they sign in their own session.'],
                'keys'    => ['SIGNATURE_AUTO_AFFIX_MODE' => 'approval', 'SIGNATURE_ALLOW_IMPLICIT_AFFIX' => 'false', 'SIGNATURE_AUTO_AFFIX_NOTIFY' => 'true'],
            ],
            [
                'comment' => ['Signing order (sequential | parallel) and progressive | incremental.'],
                'keys'    => ['SIGNATURE_SEQUENCE_MODE' => 'sequential', 'SIGNATURE_MULTI_MODE' => 'progressive'],
            ],
            [
                'comment' => ['Blocks re-uploading a signature from another machine. Leave it on.'],
                'keys'    => ['SIGNATURE_MACHINE_LOCK' => 'true'],
            ],
            [
                'comment' => ['Only turn on if your CA publishes a CRL.'],
                'keys'    => ['SIGNATURE_CRL_ENABLED' => 'false'],
            ],
            [
                'comment' => ['RFC 3161 timestamping. Blank = off. Free option: https://freetsa.org/tsr'],
                'keys'    => ['SIGNATURE_TSA_URL' => ''],
            ],
        ];

        if ($context->option('agent')) {
            // Pin today's derived values rather than inventing new ones: any
            // computer already paired keeps working, and rotating APP_KEY
            // later no longer changes them.
            $groups[] = [
                'comment' => ['Desktop agent. Server ID and salt are pinned so rotating APP_KEY', 'doesn\'t force every paired computer to pair again.'],
                'keys'    => [
                    'SIGNATURE_AGENT_ENABLED'   => 'true',
                    'SIGNATURE_AGENT_SERVER_ID' => $forExample ? '' : AgentServer::id(),
                    'SIGNATURE_AGENT_SALT'      => $forExample ? '' : AgentServer::salt(),
                ],
            ];
        }

        return $groups;
    }
}
