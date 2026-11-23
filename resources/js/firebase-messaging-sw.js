import { initializeApp } from 'firebase/app';
import { getMessaging } from 'firebase/messaging/sw';

// Registered before Firebase's handler so clicking a notification focuses the
// existing ticket tab or opens the saved ticket URL directly.
self.addEventListener('notificationclick', event => {
    event.notification.close();
    event.stopImmediatePropagation();
    const ticketUrl = event.notification.data?.FCM_MSG?.data?.ticket_url
        || event.notification.data?.ticket_url
        || null;
    event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(clients => {
        // Try to focus an already-open ticket tab first.
        const existing = clients.find(client => new URL(client.url).pathname.startsWith('/t/'));
        if (existing) return existing.focus();
        // Open the saved ticket URL, or fall back to home.
        return self.clients.openWindow(ticketUrl || '/');
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
