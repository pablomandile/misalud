import type { VariantProps } from "class-variance-authority"
import { cva } from "class-variance-authority"

export { default as Button } from "./Button.vue"

/*
 * MODIFICADO respecto de shadcn-vue. No lo pises con un `shadcn-vue add`.
 *
 * El original trae `whitespace-nowrap` y alturas fijas (`h-9`). En una app
 * pensada para letra grande eso desborda la pantalla: medido a 320 px con el
 * tamaño "Muy grande", el botón "Entrar con una llave de acceso" se salía 16 px
 * y aparecía scroll horizontal en todas las pantallas.
 *
 * Son dos causas juntas: el español es más largo que el inglés del starter kit,
 * y los breakpoints de Tailwind NO escalan con el tamaño de letra (las media
 * queries se miden en px contra el viewport). Así que el caso "pantalla chica +
 * letra grande" no lo cubre ningún `sm:`.
 *
 * La corrección es dejar que el texto corte (`whitespace-normal`) y que el
 * botón crezca en alto (`min-h-*` en vez de `h-*`). Los tamaños de ícono
 * siguen siendo cuadrados fijos: ahí no hay texto que cortar.
 *
 * El piso es 44 px (`min-h-11` = 2.75rem), que es el mínimo de área táctil que
 * pide este proyecto. Los originales de shadcn apuntan a 36 px y ninguno
 * llegaba: medido en Chrome, con el tamaño de letra POR DEFECTO el botón
 * principal quedaba en 41 px.
 *
 * `rem` y no `px` a propósito: la raíz escala con el tamaño de letra elegido,
 * así que 44 px es el piso en "Normal" y crece desde ahí. Con `px` fijos, el
 * botón se quedaría chico justo para quien agrandó la letra.
 *
 * **`sm` acá significa menos padding, NUNCA un objetivo más chico.** Por eso
 * `sm` e `icon-sm` comparten el piso con los normales: un botón de 32 px en una
 * app para personas mayores es el problema, no una variante.
 */
export const buttonVariants = cva(
  "inline-flex items-center justify-center gap-2 whitespace-normal text-center rounded-md text-sm font-medium transition-all disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-4 shrink-0 [&_svg]:shrink-0 outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive",
  {
    variants: {
      variant: {
        default:
          "bg-primary text-primary-foreground hover:bg-primary/90",
        destructive:
          "bg-destructive text-white hover:bg-destructive/90 focus-visible:ring-destructive/20 dark:focus-visible:ring-destructive/40 dark:bg-destructive/60",
        outline:
          "border bg-background shadow-xs hover:bg-accent hover:text-accent-foreground dark:bg-input/30 dark:border-input dark:hover:bg-input/50",
        secondary:
          "bg-secondary text-secondary-foreground hover:bg-secondary/80",
        ghost:
          "hover:bg-accent hover:text-accent-foreground dark:hover:bg-accent/50",
        link: "text-primary underline-offset-4 hover:underline",
      },
      size: {
        "default": "min-h-11 px-4 py-2 has-[>svg]:px-3",
        "sm": "min-h-11 rounded-md gap-1.5 px-3 has-[>svg]:px-2.5",
        "lg": "min-h-12 rounded-md px-6 has-[>svg]:px-4",
        "icon": "size-11",
        "icon-sm": "size-11",
        "icon-lg": "size-12",
      },
    },
    defaultVariants: {
      variant: "default",
      size: "default",
    },
  },
)
export type ButtonVariants = VariantProps<typeof buttonVariants>
