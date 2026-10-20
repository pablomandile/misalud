<script setup lang="ts">
/*
 * Todas las reglas de una contraseña nueva, a la vista. Aparece cuando la
 * clave que se eligió falla: el servidor contesta un solo error a la vez
 * ("debe tener 12 caracteres") y quien lo corrige se choca con la regla
 * siguiente, una y otra vez. Con la lista entera se arregla en un solo intento.
 *
 * Tiene que coincidir con `Password::defaults()` de `AppServiceProvider`.
 */
defineProps<{ visible: boolean }>();
</script>

<template>
    <div
        v-if="visible"
        role="note"
        class="rounded-md border border-input bg-muted/40 p-3 text-sm"
    >
        <p class="font-medium">La contraseña tiene que cumplir todo esto:</p>
        <ul class="mt-1 list-disc space-y-0.5 pl-5">
            <li>12 caracteres o más.</li>
            <li>Una letra mayúscula y una minúscula.</li>
            <li>Al menos un número.</li>
            <li>Al menos un símbolo, por ejemplo ! ? - _ . , @ #</li>
            <li>
                Que no sea una clave conocida o muy usada (como «qwerty» o
                «123456»): se rechaza si apareció en filtraciones.
            </li>
        </ul>
    </div>
</template>
