import { initializeApp } from 'firebase/app';
import { getMessaging, onBackgroundMessage } from 'firebase/messaging/sw';

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
// Config is inlined at build time so the worker never depends on a separate
// network request during install (a failed importScripts breaks push subscribe).
self.firebaseConfig = __FIREBASE_WEB_CONFIG__;
if (self.firebaseConfig?.projectId) {
    const messaging = getMessaging(initializeApp(self.firebaseConfig));
    // Notification payloads are displayed by the FCM SDK. Do not show a second
    // copy here; foreground messages are forwarded to onMessage in the page.
    onBackgroundMessage(messaging, () => {});
}
