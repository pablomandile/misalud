import { createInertiaApp } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import PublicoLayout from '@/layouts/PublicoLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name, page) => {
        switch (true) {
            // La portada se dibuja sola; el layout público solo le suma el
            // aviso de cookies, que tiene que verse en la primera visita.
            case name === 'Welcome':
                return PublicoLayout;
            // Cookies y los textos legales se abren con sesión y sin ella.
            case name === 'Cookies':
            case name === 'Legal':
                return (page.props as { auth?: { user?: unknown } }).auth?.user
                    ? AppLayout
                    : PublicoLayout;
            // Las invitaciones se abren muchas veces SIN sesión: el layout con
            // menú no tiene sentido para alguien que todavía no entró.
            case name.startsWith('auth/'):
            case name.startsWith('invitaciones/'):
            // "Aceptar y continuar": todavía no se entra a la app.
            case name.startsWith('terminos/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    withApp: (app) => {
        app.directive('focus', {
            mounted: (el: HTMLElement, shouldFocus) => {
                if (shouldFocus.value !== false) {
                    el.focus();
                }
            },
        });
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();
