import '../css/app.css';
import '@fontsource-variable/plus-jakarta-sans';

import { createInertiaApp } from '@inertiajs/react';
import axios from 'axios';
import { createRoot } from 'react-dom/client';
import { xsrfCookieName } from './lib/csrf';

// Inertia sends the CSRF token from this app's own cookie (see App\Http\Middleware\ValidateCsrfToken).
axios.defaults.xsrfCookieName = xsrfCookieName();

createInertiaApp({
    title: (title) => (title ? `${title} · Central` : 'Central'),
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.jsx');
        const page = pages[`./Pages/${name}.jsx`];
        if (!page) throw new Error(`Page not found: ${name}`);
        return page().then((module) => module.default);
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: { color: '#0ea5e9', delay: 150 },
});
