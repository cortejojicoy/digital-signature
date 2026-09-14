<?php

namespace Kukux\DigitalSignature\Tests\Support;

use Illuminate\Foundation\Auth\User;

class TestUser extends User
{
    protected $table = 'users';

    protected $guarded = [];
}
