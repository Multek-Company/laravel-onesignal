# Client-side integration (web & mobile)

This package is the **server half** of a OneSignal integration. It owns everything that comes from your database — tags, native properties, Email/SMS subscriptions, custom events — and it sends notifications.

It cannot, by design, do the one thing that only a device can do: **register a push subscription**. Push tokens are created by the OneSignal client SDKs running in the browser or in your app. Without them, `OneSignal::sendToUser()` has nobody to deliver to.

So a complete setup is always two halves:

| Layer | What you install | Responsibility |
|---|---|---|
| Browser | [`react-onesignal`](https://github.com/OneSignal/react-onesignal) (Web SDK v16) | permission prompt, push subscription, `login(externalId)` |
| Mobile app | [`react-native-onesignal`](https://github.com/OneSignal/react-native-onesignal) v5 + `onesignal-expo-plugin` | same, natively |
| Laravel | **this package** | tags, properties, email/phone, events, sending, backfill |

All three talk to the same OneSignal app — one `app_id`, with Web, iOS and Android enabled in the dashboard.

## How the platforms merge: `external_id`

A OneSignal user is `external_id` + N subscriptions. The same person's Chrome push, iPhone push, email and SMS all hang off one user record:

```
                              OneSignal User (external_id: "42")
                              ├─ subscription: ChromePush   ← Web SDK login("42")
Laravel (this package) ─────► ├─ subscription: iOSPush      ← Mobile SDK login("42")
  tags, properties,           ├─ subscription: Email        ← syncToOneSignal()
  email/phone, sending        └─ subscription: SMS          ← syncToOneSignal()
```

The join key is the value your model returns from `getOneSignalExternalId()` — by default `(string) $user->getKey()`. **Every client SDK must call `login()` with that exact same string**, and `logout()` when the user signs out. That is the whole contract; get it right and cross-platform delivery just works.

## Part A — Web SDK

```bash
npm install react-onesignal
```

**1. Service worker** at the public root (`public/OneSignalSDKWorker.js`):

```js
importScripts("https://cdn.onesignal.com/sdks/web/v16/OneSignalSDK.sw.js");
```

**2. A boot module** that reacts to your auth state:

```js
// resources/js/lib/onesignal.js
import OneSignal from 'react-onesignal';

let booted = false;

export async function bootOneSignal(user) {
    if (!booted) {
        booted = true;
        await OneSignal.init({
            appId: import.meta.env.VITE_ONESIGNAL_APP_ID,
            allowLocalhostAsSecureOrigin: import.meta.env.DEV,
        });
    }

    if (user) {
        await OneSignal.login(String(user.id));  // same value as getOneSignalExternalId()
    } else {
        await OneSignal.logout();
    }
}
```

**3. Call it from your root layout.** With Inertia, the auth prop already tells you who is signed in:

```jsx
import { usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import { bootOneSignal } from '@/lib/onesignal';

export default function AppLayout({ children }) {
    const { auth } = usePage().props;

    useEffect(() => {
        bootOneSignal(auth.user);
    }, [auth.user?.id]);

    return children;
}
```

Configure the site URL and the permission prompt under **Settings → Push & In-App → Web** in the dashboard.

## Part B — Mobile app that wraps your site in a WebView

A common Laravel + Inertia setup ships a thin native app that loads the same site in a WebView. This needs care, because:

> **Web push does not work inside a WebView.** Neither iOS `WKWebView` nor Android's WebView supports the service-worker push APIs the Web SDK needs.

The fix is to let the **native** SDK own push and have the web page tell it who is logged in.

**1. Install (Expo):**

```bash
npx expo install react-native-onesignal onesignal-expo-plugin
```

This requires a development build or EAS build — push needs native code, so it does **not** run in Expo Go.

```json
{
  "expo": {
    "plugins": [["onesignal-expo-plugin", { "mode": "development" }]]
  }
}
```

**2. Inject a flag and listen for auth messages:**

```tsx
import { OneSignal } from 'react-native-onesignal';
import { WebView } from 'react-native-webview';

OneSignal.initialize(process.env.EXPO_PUBLIC_ONESIGNAL_APP_ID!);

export default function App() {
    return (
        <WebView
            source={{ uri: 'https://example.com' }}
            injectedJavaScriptBeforeContentLoaded={`window.isMobileApp = true; true;`}
            onMessage={(event) => {
                const message = JSON.parse(event.nativeEvent.data);

                if (message.type === 'auth') {
                    if (message.userId) {
                        OneSignal.login(message.userId);
                        OneSignal.Notifications.requestPermission(false);
                    } else {
                        OneSignal.logout();
                    }
                }
            }}
        />
    );
}
```

**3. Teach the web boot module about the WebView.** Inside the app, skip the Web SDK entirely and forward the auth state to the native side instead:

```js
export async function bootOneSignal(user) {
    // Inside the app's WebView the native SDK owns push — just relay who is signed in.
    if (window.isMobileApp) {
        window.ReactNativeWebView?.postMessage(JSON.stringify({
            type: 'auth',
            userId: user ? String(user.id) : null,
        }));
        return;
    }

    // ...browser path from Part A
}
```

The layout hook from Part A now serves both platforms — it re-runs whenever the signed-in user changes, in the browser and in the app.

Upload your APNs key (**Settings → iOS**) and FCM credentials (**Settings → Android**) to the same OneSignal app.

## Part C — Server side (this package)

Keep the profile in sync from a model observer, and send from anywhere:

```php
// app/Observers/UserObserver.php
public function created(User $user): void
{
    $user->syncToOneSignalAsync();
}

public function updated(User $user): void
{
    $user->syncToOneSignalAsync();
}
```

```php
// Delivers to every subscription of that user: browser, phone, and any other device
OneSignal::sendToUser((string) $user->id, 'Your order shipped 🚀');
```

Both are safe to call unconditionally — when OneSignal is unconfigured the package no-ops (see the README's zero-config section).

## Rules that keep a multi-SDK setup sane

**One writer for user data: the server.** Client SDKs can also write tags (`OneSignal.User.addTag()`) — don't. If the browser writes `plan=free` and Laravel writes `plan=pro`, the last write wins and you will chase phantom differences forever. Tags come from `getOneSignalTags()`, email/phone from `syncToOneSignal()`. The client's only job is registering the device and calling `login()`/`logout()`.

**`login()` is unauthenticated by default.** Anyone can open a console and call `OneSignal.login("1")` to receive another user's notifications. OneSignal ships **Identity Verification** to close this: your backend signs a token for the current user and the client passes it along with `login()`. Turn it on before you have real volume — see [OneSignal's Identity Verification docs](https://documentation.onesignal.com/docs/identity-verification) for the current token format and client API.

**Expect duplicates across platforms.** A user with both a browser subscription and the app installed receives the same notification twice — correct behavior for a send addressed to the user. If that is not the UX you want, the usual fix is to not prompt for web push at all for users who have the app (the `isMobileApp` flag above already tells you), or to segment sends by subscription type.

**Stay on the user model (v16 web / v5 mobile).** This package targets OneSignal's user model — the API where a user has an `external_id` and many subscriptions. Older tutorials built around `player_id` and `setExternalUserId()` describe the previous device-centric model; the concepts do not map cleanly, and mixing them is the single biggest source of confusion in OneSignal integrations.

**Prompt for permission with context.** `requestPermission` at first launch converts poorly. Ask after an action that makes the value obvious — right after a first order, when enabling a watchlist, and so on.
