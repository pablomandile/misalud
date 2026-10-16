<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { computed } from 'vue';
import FondoDeSalud from '@/components/FondoDeSalud.vue';
import { home } from '@/routes';

/*
 * Términos y condiciones, y política de privacidad: la misma página con otro
 * texto. Los textos viven en `resources/legales/*.md` y llegan ya convertidos
 * a HTML desde `LegalesController`; son del repo, no de un usuario.
 *
 * Se abre con sesión y sin ella, igual que Cookies: sin sesión no hay
 * AppLayout (va con PublicoLayout, ver app.ts) y la página trae su propio
 * encabezado y su fondo.
 */

defineProps<{
    titulo: string;
    version: string;
    html: string;
}>();

const page = usePage();
const conSesion = computed(() => Boolean(page.props.auth.user));
</script>

<template>
    <Head :title="titulo" />

    <div
        :class="
            conSesion
                ? ''
                : 'relative isolate min-h-svh bg-background text-foreground'
        "
    >
        <FondoDeSalud v-if="!conSesion" />

        <header
            v-if="!conSesion"
            class="mx-auto w-full max-w-2xl px-4 pt-4 sm:px-6 sm:pt-6"
        >
            <Link
                :href="home()"
                class="inline-flex min-h-11 items-center gap-2 text-sm text-muted-foreground transition-colors hover:text-foreground"
            >
                <ArrowLeft class="size-4 shrink-0" />
                Volver al inicio
            </Link>
        </header>

        <main
            class="mx-auto flex w-full max-w-2xl flex-col gap-2"
            :class="conSesion ? '' : 'p-4 pb-12 sm:p-6'"
        >
            <h1 class="text-xl font-semibold">{{ titulo }}</h1>
            <p class="text-sm text-muted-foreground">
                Versión del {{ version }}
            </p>

            <!-- eslint-disable vue/no-v-html -- texto propio del repo, ver LegalesController -->
            <article
                class="mt-3 rounded-xl border border-border bg-card p-5 text-pretty wrap-anywhere sm:p-6 [&_a]:underline [&_a]:underline-offset-4 [&_a:hover]:text-primary [&_blockquote]:mt-4 [&_blockquote]:border-l-4 [&_blockquote]:border-border [&_blockquote]:pl-4 [&_blockquote]:text-sm [&_blockquote]:text-muted-foreground [&_h2]:mt-8 [&_h2]:text-lg [&_h2]:font-semibold [&_h2:first-child]:mt-0 [&_li]:mt-1.5 [&_p]:mt-3 [&_p:first-child]:mt-0 [&_ul]:mt-2 [&_ul]:list-disc [&_ul]:pl-5"
                v-html="html"
            />
        </main>
    </div>
</template>
