import { build } from 'vite';

await build({
    configFile: false,
    publicDir: false,
    build: {
        outDir: 'public', emptyOutDir: false,
        lib: { entry: 'resources/js/firebase-messaging-sw.js', formats: ['iife'], name: 'QueueMessagingWorker', fileName: () => 'firebase-messaging-sw.js' },
    },
});
