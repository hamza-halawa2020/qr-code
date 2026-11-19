const board = document.getElementById('branch-board');
if (board) {
    const config = JSON.parse(document.getElementById('board-config').textContent);
    const s = config.strings; // localised strings passed from Blade
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
        status.textContent = s.board_updated + new Date().toLocaleTimeString();
    };
    const refresh = async () => {
        try {
            const response = await fetch(board.dataset.stateUrl, { cache: 'no-store', headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error(); apply(await response.json());
        } catch { status.textContent = s.board_conn_failed; }
    };
    const connect = async () => {
        if (!config.enabled) { status.textContent = s.conn_live_disabled; return; }
        try {
            const [{ initializeApp }, authModule, firestore] = await Promise.all([import('firebase/app'), import('firebase/auth'), import('firebase/firestore')]);
            app ??= initializeApp(config.firebase); auth ??= authModule.getAuth(app);
            await authModule.setPersistence(auth, authModule.inMemoryPersistence);
            const response = await fetch(board.dataset.authUrl, { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content } });
            if (!response.ok) throw new Error();
            const credentials = await response.json(); await authModule.signInWithCustomToken(auth, credentials.token);
            unsubscribe?.();
            unsubscribe = firestore.onSnapshot(firestore.doc(firestore.getFirestore(app), credentials.path), snapshot => { if (snapshot.exists() && !snapshot.metadata.fromCache) apply(snapshot.data()); }, () => { status.textContent = s.board_conn_failed; });
        } catch { status.textContent = s.board_conn_failed; }
    };
    apply(config.initial); connect(); setInterval(connect, 45 * 60 * 1000);
    window.addEventListener('online', () => { refresh(); connect(); });
    window.addEventListener('offline', () => { status.textContent = s.board_offline; });
}
