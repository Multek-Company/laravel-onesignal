# Laravel OneSignal

Laravel wrapper for the official [OneSignal PHP SDK](https://github.com/OneSignal/onesignal-php-api). Send push notifications, manage users, and track custom events with a clean, fluent API.

Also implements the [`laravel-customer-engagement`](https://github.com/Multek-Company/laravel-customer-engagement) driver contracts, so you can use OneSignal as a drop-in provider for the unified engagement platform.

## Features

- **Push Notifications** - Send to users, segments, or build complex payloads with a fluent builder
- **User Management** - Create, update, and delete OneSignal users with tag sync
- **Custom Event Tracking** - Track events for analytics and segmentation
- **Laravel Notification Channel** - Send via Laravel's native notification system
- **Model Trait** - `HasOneSignal` trait for syncing Eloquent models to OneSignal
- **Async Jobs** - Queue-based user sync with `SyncUserToOneSignal`
- **Customer Engagement Driver** - Implements `EngagementDriver`, `SyncsUsers`, `SendsNotifications`, and `TracksEvents` contracts
- **Events** - `NotificationSent` and `NotificationFailed` events for observability

## Requirements

- PHP 8.2+
- Laravel 11.0+

## Installation

```bash
composer require multek/laravel-onesignal
```

Publish the config file:

```bash
php artisan vendor:publish --tag=onesignal-config
```

Add your credentials to `.env`:

```env
ONESIGNAL_APP_ID=your-app-id
ONESIGNAL_REST_API_KEY=your-rest-api-key
```

## Usage

### Sending Notifications

```php
use Multek\OneSignal\Facades\OneSignal;

// Simple push to a user
OneSignal::sendToUser('user_123', 'Hello!');

// Push to multiple users
OneSignal::sendToUsers(['user_1', 'user_2'], 'Hello everyone!');

// Push to a segment
OneSignal::sendToSegment('Active Users', 'New feature available!');

// Fluent builder for complex notifications
OneSignal::notification()
    ->toUser('user_123')
    ->heading('Order Shipped')
    ->body('Your order #456 has been shipped.')
    ->data(['order_id' => 456])
    ->send();
```

### User Management

```php
// Create a user with tags
OneSignal::createUser('user_123', ['plan' => 'pro', 'role' => 'admin']);

// Update tags
OneSignal::updateUserTags('user_123', ['plan' => 'enterprise']);

// Remove tags
OneSignal::removeUserTags('user_123', ['role']);

// Get user
OneSignal::getUser('user_123');

// Delete user
OneSignal::deleteUser('user_123');
```

### Model Trait

```php
use Multek\OneSignal\Concerns\HasOneSignal;

class User extends Authenticatable
{
    use HasOneSignal;
}

// Sync user to OneSignal
$user->syncToOneSignal();
```

### Custom Event Tracking

```php
// Track a single event
OneSignal::trackEvent('user_123', 'purchase', ['amount' => 99.90]);

// Track for multiple users
OneSignal::trackEventForUsers(['user_1', 'user_2'], 'promo_viewed', ['campaign' => 'summer']);
```

### Laravel Notification Channel

```php
use Multek\OneSignal\Messages\OneSignalMessage;

class OrderShipped extends Notification
{
    public function via($notifiable): array
    {
        return ['onesignal'];
    }

    public function toOneSignal($notifiable): OneSignalMessage
    {
        return (new OneSignalMessage)
            ->heading('Order Shipped')
            ->body('Your order has been shipped.');
    }
}
```

### Accessing the Raw SDK

```php
$api = OneSignal::api(); // Returns onesignal\client\api\DefaultApi
```

## Configuration

See `config/onesignal.php` for all available options including default tags, queue name, and organization API key.

## Customer Engagement Integration

When used with `multek/laravel-customer-engagement`, OneSignal registers itself as a driver automatically:

```php
// config/customer-engagement.php
'default' => 'onesignal',

// Then use the unified API
use Multek\CustomerEngagement\Facades\Engagement;

Engagement::sendToUser('user_123', $notification);
```

## Testing

```bash
composer test
```

## License

MIT
