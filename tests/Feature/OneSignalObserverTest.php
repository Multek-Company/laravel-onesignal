<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use Multek\OneSignal\Jobs\DeleteUserFromOneSignal;
use Multek\OneSignal\Jobs\SyncUserToOneSignal;
use Multek\OneSignal\Observers\OneSignalObserver;
use Multek\OneSignal\Tests\Fixtures\RelationTaggedUser;
use Multek\OneSignal\Tests\Fixtures\Role;
use Multek\OneSignal\Tests\Fixtures\SoftDeletingUser;
use Multek\OneSignal\Tests\Fixtures\User;

beforeEach(function () {
    Schema::create('users', function ($table) {
        $table->id();
        $table->string('email')->nullable();
        $table->string('phone')->nullable();
        $table->string('subscription_plan')->nullable();
    });

    // Fake before any create(). Testbench's default queue connection is `sync`,
    // so an unfaked dispatch would run SyncUserToOneSignal inline and attempt a
    // real HTTP call. Re-calling Bus::fake() later installs a fresh fake, which
    // is how each test clears the jobs its setup produced.
    Bus::fake();

    User::observe(OneSignalObserver::class);
});

it('dispatches a sync when a save changed the payload', function () {
    config(['onesignal.default_tags' => ['plan' => 'subscription_plan']]);
    $user = User::create(['email' => 'ana@example.com', 'subscription_plan' => 'free']);

    Bus::fake();
    $user->update(['subscription_plan' => 'pro']);

    Bus::assertDispatchedTimes(SyncUserToOneSignal::class, 1);
});

it('dispatches nothing when a save left the payload untouched', function () {
    config(['onesignal.default_tags' => []]);
    $user = User::create(['email' => 'ana@example.com', 'subscription_plan' => 'free']);

    Bus::fake();
    $user->update(['subscription_plan' => 'pro']);

    Bus::assertNothingDispatched();
});

it('dispatches a sync on create', function () {
    Bus::fake();

    User::create(['email' => 'ana@example.com']);

    Bus::assertDispatchedTimes(SyncUserToOneSignal::class, 1);
});

it('dispatches a delete when a model without SoftDeletes is deleted', function () {
    $user = User::create(['email' => 'ana@example.com']);

    Bus::fake();
    $user->delete();

    Bus::assertDispatched(
        DeleteUserFromOneSignal::class,
        fn (DeleteUserFromOneSignal $job) => $job->externalId === (string) $user->id,
    );
});

it('keeps the profile on a soft delete and removes it on a force delete', function () {
    Schema::table('users', function ($table) {
        $table->softDeletes();
    });

    SoftDeletingUser::observe(OneSignalObserver::class);
    $user = SoftDeletingUser::create(['email' => 'ana@example.com']);

    Bus::fake();

    $user->delete();
    Bus::assertNotDispatched(DeleteUserFromOneSignal::class);

    $user->forceDelete();
    Bus::assertDispatchedTimes(DeleteUserFromOneSignal::class, 1);
});

it('dispatches a sync when a relation-tagged model is created', function () {
    Schema::create('roles', function ($table) {
        $table->id();
        $table->string('name');
    });

    Schema::table('users', function ($table) {
        $table->foreignId('role_id')->nullable();
    });

    Role::query()->insert(['id' => 1, 'name' => 'member']);

    RelationTaggedUser::observe(OneSignalObserver::class);

    Bus::fake();

    // On create, Eloquent fires `saved` before syncOriginal(), so the
    // "previous" side of the diff is built from an empty attribute set. A
    // relation-walking tag getter must not blow up against that.
    RelationTaggedUser::create(['email' => 'ana@example.com', 'role_id' => 1]);

    Bus::assertDispatchedTimes(SyncUserToOneSignal::class, 1);
});

it('dispatches a sync when a soft-deleted model is restored', function () {
    Schema::table('users', function ($table) {
        $table->softDeletes();
    });

    SoftDeletingUser::observe(OneSignalObserver::class);
    $user = SoftDeletingUser::create(['email' => 'ana@example.com']);
    $user->delete();

    Bus::fake();
    $user->restore();

    Bus::assertDispatchedTimes(SyncUserToOneSignal::class, 1);
});
