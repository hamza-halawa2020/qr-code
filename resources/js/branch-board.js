const board = document.getElementById('branch-board');
if (board) {
    const config = JSON.parse(document.getElementById('board-config').textContent);
    const status = document.getElementById('board-connection');
    let version = Number(config.initial.version), unsubscribe, auth, app;
    const apply = data => {
        if (Number(data.version) < version) return;
        version = Number(data.version);
        const cards = data.counters.map(item => {
            const card = document.createElement('section'); card.className = 'panel';
            for (const [tag, value, cls] of [['h2', item.name, ''], ['div', item.number ?? '—', 'ahead-number'], ['p', item.status, '']]) {
                const element = document.createElement(tag); element.textContent = value; element.className = cls; card.append(element);
            }
            return card;
        });
        document.getElementById('board-counters').replaceChildren(...cards);
        status.textContent = `آخر تحديث: ${new Date().toLocaleTimeString('ar-EG')}`;
    };
    const refresh = async () => {
        try {
            const response = await fetch(board.dataset.stateUrl, { cache: 'no-store', headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error(); apply(await response.json());
        } catch { status.textContent = 'تعذر الاتصال — البيانات قديمة'; }
    };
    const connect = async () => {
        if (!config.enabled) return;
        try {
            const [{ initializeApp }, authModule, firestore] = await Promise.all([import('firebase/app'), import('firebase/auth'), import('firebase/firestore')]);
            app ??= initializeApp(config.firebase); auth ??= authModule.getAuth(app);
            await authModule.setPersistence(auth, authModule.inMemoryPersistence);
            const response = await fetch(board.dataset.authUrl, { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content } });
            if (!response.ok) throw new Error();
            const credentials = await response.json(); await authModule.signInWithCustomToken(auth, credentials.token);
            unsubscribe?.();
            unsubscribe = firestore.onSnapshot(firestore.doc(firestore.getFirestore(app), credentials.path), snapshot => { if (snapshot.exists() && !snapshot.metadata.fromCache) apply(snapshot.data()); }, refresh);
        } catch { refresh(); }
    };
    apply(config.initial); refresh(); connect(); setInterval(refresh, 10000); setInterval(connect, 45 * 60 * 1000);
    window.addEventListener('online', () => { refresh(); connect(); });
    window.addEventListener('offline', () => { status.textContent = 'الاتصال مقطوع — البيانات قديمة'; });
}
