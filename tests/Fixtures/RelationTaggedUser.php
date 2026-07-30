<?php

namespace Multek\OneSignal\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Multek\OneSignal\Concerns\HasOneSignal;

class RelationTaggedUser extends Model
{
    use HasOneSignal;

    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function getOneSignalTags(): array
    {
        return ['role' => (string) $this->role->name];
    }
}
