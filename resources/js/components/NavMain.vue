<script setup lang="ts">
import IconoSeccion from '@/components/IconoSeccion.vue';
import { Link } from '@inertiajs/vue3';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import type { NavItem } from '@/types';

defineProps<{
    items: NavItem[];
}>();

const { isCurrentUrl } = useCurrentUrl();
</script>

<template>
    <SidebarGroup class="px-2 py-0">
        <!--
            El kit ponía 'Plataforma' acá. Con un solo grupo de destinos, un
            título es ruido: ocupa lugar y no separa nada de nada. Se deja el
            rótulo para el lector de pantalla, que sí necesita saber qué es
            esta lista.
        -->
        <SidebarGroupLabel class="sr-only"
            >Secciones de MiSalud</SidebarGroupLabel
        >
        <SidebarMenu>
            <SidebarMenuItem v-for="item in items" :key="item.title">
                <SidebarMenuButton
                    as-child
                    :is-active="isCurrentUrl(item.href)"
                    :tooltip="item.title"
                >
                    <Link :href="item.href">
                        <IconoSeccion
                            v-if="item.icon && item.tono"
                            :icon="item.icon"
                            :tono="item.tono"
                            class="group-data-[collapsible=icon]:-m-1.5"
                        />
                        <component :is="item.icon" v-else />
                        <span>{{ item.title }}</span>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
