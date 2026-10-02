<?php

namespace Kukux\DigitalSignature\Tests\Support;

use Illuminate\Foundation\Auth\User;
use Illuminate\Notifications\Notifiable;

class TestUser extends User
{
    use Notifiable;

    protected $table = 'users';

    protected $guarded = [];
}
