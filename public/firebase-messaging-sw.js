self.addEventListener('push', event => {
    const payload = event.data?.json();
    if (!payload?.notification) return;
    event.waitUntil(self.registration.showNotification(payload.notification.title, {
        body: payload.notification.body,
        tag: payload.webpush?.notification?.tag ?? payload.data?.event_id ?? 'queue-call',
    }));
});
self.addEventListener('notificationclick', event => {
    event.notification.close();
    event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(clients => {
        const ticket = clients.find(client => new URL(client.url).pathname.startsWith('/t/'));
        return ticket ? ticket.focus() : self.clients.openWindow('/');
    }));
});
