<script lang="ts" setup>
import type { PrimitiveProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { Primitive } from "reka-ui"
import { cn } from "@/lib/utils"

const props = withDefaults(defineProps<PrimitiveProps & { class?: HTMLAttributes["class"] }>(), {
  as: "a",
})
</script>

<template>
  <Primitive
    data-slot="breadcrumb-link"
    :as="as"
    :as-child="asChild"
    :class="
      cn(
        // `inline-flex` + `min-h-11`: el área táctil llega a 44px sin que el
        // texto cambie de tamaño, igual que el checkbox. Un breadcrumb acá
        // no es adorno —es el camino de vuelta— y medía 23px, que en un
        // celular no se acierta. Aparece recién con dos niveles: con uno
        // solo, el último tramo se dibuja como texto y no como enlace.
        'hover:text-foreground inline-flex min-h-11 items-center transition-colors',
        props.class,
      )
    "
  >
    <slot />
  </Primitive>
</template>
