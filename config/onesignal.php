<?php

return [
    /*
    |--------------------------------------------------------------------------
    | OneSignal App ID
    |--------------------------------------------------------------------------
    | Same App ID used in mobile app (Expo) and web push.
    | Found in OneSignal Dashboard → Settings → Keys & IDs
    */
    'app_id' => env('ONESIGNAL_APP_ID'),

    /*
    |--------------------------------------------------------------------------
    | REST API Key
    |--------------------------------------------------------------------------
    | Used for server-side API calls (sending notifications, managing users).
    | Found in OneSignal Dashboard → Settings → Keys & IDs
    */
    'rest_api_key' => env('ONESIGNAL_REST_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Organization API Key (optional)
    |--------------------------------------------------------------------------
    | Only needed for app-level management (creating apps, etc).
    | Most projects won't need this.
    */
    'organization_api_key' => env('ONESIGNAL_ORGANIZATION_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Default Tags
    |--------------------------------------------------------------------------
    | Tags to sync automatically when calling $user->syncToOneSignal().
    | Maps OneSignal tag keys to User model attributes or closures.
    |
    | Example:
    |   'plan' => 'subscription_plan',             // $user->subscription_plan
    |   'role' => fn($user) => $user->role->name,  // closure
    */
    'default_tags' => [
        // 'plan' => 'subscription_plan',
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    | Queue name for async operations (user sync, batch sends).
    | Use QUEUE_CONNECTION=sync in .env to run synchronously.
    */
    'queue' => env('ONESIGNAL_QUEUE', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    | Enable or disable the OneSignal package. When disabled, all API calls
    | are silently skipped (no-op) and debug logs are written.
    | The package also disables itself when app_id is empty.
    */
    'enabled' => env('ONESIGNAL_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Track Events
    |--------------------------------------------------------------------------
    | Enable custom event tracking. Free plan rejects custom events with 403.
    */
    'track_events' => env('ONESIGNAL_TRACK_EVENTS', false),

    /*
    |--------------------------------------------------------------------------
    | Sync Model
    |--------------------------------------------------------------------------
    | The model class to use for backfill operations (onesignal:backfill command).
    | Example: App\Models\User::class
    */
    'sync_model' => env('ONESIGNAL_SYNC_MODEL'),
];
