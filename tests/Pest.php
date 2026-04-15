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
