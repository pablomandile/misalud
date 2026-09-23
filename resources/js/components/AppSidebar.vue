<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { onUnmounted } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { destinosPrincipales, destinosSecundarios } from '@/lib/navegacion';
import { dashboard } from '@/routes';

const { isMobile, setOpenMobile } = useSidebar();

/*
 * En una SPA la navegación no desmonta el menú: elegís una opción, la pantalla
 * nueva carga detrás y el sheet queda encima tapándola, con el scroll del body
 * bloqueado. En escritorio no pasa, así que se escapa a cualquier revisión que
 * no se haga en un viewport de celular.
 *
 * Tres detalles, y ninguno sobra:
 *
 * 1. Va en el router y no en cada `<Link>`. Hay tres grupos de enlaces —el
 *    logo, la navegación y el menú de usuario— y agregando el enlace número
 *    siete alguien se olvida seguro.
 * 2. Solo en mobile. En escritorio la barra es fija: cerrarla al navegar
 *    dejaría al usuario sin menú a cada paso.
 * 3. `navigate` y no `start`. Con `start` el menú se cierra al tocar, antes de
 *    que llegue la página: se siente más rápido, pero si la visita falla queda
 *    sin menú y sin página. Y `start` dispara también en cualquier
 *    `router.reload()` de fondo.
 */
onUnmounted(
    router.on('navigate', () => {
        if (isMobile.value) {
            setOpenMobile(false);
        }
    }),
);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="destinosPrincipales" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="destinosSecundarios" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
