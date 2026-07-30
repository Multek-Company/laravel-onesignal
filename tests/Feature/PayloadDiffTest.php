<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Multek\OneSignal\Tests\Fixtures\CastingUser;
use Multek\OneSignal\Tests\Fixtures\RelationTaggedUser;
use Multek\OneSignal\Tests\Fixtures\Role;
use Multek\OneSignal\Tests\Fixtures\User;

function payloadUser(array $attributes = []): User
{
    return (new User)->forceFill(array_merge([
        'id' => 1,
        'email' => 'ana@example.com',
        'phone' => '+5511999999999',
    ], $attributes));
}

it('builds a payload from identity, tags, properties and subscriptions', function () {
    config(['onesignal.default_tags' => ['plan' => 'subscription_plan']]);

    $user = new class extends User
    {
        public function getOneSignalLanguage(): ?string
        {
            return 'pt';
        }

        public function getOneSignalTimezone(): ?string
        {
            return 'America/Sao_Paulo';
        }
    };

    $user->forceFill([
        'id' => 7,
        'email' => 'ana@example.com',
        'phone' => '+5511999999999',
        'subscription_plan' => 'pro',
    ]);

    expect($user->toOneSignalPayload())->toBe([
        'external_id' => '7',
        'tags' => ['plan' => 'pro'],
        'properties' => ['language' => 'pt', 'timezone_id' => 'America/Sao_Paulo'],
        'email' => 'ana@example.com',
        'phone' => '+5511999999999',
    ]);
});

it('sorts tags and properties by key', function () {
    $user = new class extends User
    {
        public function getOneSignalTags(): array
        {
            return ['zeta' => '1', 'alpha' => '2'];
        }

        public function getOneSignalTimezone(): ?string
        {
            return 'America/Sao_Paulo';
        }

        public function getOneSignalCountry(): ?string
        {
            return 'BR';
        }
    };

    $payload = $user->forceFill(['id' => 1])->toOneSignalPayload();

    expect(array_keys($payload['tags']))->toBe(['alpha', 'zeta'])
        ->and(array_keys($payload['properties']))->toBe(['country', 'timezone_id']);
});

it('omits a non-E164 phone from the payload without logging', function () {
    Log::spy();

    $payload = payloadUser(['phone' => '11 99999-9999'])->toOneSignalPayload();

    expect($payload['phone'])->toBeNull();

    Log::shouldNotHaveReceived('warning');
});

it('reports no change when the save touched nothing OneSignal cares about', function () {
    $user = payloadUser(['name' => 'Ana']);
    $user->syncOriginal();

    $user->name = 'Ana Maria';

    expect($user->oneSignalPayloadChanged())->toBeFalse();
});

it('reports a change when a tag-mapped attribute changed', function () {
    config(['onesignal.default_tags' => ['plan' => 'subscription_plan']]);

    $user = payloadUser(['subscription_plan' => 'free']);
    $user->syncOriginal();

    $user->subscription_plan = 'pro';

    expect($user->oneSignalPayloadChanged())->toBeTrue();
});

it('reports a change for a model that was never saved', function () {
    expect(payloadUser()->oneSignalPayloadChanged())->toBeTrue();
});

it('detects a foreign key change even when the relation was eagerly loaded', function () {
    Schema::create('roles', function ($table) {
        $table->id();
        $table->string('name');
    });

    Schema::create('users', function ($table) {
        $table->id();
        $table->string('email')->nullable();
        $table->string('phone')->nullable();
        $table->foreignId('role_id');
    });

    Role::query()->insert([
        ['id' => 1, 'name' => 'member'],
        ['id' => 2, 'name' => 'admin'],
    ]);

    $user = RelationTaggedUser::create(['email' => 'ana@example.com', 'role_id' => 1]);
    $user->load('role');

    expect($user->toOneSignalPayload()['tags'])->toBe(['role' => 'member']);

    $user->role_id = 2;

    expect($user->oneSignalPayloadChanged())->toBeTrue();
});

it('diffs correctly when a payload-relevant attribute uses a cast', function () {
    Schema::create('users', function ($table) {
        $table->id();
        $table->string('email')->nullable();
        $table->string('phone')->nullable();
        $table->json('prefs')->nullable();
    });

    config(['onesignal.default_tags' => [
        'prefs_count' => fn ($u) => (string) count($u->prefs ?? []),
    ]]);

    // create() persists and syncs original, so the "previous" side of the
    // diff is non-empty and the clone path in oneSignalPayloadFrom() is
    // actually exercised (a never-saved model would short-circuit instead).
    $user = CastingUser::create(['email' => 'ana@example.com', 'prefs' => ['a', 'b']]);

    $user->prefs = ['a', 'b', 'c'];

    expect($user->oneSignalPayloadChanged())->toBeTrue();
});
