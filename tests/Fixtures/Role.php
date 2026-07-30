<?php

namespace Multek\OneSignal\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $table = 'roles';

    protected $guarded = [];

    public $timestamps = false;
}
