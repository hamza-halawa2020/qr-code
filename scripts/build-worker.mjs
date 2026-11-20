import { build, loadEnv } from 'vite';

const env = loadEnv('production', process.cwd(), 'FIREBASE_');
const firebaseWebConfig = {
    apiKey: env.FIREBASE_API_KEY,
    authDomain: env.FIREBASE_AUTH_DOMAIN,
    projectId: env.FIREBASE_PROJECT_ID,
    messagingSenderId: env.FIREBASE_MESSAGING_SENDER_ID,
    appId: env.FIREBASE_APP_ID,
};

await build({
    configFile: false,
    publicDir: false,
    define: { __FIREBASE_WEB_CONFIG__: JSON.stringify(firebaseWebConfig) },
    build: {
        outDir: 'public', emptyOutDir: false,
        lib: { entry: 'resources/js/firebase-messaging-sw.js', formats: ['iife'], name: 'QueueMessagingWorker', fileName: () => 'firebase-messaging-sw.js' },
    },
});
