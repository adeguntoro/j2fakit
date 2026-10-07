<?php

namespace Adeguntoro\J2FAKit\Tests\Support;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Minimal user for gate tests. Never touches a database: actingAs()
 * hands this instance straight to the request, and the gate only reads
 * attributes off it.
 */
class User extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}
