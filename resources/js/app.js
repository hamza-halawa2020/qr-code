import './bootstrap';

const root = document.getElementById('live-queue');
if (root) {
    const config = JSON.parse(document.getElementById('queue-config').textContent);
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    let version = Number(config.initial.version ?? 0), lastLive = 0;
    let auth, firebaseApp, messagingModule, unsubscribe;
    const text = (id, value) => { const el = document.getElementById(id); if (el) el.textContent = value ?? ''; };
    const connection = value => text('connection-status', value);
    const apply = data => {
        if (Number(data.version) < version) return;
        version = Number(data.version);
        if (root.dataset.kind === 'counter') {
            text('display-number', data.number ?? '—');
            text('display-label', data.number ? 'نتشرف بخدمة رقم' : data.paused ? 'الشباك في استراحة' : 'في انتظار العميل التالي');
        } else {
            text('ticket-status', data.label); text('ticket-ahead', data.ahead); text('ticket-counter', data.counter);
            text('ticket-message', data.status === 'called' ? 'دورك الآن، اتوجه إلى' : data.status === 'waiting' ? 'استنى النداء على رقمك' : data.label);
            document.getElementById('cancel-ticket').hidden = data.status !== 'waiting';
        }
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
                apply(snapshot.data()); lastLive = Date.now(); connection('متصل — تحديث مباشر عبر Firebase');
            }, () => { lastLive = 0; refresh(); });
        } catch { connection('تعذر الاتصال بـ Firebase — المتابعة المؤقتة من الخادم'); }
    };
    document.getElementById('enable-notifications')?.addEventListener('click', async event => {
        const button = event.currentTarget;
        if (!config.enabled) return text('notification-message', 'الإشعارات هتتاح بعد ربط حساب Firebase.');
        if (!('Notification' in window) || !('serviceWorker' in navigator)) return text('notification-message', 'متصفحك لا يدعم الإشعارات. تابع الصفحة وشاشات الفرع.');
        button.disabled = true;
        try {
            const permission = await Notification.requestPermission();
            if (permission !== 'granted') throw new Error('الإشعارات غير مفعلة. تقدر تتابع دورك من الصفحة.');
            messagingModule ??= await import('firebase/messaging');
            if (!await messagingModule.isSupported()) throw new Error('الإشعارات غير مدعومة هنا. على iPhone جرّب إضافة الموقع للشاشة الرئيسية.');
            if (!firebaseApp) await connect();
            const registration = await navigator.serviceWorker.register('/firebase-messaging-sw.js');
            await navigator.serviceWorker.ready;
            const token = await messagingModule.getToken(messagingModule.getMessaging(firebaseApp), { vapidKey: config.vapidKey, serviceWorkerRegistration: registration });
            await post(root.dataset.subscribeUrl, { token });
            messagingModule.onMessage(messagingModule.getMessaging(firebaseApp), () => { refresh(); text('notification-message', 'تم النداء على رقمك، راجع الشباك الموضح.'); });
            text('notification-message', 'تم تفعيل الإشعارات على الجهاز. تابع شاشات الفرع كمان.');
        } catch (error) { text('notification-message', error.message || 'تعذر تفعيل الإشعارات.'); }
        finally { button.disabled = false; }
    });
    apply(config.initial); connect(); refresh();
    setInterval(() => { if (Date.now() - lastLive > 60000) refresh(); }, 10000);
    setInterval(connect, 45 * 60 * 1000);
    window.addEventListener('online', () => { refresh(); connect(); });
    window.addEventListener('offline', () => connection('الاتصال مقطوع — البيانات قديمة، تابع شاشة الفرع'));
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
}
document.querySelectorAll('form').forEach(form => form.addEventListener('submit', () => form.querySelectorAll('[data-submit]').forEach(button => { button.disabled = true; })));
