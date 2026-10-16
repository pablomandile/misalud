<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    cookies,
    dashboard,
    login,
    privacidad,
    register,
    terminos,
} from '@/routes';
import { Button } from '@/components/ui/button';
</script>

<template>
    <Head title="MiSalud" />

    <div
        class="flex min-h-svh flex-col bg-background px-4 py-6 text-foreground"
    >
        <header class="mx-auto flex w-full max-w-3xl items-center justify-end">
            <nav class="flex items-center gap-2">
                <Button v-if="$page.props.auth.user" as-child>
                    <Link :href="dashboard()">Ir a mi panel</Link>
                </Button>
                <template v-else>
                    <Button variant="ghost" as-child>
                        <Link :href="login()">Iniciar sesión</Link>
                    </Button>
                    <Button as-child>
                        <Link :href="register()">Crear cuenta</Link>
                    </Button>
                </template>
            </nav>
        </header>

        <main
            class="mx-auto flex w-full max-w-3xl flex-1 flex-col justify-center gap-6 py-12"
        >
            <h1 class="text-4xl font-semibold tracking-tight sm:text-5xl">
                MiSalud
            </h1>

            <p class="max-w-xl text-lg text-muted-foreground">
                Tu historia clínica y la de tu familia, ordenada y siempre a
                mano: estudios, tratamientos, turnos, recetas y la credencial de
                la obra social.
            </p>

            <ul
                class="max-w-xl list-inside list-disc space-y-1 text-muted-foreground"
            >
                <li>
                    Los estudios y sus valores, con la evolución en el tiempo
                </li>
                <li>
                    Las recetas que llegan por mail, listas para la farmacia
                </li>
                <li>
                    Los datos se guardan cifrados y no se comparten con nadie
                </li>
            </ul>

            <div v-if="!$page.props.auth.user" class="pt-2">
                <Button size="lg" as-child>
                    <Link :href="register()">Empezar</Link>
                </Button>
            </div>
        </main>

        <footer class="mx-auto flex w-full max-w-3xl flex-wrap gap-x-5 text-sm">
            <Link
                :href="terminos()"
                class="inline-flex min-h-11 items-center text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
            >
                Términos y condiciones
            </Link>
            <Link
                :href="privacidad()"
                class="inline-flex min-h-11 items-center text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
            >
                Privacidad
            </Link>
            <Link
                :href="cookies()"
                class="inline-flex min-h-11 items-center text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
            >
                Cookies y datos guardados
            </Link>
        </footer>
    </div>
</template>
