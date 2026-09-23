<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { redirect } from '@/routes/google';

/*
 * Ingreso con Google.
 *
 * No se muestra si el `.env` no tiene las credenciales: sin ellas la ruta
 * devuelve 404 y el botón sería una puerta pintada en la pared.
 *
 * Es un `<a>` y no un `<Link>` de Inertia a propósito: el destino termina en un
 * redirect a accounts.google.com, que es otro origen, y una navegación de
 * Inertia no puede salir de la app -se quedaría esperando un JSON que nunca
 * llega-.
 *
 * El logo va como SVG inline y no como archivo: son cuatro trazos, y así no hay
 * una request más justo en la pantalla de entrada.
 */

const habilitado = computed(() => usePage().props.googleHabilitado === true);

const props = withDefaults(
    defineProps<{
        /** Cambia solo el texto: el registro y el ingreso usan la misma ruta. */
        modo?: 'ingresar' | 'registrarse';
        /**
         * En el login va en false porque el botón de llave de acceso, que viene
         * abajo, ya trae el suyo: dos separadores diciendo lo mismo con un
         * botón en el medio se leen como dos formularios distintos.
         */
        conSeparador?: boolean;
    }>(),
    { modo: 'ingresar', conSeparador: true },
);

const texto = computed(() =>
    props.modo === 'registrarse'
        ? 'Registrarme con Google'
        : 'Continuar con Google',
);
</script>

<template>
    <div v-if="habilitado" class="flex flex-col gap-6">
        <Button as-child variant="outline" class="w-full">
            <a :href="redirect.url()">
                <svg
                    class="size-4"
                    viewBox="0 0 24 24"
                    aria-hidden="true"
                    focusable="false"
                >
                    <path
                        fill="#4285F4"
                        d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.76h3.57c2.08-1.92 3.27-4.74 3.27-8.09Z"
                    />
                    <path
                        fill="#34A853"
                        d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.76c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84A11 11 0 0 0 12 23Z"
                    />
                    <path
                        fill="#FBBC05"
                        d="M5.84 14.09a6.6 6.6 0 0 1 0-4.18V7.07H2.18a11 11 0 0 0 0 9.86l3.66-2.84Z"
                    />
                    <path
                        fill="#EA4335"
                        d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84C6.71 7.31 9.14 5.38 12 5.38Z"
                    />
                </svg>
                {{ texto }}
            </a>
        </Button>

        <!-- Separador con la etiqueta encima de la línea, para lector de pantalla. -->
        <div v-if="conSeparador" class="relative text-center text-sm">
            <span
                class="absolute inset-0 top-1/2 h-px bg-border"
                aria-hidden="true"
            />
            <span class="relative bg-background px-2 text-muted-foreground">
                o con tu email
            </span>
        </div>
    </div>
</template>
