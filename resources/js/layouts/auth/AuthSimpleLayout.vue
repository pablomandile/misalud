<script setup lang="ts">
import FondoDeSalud from '@/components/FondoDeSalud.vue';
import { Link } from '@inertiajs/vue3';
import LogoConNombre from '@/components/LogoConNombre.vue';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div
        class="relative isolate flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 md:p-10"
    >
        <FondoDeSalud />
        <div class="w-full max-w-sm">
            <div class="flex flex-col gap-8">
                <div class="flex flex-col items-center gap-4">
                    <Link
                        :href="home()"
                        class="flex min-h-11 flex-col items-center gap-2 font-medium"
                    >
                        <!-- El logo con el nombre: es la presentación de la app. -->
                        <LogoConNombre class="mb-1 h-14" />
                    </Link>
                    <div class="space-y-2 text-center">
                        <h1 class="text-xl font-medium">{{ title }}</h1>
                        <p class="text-center text-sm text-muted-foreground">
                            {{ description }}
                        </p>
                    </div>
                </div>
                <slot />
            </div>
        </div>
    </div>
</template>
