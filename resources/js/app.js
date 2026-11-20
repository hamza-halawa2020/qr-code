import './bootstrap';
import './branch-board';

const root = document.getElementById('live-queue');
if (root) {
    const config = JSON.parse(document.getElementById('queue-config').textContent);
    const s = config.strings; // localised strings passed from Blade
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    let version = Number(config.initial.version ?? 0);
    let auth, firebaseApp, messagingModule, unsubscribe, swRegistration;
    let activeTicket = ['waiting', 'deferred', 'called', 'serving'].includes(config.initial.status);
    let notificationsSupported = false;
    let notificationBusy = false, currentPushToken = null, removeForegroundListener;
    const notificationButton = document.getElementById('enable-notifications');
    const stopNotificationButton = document.getElementById('disable-notifications');
    const preferenceKey = `queue-notifications-optout:${config.initial.id}`;
    let notificationPreference = null;
    const optedOut = () => { if (notificationPreference !== null) return notificationPreference; try { return localStorage.getItem(preferenceKey) === '1'; } catch { return false; } };
    const setOptOut = value => { notificationPreference = value; try { value ? localStorage.setItem(preferenceKey, '1') : localStorage.removeItem(preferenceKey); } catch {} };
    const text = (id, value) => { const el = document.getElementById(id); if (el) el.textContent = value ?? ''; };
    const connection = value => text('connection-status', value);
    const log = (...args) => console.log('[queue]', ...args);
    const logError = (label, error) => {
        console.error('[queue] ' + label, {
            message: error?.message ?? String(error),
            name: error?.name ?? '',
            code: error?.code ?? '',
        });
        const message = error?.message ?? '';
        if (message) text('notification-message', message);
        return message;
    };
    const report = (label, error) => {
        logError(label, error);
        const url = root.dataset.diagUrl;
        if (!url) return;
        void post(url, {
            label,
            message: String(error?.message ?? error).slice(0, 900),
            name: String(error?.name ?? '').slice(0, 120),
            code: String(error?.code ?? '').slice(0, 120),
        }).catch(() => {});
    };
    const apply = data => {
        if (Number(data.version) < version) return false;
        version = Number(data.version);
        if (data.updated_at) text('last-updated', s.last_updated + new Date(data.updated_at).toLocaleTimeString());
        if (root.dataset.kind === 'counter') {
            text('display-name', data.name);
            text('display-number', data.number ?? '—');
            text('display-label', data.number ? s.display_serving : data.paused ? s.display_paused : s.display_waiting);
        } else {
            activeTicket = ['waiting', 'deferred', 'called', 'serving'].includes(data.status);
            if (notificationButton) notificationButton.disabled = !activeTicket || !notificationsSupported || notificationBusy || !!currentPushToken;
            text('ticket-status', data.label); text('ticket-ahead', data.ahead); text('ticket-counter', data.counter);
            text('wait-estimate', data.estimate ?? '');
            const estimate = document.getElementById('estimate-section'); if (estimate) estimate.hidden = data.status !== 'waiting';
            const near = document.getElementById('near-turn'); if (near) near.hidden = !data.near_turn;
            const rating = document.getElementById('rating-section'); if (rating) rating.hidden = data.status !== 'completed';
            text('ticket-heading', data.heading ?? data.label);
            text('ticket-description', data.description ?? s.ticket_default_desc);
            text('retry-note', data.retry_note ?? ''); text('ahead-description', data.ahead_label ?? s.ahead_service);
            text('ticket-message', data.status === 'called' ? s.ticket_called : data.status === 'waiting' ? s.ticket_waiting : data.label);
            document.getElementById('cancel-ticket').hidden = !['waiting', 'deferred'].includes(data.status);
        }
        return true;
    };
    const post = async (url, body = {}) => {
        const response = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify(body), cache: 'no-store' });
        if (!response.ok) throw new Error(s.request_failed);
        return response.json();
    };
    const refresh = async () => {
        if (!navigator.onLine) return connection(s.conn_offline);
        try {
            const response = await fetch(root.dataset.stateUrl, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (!response.ok) throw new Error();
            apply(await response.json()); connection(s.conn_refreshed);
        } catch { connection(s.conn_refresh_failed); }
    };
    const connect = async () => {
        if (!config.enabled) return connection(s.conn_live_disabled);
        log('connect start', { authUrl: root.dataset.authUrl, projectId: config.firebase?.projectId });
        try {
            const [{ initializeApp }, authModule, firestore] = await Promise.all([import('firebase/app'), import('firebase/auth'), import('firebase/firestore')]);
            firebaseApp ??= initializeApp(config.firebase);
            auth ??= authModule.getAuth(firebaseApp);
            await authModule.setPersistence(auth, authModule.inMemoryPersistence);
            const credentials = await post(root.dataset.authUrl);
            log('custom token received', { path: credentials.path, tokenLength: credentials.token?.length ?? 0 });
            await authModule.signInWithCustomToken(auth, credentials.token);
            log('custom token sign-in ok', { uid: auth.currentUser?.uid ?? '' });
            unsubscribe?.();
            unsubscribe = firestore.onSnapshot(firestore.doc(firestore.getFirestore(firebaseApp), credentials.path), { includeMetadataChanges: true }, snapshot => {
                if (!snapshot.exists() || snapshot.metadata.fromCache) return;
                if (!apply(snapshot.data())) return;
                connection(s.conn_live);
                log('firestore snapshot applied', { path: credentials.path, version: snapshot.data()?.version ?? '' });
            }, error => {
                connection(s.conn_live_failed);
                report('firestore listener failed', error);
            });
        } catch (error) {
            connection(s.conn_live_failed);
            report('connect failed', error);
        }
    };
    const prepareNotifications = async () => {
        if (!notificationButton) return;
        notificationButton.disabled = true;
        if (!config.enabled) return text('notification-message', s.notif_unavailable);
        const environment = {
            secure: window.isSecureContext,
            notification: 'Notification' in window,
            serviceWorker: 'serviceWorker' in navigator,
            permission: 'Notification' in window ? Notification.permission : 'missing',
            vapidLength: (config.vapidKey ?? '').length,
        };
        log('notification environment', environment);
        if (!window.isSecureContext || !('Notification' in window) || !('serviceWorker' in navigator)) return text('notification-message', s.notif_insecure);
        try {
            messagingModule = await import('firebase/messaging');
            notificationsSupported = await messagingModule.isSupported();
            log('messaging.isSupported', { supported: notificationsSupported });
            notificationButton.disabled = !notificationsSupported || !activeTicket;
            if (!notificationsSupported) text('notification-message', s.notif_unsupported);
            if (notificationsSupported && Notification.permission === 'granted' && !optedOut()) await renewPushSubscription();
        } catch (error) {
            report('prepare notifications failed', error);
        }
    };
    const updateNotificationButtons = () => {
        if (notificationButton) notificationButton.disabled = !activeTicket || !notificationsSupported || notificationBusy || !!currentPushToken;
        if (stopNotificationButton) { stopNotificationButton.hidden = !currentPushToken; stopNotificationButton.disabled = notificationBusy; }
    };
    const renewPushSubscription = async () => {
        if (!notificationsSupported || !activeTicket || notificationBusy || optedOut() || Notification.permission !== 'granted') return;
        notificationBusy = true; updateNotificationButtons();
        try {
            if (!firebaseApp) await connect();
            swRegistration ??= await navigator.serviceWorker.register('/firebase-messaging-sw.js');
            await navigator.serviceWorker.ready;
            const registration = swRegistration;
            const existing = await registration.pushManager.getSubscription();
            log('service worker ready', {
                scope: registration.scope,
                active: !!navigator.serviceWorker.controller,
                existingPushSubscription: !!existing,
            });
            const messaging = messagingModule.getMessaging(firebaseApp);
            log('requesting FCM token', { vapidLength: (config.vapidKey ?? '').length, senderId: config.firebase?.messagingSenderId ?? '' });
            let token;
            try {
                token = await messagingModule.getToken(messaging, { vapidKey: config.vapidKey, serviceWorkerRegistration: registration });
            } catch (tokenError) {
                // Chrome can keep a stale push subscription that makes new
                // subscribe() calls fail with "Registration failed - push service error".
                if (!existing) throw tokenError;
                await existing.unsubscribe();
                log('stale browser push subscription removed, retrying');
                token = await messagingModule.getToken(messaging, { vapidKey: config.vapidKey, serviceWorkerRegistration: registration });
            }
            log('FCM token received', { tokenLength: token?.length ?? 0 });
            await post(root.dataset.subscribeUrl, { token });
            log('subscribe endpoint ok');
            if (currentPushToken && currentPushToken !== token) await post(root.dataset.unsubscribeUrl, { token: currentPushToken });
            currentPushToken = token;
            removeForegroundListener ??= messagingModule.onMessage(messaging, payload => {
                log('foreground FCM message', { kind: payload.data?.kind ?? '', ticketId: payload.data?.ticket_id ?? '' });
                if (payload.data?.ticket_id && payload.data.ticket_id !== config.initial.id) return;
                refresh(); text('notification-message', payload.data?.kind === 'near_turn' ? s.notif_near : s.notif_called);
            });
            text('notification-message', s.notif_enabled);
        } catch (error) {
            report('push subscription failed', error);
            throw error;
        } finally { notificationBusy = false; updateNotificationButtons(); }
    };
    const stopNotifications = async () => {
        if (notificationBusy || !currentPushToken) return;
        setOptOut(true); notificationBusy = true; updateNotificationButtons();
        try {
            await post(root.dataset.unsubscribeUrl, { token: currentPushToken });
            currentPushToken = null;
            removeForegroundListener?.(); removeForegroundListener = undefined;
            text('notification-message', s.notif_stopped);
        } finally { notificationBusy = false; updateNotificationButtons(); }
    };
    const syncNotifications = async () => {
        if (!notificationsSupported) return;
        try {
            if (Notification.permission !== 'granted' && currentPushToken) await stopNotifications();
            else if (!optedOut() && !currentPushToken) await renewPushSubscription();
        } catch { text('notification-message', s.notif_prepare_failed); }
    };
    notificationButton?.addEventListener('click', async event => {
        const button = event.currentTarget;
        if (!notificationsSupported || !activeTicket) return;
        button.disabled = true;
        try {
            const permission = await Notification.requestPermission();
            if (permission !== 'granted') throw new Error(s.notif_denied);
            setOptOut(false);
            await renewPushSubscription();
        } catch (error) { text('notification-message', error.message || s.notif_error); }
        finally { updateNotificationButtons(); }
    });
    stopNotificationButton?.addEventListener('click', () => stopNotifications().catch(() => text('notification-message', s.notif_stop_error)));
    window.addEventListener('unhandledrejection', event => report('unhandled rejection', event.reason));
    window.addEventListener('error', event => report('window error', event.error ?? event.message));
    log('ticket live sync boot', { kind: root.dataset.kind ?? '', ticketId: config.initial?.id ?? '', enabled: config.enabled });
    apply(config.initial); connect(); prepareNotifications();
    setInterval(connect, 45 * 60 * 1000);
    setInterval(syncNotifications, 30 * 60 * 1000);
    window.addEventListener('online', () => { refresh(); connect(); syncNotifications(); });
    window.addEventListener('offline', () => connection(s.conn_offline));
    document.addEventListener('visibilitychange', () => { if (!document.hidden) { refresh(); syncNotifications(); } });
}
