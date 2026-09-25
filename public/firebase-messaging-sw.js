/*
 * Service worker for dashboard push notifications.
 *
 * Must live at the site root: a service worker can only control pages at or
 * below its own path, and the dashboard spans every route.
 *
 * Deliberately plain ES5-style and dependency-free. A service worker runs
 * outside the page, so it cannot use the app's bundle — the Firebase compat
 * builds are imported directly and pinned to a version.
 *
 * The config below is public: the web SDK ships it to every browser. The
 * service account that signs sends stays on the server.
 */

importScripts('https://www.gstatic.com/firebasejs/10.14.1/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/10.14.1/firebase-messaging-compat.js');

firebase.initializeApp({
  apiKey: 'AIzaSyD_VoaVWoKI0W9XXCtOCKgV9VcOJZuQ5ZQ',
  authDomain: 'driving-d51f6.firebaseapp.com',
  projectId: 'driving-d51f6',
  storageBucket: 'driving-d51f6.firebasestorage.app',
  messagingSenderId: '531510985012',
  appId: '1:531510985012:web:bb3670250bc2b014be6561',
});

var messaging = firebase.messaging();

/* A notification that arrives while no tab is focused. */
messaging.onBackgroundMessage(function (payload) {
  var notification = payload.notification || {};
  var data = payload.data || {};

  self.registration.showNotification(notification.title || 'إشعار جديد', {
    body: notification.body || '',
    icon: '/favicon.ico',
    badge: '/favicon.ico',
    dir: 'rtl',
    lang: 'ar',
    // Group by kind so ten booking requests collapse into one line rather than
    // burying everything else in the tray.
    tag: data.kind || 'general',
    renotify: true,
    data: data,
  });
});

/* Open the page the notification is about, reusing a tab if one is already there. */
self.addEventListener('notificationclick', function (event) {
  event.notification.close();

  var target = (event.notification.data && event.notification.data.url) || '/dashboard';

  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (windows) {
      for (var i = 0; i < windows.length; i++) {
        if (windows[i].url.indexOf(target) !== -1 && 'focus' in windows[i]) {
          return windows[i].focus();
        }
      }

      return clients.openWindow(target);
    })
  );
});
