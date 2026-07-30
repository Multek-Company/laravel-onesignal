<?php

namespace Multek\OneSignal\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Multek\OneSignal\Concerns\HasOneSignal;

class CastingUser extends Model
{
    use HasOneSignal;

    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'prefs' => 'array',
    ];
}
