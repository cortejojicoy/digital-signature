<?php

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Kukux\DigitalSignature\Hub\Webhooks\HubNotifier;
use Kukux\DigitalSignature\Hub\Webhooks\HubWebhookDispatcher;
use Kukux\DigitalSignature\Models\HubHolder;
use Kukux\DigitalSignature\Models\HubWebhook;
use Kukux\DigitalSignature\Tests\Feature\Hub\Api\HubApiEnvironment;

uses(HubApiEnvironment::class);

beforeEach(fn () => $this->setUpHubApi());

/*
 * The webhook outbox (docs/hub/contracts.md §3, plan R6).
 */

it('signs each delivery exactly as the contract says', function () {
    Http::fake(['*' => Http::response('', 200)]);
    $app = $this->registerApp();
    $specimen = $this->signer();
    HubHolder::link($app['app']->id, 'p-juan');

    $this->freezeSecond();
    app(HubNotifier::class)->signatureUpdated('p-juan');

    $webhook = HubWebhook::query()->sole();

    Http::assertSent(function (HttpRequest $request) use ($app, $webhook, $specimen) {
        $timestamp = $request->header('X-Signature-Hub-Timestamp')[0];
        $raw = $request->body();
        $body = json_decode($raw, true);

        return $request->url() === 'https://performance.uplb.test/signature/hub/webhook'
            && $request->method() === 'POST'
            && $request->header('Content-Type')[0] === 'application/json'
            && $request->header('X-Signature-Hub-Id')[0] === $webhook->uuid
            && $request->header('X-Signature-Hub-Event')[0] === 'signature.updated'
            && $timestamp === (string) now()->getTimestamp()
            && $request->header('X-Signature-Hub-Signature')[0] === 'sha256='.hash_hmac('sha256', $timestamp.'.'.$raw, $app['webhook_secret'])
            && $body === [
                'id'         => $webhook->uuid,
                'event'      => 'signature.updated',
                'created_at' => $webhook->created_at->toIso8601String(),
                'data'       => ['sub' => 'p-juan', 'uuid' => $specimen->uuid, 'image_sha256' => $specimen->image_hash],
            ];
    });

    expect($webhook->delivered_at)->not->toBeNull()
        ->and($webhook->attempts)->toBe(1)
        ->and($webhook->last_status)->toBe(200);
});

it('notifies only active holder apps that have a webhook URL', function () {
    Http::fake(['*' => Http::response('', 200)]);
    $holder = $this->registerApp();
    $bystander = $this->registerApp('sims');
    $disabled = $this->registerApp('amp');
    $silent = $this->registerApp('tks', webhook: null);
    $this->signer();

    HubHolder::link($holder['app']->id, 'p-juan');
    HubHolder::link($disabled['app']->id, 'p-juan');
    HubHolder::link($silent['app']->id, 'p-juan');
    $disabled['app']->update(['active' => false]);

    app(HubNotifier::class)->personSeparated('p-juan');

    expect(HubWebhook::query()->pluck('app_id')->all())->toBe([$holder['app']->id])
        ->and(HubWebhook::query()->sole()->payload)->toBe(['sub' => 'p-juan']);
});

it('backs off from 1 minute doubling to 24 hours, then gives up', function () {
    config(['signature.hub.webhook_max_attempts' => 14]);
    Http::fake(['*' => Http::response('nope', 500)]);
    $app = $this->registerApp();
    HubHolder::link($app['app']->id, 'p-juan');
    $dispatcher = app(HubWebhookDispatcher::class);

    $this->freezeSecond();
    app(HubNotifier::class)->personSeparated('p-juan');      // attempt 1, via the queue

    $webhook = HubWebhook::query()->sole();
    expect($webhook)->attempts->toBe(1)->last_status->toBe(500)->last_error->toBe('nope')
        ->and($webhook->next_attempt_at->diffInSeconds(now(), true))->toEqual(60.0);

    // Not due yet: the sweep leaves it alone.
    expect($dispatcher->deliverDue())->toBe(0)->and($webhook->refresh()->attempts)->toBe(1);

    $delays = [];
    for ($i = 2; $i <= 14; $i++) {
        $this->travelTo($webhook->next_attempt_at);
        $dispatcher->deliverDue();
        $webhook->refresh();
        $delays[] = $webhook->next_attempt_at?->diffInSeconds(now(), true);
    }

    expect($delays)->toEqual([120, 240, 480, 960, 1920, 3840, 7680, 15360, 30720, 61440, 86400, 86400, null])
        ->and($webhook->attempts)->toBe(14)
        ->and($webhook->delivered_at)->toBeNull();

    expect(HubWebhookDispatcher::backoff(1))->toBe(60)
        ->and(HubWebhookDispatcher::backoff(2))->toBe(120)
        ->and(HubWebhookDispatcher::backoff(20))->toBe(86400);
});

it('delivers a due retry once the app is back, and redelivers on demand', function () {
    $app = $this->registerApp();
    HubHolder::link($app['app']->id, 'p-juan');

    Http::fakeSequence()->push('down', 503)->push('', 204)->push('', 204);

    app(HubNotifier::class)->personSeparated('p-juan');
    $webhook = HubWebhook::query()->sole();
    expect($webhook->delivered_at)->toBeNull();

    $this->travel(61)->seconds();
    $this->artisan('signature:hub-webhooks')->expectsOutputToContain('delivered 1 webhook(s)')->assertSuccessful();

    expect($webhook->refresh())->delivered_at->not->toBeNull()->attempts->toBe(2)->last_status->toBe(204);

    // Redeliver: the admin's button, regardless of state.
    $this->artisan('signature:hub-webhooks', ['--redeliver' => $webhook->uuid])->assertSuccessful();

    expect($webhook->refresh()->attempts)->toBe(3);
    Http::assertSentCount(3);
});

it('records connection errors and keeps the row for retry', function () {
    Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection refused'));
    $app = $this->registerApp();
    HubHolder::link($app['app']->id, 'p-juan');

    app(HubNotifier::class)->personSeparated('p-juan');

    expect(HubWebhook::query()->sole())
        ->delivered_at->toBeNull()
        ->last_status->toBeNull()
        ->last_error->toContain('Connection refused')
        ->next_attempt_at->not->toBeNull();
});
