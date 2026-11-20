import { initializeApp } from 'firebase/app';
import { getMessaging } from 'firebase/messaging/sw';

// Registered before Firebase's handler so clicking a notification focuses the
// existing ticket tab. The secret ticket URL is never sent to FCM.
self.addEventListener('notificationclick', event => {
    event.notification.close();
    event.stopImmediatePropagation();
    event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(clients => {
        const ticket = clients.find(client => new URL(client.url).pathname.startsWith('/t/'));
        return ticket ? ticket.focus() : self.clients.openWindow('/');
    }));
});
// Diagnostic: lets us confirm a background push actually reached the worker
// even when Firebase's own handler shows the notification silently.
self.addEventListener('push', event => {
    let kind = 'unknown';
    try {
        kind = event.data?.json()?.data?.kind ?? 'unknown';
    } catch {}
    event.waitUntil(fetch('/firebase-push-diag?kind=' + encodeURIComponent(kind)).catch(() => {}));
});

// Config is inlined at build time so the worker never depends on a separate
// network request during install (a failed importScripts breaks push subscribe).
self.firebaseConfig = __FIREBASE_WEB_CONFIG__;
if (self.firebaseConfig?.projectId) {
    getMessaging(initializeApp(self.firebaseConfig));
}
