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
        "default": "min-h-9 px-4 py-2 has-[>svg]:px-3",
        "sm": "min-h-8 rounded-md gap-1.5 px-3 has-[>svg]:px-2.5",
        "lg": "min-h-10 rounded-md px-6 has-[>svg]:px-4",
        "icon": "size-9",
        "icon-sm": "size-8",
        "icon-lg": "size-10",
      },
    },
    defaultVariants: {
      variant: "default",
      size: "default",
    },
  },
)
export type ButtonVariants = VariantProps<typeof buttonVariants>
