/**
 * Dashboard notifications.
 *
 * Two independent paths, because each covers the other's blind spot:
 *
 *  - **Browser push** reaches whoever is signed in even with the tab closed,
 *    which is what makes a booking request land on a receptionist's screen while
 *    they are in another program. It needs permission, which the user may refuse.
 *  - **A short poll** keeps the bell and the queue counts current in an open tab.
 *    It needs no permission and works where push is unavailable — an http origin,
 *    a browser with notifications blocked, Safari on an old macOS.
 *
 * Both feed the same `dashboard:notifications` event, so the UI has one path to
 * render regardless of how the news arrived.
 */

/*
 * The bell reads a session-authenticated web route, not the API.
 *
 * The dashboard carries a session cookie; reaching `/api/v1` with it depends on
 * Sanctum's stateful-domain list matching the host, which returns a silent 401
 * when it does not. Device registration still goes to the API, because that is
 * the endpoint the mobile apps share and it is CSRF-protected the same way.
 */
const FEED = '/notifications/feed';
const DEVICE_ENDPOINT = '/api/v1/notifications/device';
const POLL_MS = 15000;

/** Read the config the Blade layout embedded, or null when push is not set up. */
function pushConfig() {
    const node = document.getElementById('push-config');

    if (!node) {
        return null;
    }

    try {
        const config = JSON.parse(node.textContent);

        return config.vapidKey && config.apiKey ? config : null;
    } catch {
        return null;
    }
}

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

/**
 * Hand the browser's push token to the backend.
 *
 * Uses the session cookie rather than a bearer token: this is the dashboard, and
 * minting an API token for it would create a second credential for the same
 * person with no way to see or revoke it separately.
 */
async function registerToken(token) {
    try {
        await fetch(DEVICE_ENDPOINT, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
            body: JSON.stringify({ push_token: token, platform: 'web', app: 'dashboard' }),
        });
    } catch (error) {
        // A failed registration costs push, not the dashboard.
        console.warn('[push] could not register this browser', error);
    }
}

/**
 * Ask for permission and register, once the user has shown intent.
 *
 * Not called on load: a permission prompt that appears before the user has done
 * anything is the fastest way to get denied permanently, and a denial cannot be
 * re-asked from script.
 */
export async function enablePush() {
    const config = pushConfig();

    if (!config) {
        return { ok: false, reason: 'not-configured' };
    }

    if (!('serviceWorker' in navigator) || !('Notification' in window)) {
        return { ok: false, reason: 'unsupported' };
    }

    // Push requires a secure origin. localhost counts; plain http on a domain
    // does not, and the failure there is silent without this check.
    if (!window.isSecureContext) {
        return { ok: false, reason: 'insecure-origin' };
    }

    const permission = await Notification.requestPermission();

    if (permission !== 'granted') {
        return { ok: false, reason: permission };
    }

    try {
        const [{ initializeApp }, { getMessaging, getToken, onMessage }] = await Promise.all([
            import('firebase/app'),
            import('firebase/messaging'),
        ]);

        const app = initializeApp({
            apiKey: config.apiKey,
            authDomain: config.authDomain,
            projectId: config.projectId,
            messagingSenderId: config.senderId,
            appId: config.appId,
        });

        const registration = await navigator.serviceWorker.register('/firebase-messaging-sw.js');
        const messaging = getMessaging(app);

        const token = await getToken(messaging, {
            vapidKey: config.vapidKey,
            serviceWorkerRegistration: registration,
        });

        if (!token) {
            return { ok: false, reason: 'no-token' };
        }

        await registerToken(token);

        // A message arriving while the tab is focused is not shown by the browser,
        // so it is surfaced in the page instead — same event as the poll uses.
        onMessage(messaging, (payload) => {
            window.dispatchEvent(
                new CustomEvent('dashboard:push', {
                    detail: {
                        title: payload.notification?.title ?? 'إشعار جديد',
                        body: payload.notification?.body ?? '',
                        data: payload.data ?? {},
                    },
                }),
            );

            refresh();
        });

        return { ok: true };
    } catch (error) {
        console.warn('[push] setup failed', error);

        return { ok: false, reason: 'error' };
    }
}

/** Current unread count and the newest few, for the bell. */
async function refresh() {
    try {
        const response = await fetch(FEED, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            return;
        }

        const payload = await response.json();

        window.dispatchEvent(
            new CustomEvent('dashboard:notifications', {
                detail: {
                    unread: payload.unread ?? 0,
                    items: payload.items ?? [],
                },
            }),
        );
    } catch {
        // Offline for a moment; the next tick covers it.
    }
}

/**
 * Start the poll.
 *
 * Paused while the tab is hidden — a background tab polling every fifteen
 * seconds is a request every fifteen seconds that nobody reads — and refreshed
 * immediately on return so the bell is never stale when looked at.
 */
export function startNotificationPolling() {
    if (!document.querySelector('meta[name="csrf-token"]')) {
        return;
    }

    let timer = null;

    const start = () => {
        stop();
        refresh();
        timer = window.setInterval(refresh, POLL_MS);
    };

    const stop = () => {
        if (timer !== null) {
            window.clearInterval(timer);
            timer = null;
        }
    };

    document.addEventListener('visibilitychange', () => {
        document.hidden ? stop() : start();
    });

    start();
}

export { refresh as refreshNotifications };
