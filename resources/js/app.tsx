import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { ToastProvider } from '@/components/ui/toast';

createInertiaApp({
    title: (title) => title || 'Commerce ERP',
    resolve: (name) =>
        resolvePageComponent(
            `./pages/${name}.tsx`,
            import.meta.glob('./pages/**/*.tsx'),
        ),
    // Subtle top progress bar for Inertia page navigations, themed to the app's
    // primary colour. No spinner — the bar alone is enough at this weight.
    progress: {
        color: '#2b3a30',
        showSpinner: false,
        delay: 120,
    },
    setup({ el, App, props }) {
        createRoot(el).render(
            <ToastProvider>
                <App {...props} />
            </ToastProvider>,
        );
    },
});
