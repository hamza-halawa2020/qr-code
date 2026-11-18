import './bootstrap';
import './branch-board';

const root = document.getElementById('live-queue');
if (root) {
    const config = JSON.parse(document.getElementById('queue-config').textContent);
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    let version = Number(config.initial.version ?? 0), lastLive = 0;
    let auth, firebaseApp, messagingModule, unsubscribe;
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
    const apply = data => {
        if (Number(data.version) < version) return false;
        version = Number(data.version);
        if (data.updated_at) text('last-updated', `آخر تحديث: ${new Date(data.updated_at).toLocaleTimeString('ar-EG')}`);
        if (root.dataset.kind === 'counter') {
            text('display-name', data.name);
            text('display-number', data.number ?? '—');
            text('display-label', data.number ? 'نتشرف بخدمة رقم' : data.paused ? 'الشباك في استراحة' : 'في انتظار العميل التالي');
        } else {
            activeTicket = ['waiting', 'deferred', 'called', 'serving'].includes(data.status);
            if (notificationButton) notificationButton.disabled = !activeTicket || !notificationsSupported || notificationBusy || !!currentPushToken;
            text('ticket-status', data.label); text('ticket-ahead', data.ahead); text('ticket-counter', data.counter);
            text('wait-estimate', data.estimate ?? '');
            const estimate = document.getElementById('estimate-section'); if (estimate) estimate.hidden = data.status !== 'waiting';
            const near = document.getElementById('near-turn'); if (near) near.hidden = !data.near_turn;
            const rating = document.getElementById('rating-section'); if (rating) rating.hidden = data.status !== 'completed';
            text('ticket-heading', data.heading ?? data.label);
            text('ticket-description', data.description ?? 'تابع حالة تذكرتك وشاشة الفرع.');
            text('retry-note', data.retry_note ?? ''); text('ahead-description', data.ahead_label ?? 'منتظرين لنفس الخدمة قبلك');
            text('ticket-message', data.status === 'called' ? 'دورك الآن، اتوجه إلى' : data.status === 'waiting' ? 'استنى النداء على رقمك' : data.label);
            document.getElementById('cancel-ticket').hidden = !['waiting', 'deferred'].includes(data.status);
        }
        return true;
    };
    const post = async (url, body = {}) => {
        const response = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify(body), cache: 'no-store' });
        if (!response.ok) throw new Error('تعذر تنفيذ الطلب، حاول مرة أخرى.');
        return response.json();
    };
    const refresh = async () => {
        if (!navigator.onLine) return connection('الاتصال مقطوع — البيانات قديمة، تابع شاشة الفرع');
        try {
            const response = await fetch(root.dataset.stateUrl, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (!response.ok) throw new Error();
            apply(await response.json()); connection('تم تحديث الحالة من الخادم');
        } catch { connection('تعذر تحديث الحالة — تابع شاشة الفرع'); }
    };
    const connect = async () => {
        if (!config.enabled) return;
        try {
            const [{ initializeApp }, authModule, firestore] = await Promise.all([import('firebase/app'), import('firebase/auth'), import('firebase/firestore')]);
            firebaseApp ??= initializeApp(config.firebase);
            auth ??= authModule.getAuth(firebaseApp);
            await authModule.setPersistence(auth, authModule.inMemoryPersistence);
            const credentials = await post(root.dataset.authUrl);
            await authModule.signInWithCustomToken(auth, credentials.token);
            unsubscribe?.();
            unsubscribe = firestore.onSnapshot(firestore.doc(firestore.getFirestore(firebaseApp), credentials.path), { includeMetadataChanges: true }, snapshot => {
                if (!snapshot.exists() || snapshot.metadata.fromCache) return;
                if (!apply(snapshot.data())) { lastLive = 0; refresh(); return; }
                lastLive = Date.now(); connection('متصل — تحديث مباشر');
            }, () => { lastLive = 0; refresh(); });
        } catch { connection('تعذر الاتصال المباشر؛ نحاول تحديث دورك تلقائيًا'); }
    };
    const prepareNotifications = async () => {
        if (!notificationButton) return;
        notificationButton.disabled = true;
        if (!config.enabled) return text('notification-message', 'تنبيهات الموبايل غير متاحة حاليًا؛ تابع الصفحة وشاشة الفرع.');
        if (!window.isSecureContext || !('Notification' in window) || !('serviceWorker' in navigator)) return text('notification-message', 'الإشعارات غير متاحة على هذا الاتصال أو المتصفح. تابع الصفحة وشاشة الفرع.');
        try {
            messagingModule = await import('firebase/messaging');
            notificationsSupported = await messagingModule.isSupported();
            notificationButton.disabled = !notificationsSupported || !activeTicket;
            if (!notificationsSupported) text('notification-message', 'الإشعارات غير مدعومة هنا. على iPhone جرّب إضافة الموقع للشاشة الرئيسية.');
            if (notificationsSupported && Notification.permission === 'granted' && !optedOut()) await renewPushSubscription();
        } catch { text('notification-message', 'تعذر تجهيز الإشعارات. تابع الصفحة وشاشة الفرع.'); }
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
            const registration = await navigator.serviceWorker.register('/firebase-messaging-sw.js');
            await navigator.serviceWorker.ready;
            const messaging = messagingModule.getMessaging(firebaseApp);
            const token = await messagingModule.getToken(messaging, { vapidKey: config.vapidKey, serviceWorkerRegistration: registration });
            await post(root.dataset.subscribeUrl, { token });
            if (currentPushToken && currentPushToken !== token) await post(root.dataset.unsubscribeUrl, { token: currentPushToken });
            currentPushToken = token;
            removeForegroundListener ??= messagingModule.onMessage(messaging, payload => {
                if (payload.data?.ticket_id && payload.data.ticket_id !== config.initial.id) return;
                refresh(); text('notification-message', payload.data?.kind === 'near_turn' ? 'دورك قرب، خليك قريب وتابع الصفحة.' : 'تم النداء على رقمك، راجع الشباك الموضح.');
            });
            text('notification-message', 'تم تفعيل الإشعارات على الجهاز. تابع شاشات الفرع كمان.');
        } finally { notificationBusy = false; updateNotificationButtons(); }
    };
    const stopNotifications = async () => {
        if (notificationBusy || !currentPushToken) return;
        setOptOut(true); notificationBusy = true; updateNotificationButtons();
        try {
            await post(root.dataset.unsubscribeUrl, { token: currentPushToken });
            // The FCM token can be shared by another open ticket. Opt out of
            // this ticket on the server without revoking that device token.
            currentPushToken = null;
            removeForegroundListener?.(); removeForegroundListener = undefined;
            text('notification-message', 'تم إيقاف إشعارات هذه التذكرة. تقدر تتابع دورك من الصفحة.');
        } finally { notificationBusy = false; updateNotificationButtons(); }
    };
    const syncNotifications = async () => {
        if (!notificationsSupported) return;
        try {
            if (Notification.permission !== 'granted' && currentPushToken) await stopNotifications();
            else if (!optedOut()) await renewPushSubscription();
        } catch { text('notification-message', 'تعذر تحديث اشتراك الإشعارات. تابع الصفحة وشاشة الفرع.'); }
    };
    notificationButton?.addEventListener('click', async event => {
        const button = event.currentTarget;
        if (!notificationsSupported || !activeTicket) return;
        button.disabled = true;
        try {
            const permission = await Notification.requestPermission();
            if (permission !== 'granted') throw new Error('الإشعارات غير مفعلة. تقدر تتابع دورك من الصفحة.');
            setOptOut(false);
            await renewPushSubscription();
        } catch (error) { text('notification-message', error.message || 'تعذر تفعيل الإشعارات.'); }
        finally { updateNotificationButtons(); }
    });
    stopNotificationButton?.addEventListener('click', () => stopNotifications().catch(() => text('notification-message', 'تعذر إيقاف الاشتراك. حاول تاني أو أوقف إذن الإشعارات من إعدادات المتصفح.')));
    apply(config.initial); connect(); refresh(); prepareNotifications();
    setInterval(() => { if (Date.now() - lastLive > 60000) refresh(); }, 10000);
    setInterval(connect, 45 * 60 * 1000);
    setInterval(syncNotifications, 30 * 60 * 1000);
    window.addEventListener('online', () => { refresh(); connect(); syncNotifications(); });
    window.addEventListener('offline', () => connection('الاتصال مقطوع — البيانات قديمة، تابع شاشة الفرع'));
    document.addEventListener('visibilitychange', () => { if (!document.hidden) { refresh(); syncNotifications(); } });
}
document.querySelectorAll('form').forEach(form => form.addEventListener('submit', () => form.querySelectorAll('[data-submit]').forEach(button => { button.disabled = true; })));
