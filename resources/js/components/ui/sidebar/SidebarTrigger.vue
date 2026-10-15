<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { Menu } from "@lucide/vue"
import { cn } from "@/lib/utils"
import { Button } from '@/components/ui/button'
import { useSidebar } from "./utils"

const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()

const { toggleSidebar } = useSidebar()
</script>

<template>
  <Button
    data-sidebar="trigger"
    data-slot="sidebar-trigger"
    variant="ghost"
    size="icon"
    :class="cn(props.class)"
    @click="toggleSidebar"
  >
    <!--
      Hamburguesa clásica, el mismo ícono abierto o cerrado y en celular o en
      escritorio: es el que todo el mundo reconoce como "el menú". El del kit
      (un panel con una flecha) cambiaba según el estado y no se leía como
      menú. Un poco más grande que el resto de los íconos: es la puerta a toda
      la app.
    -->
    <Menu class="size-6" />
    <span class="sr-only">Abrir o cerrar el menú</span>
  </Button>
</template>
