<script setup lang="ts">
import { Plus } from '@lucide/vue';
import { nextTick } from 'vue';
import { Button } from '@/components/ui/button';
import { VARIABLES } from '@/lib/plantillaDePedido';

/*
 * Botones para meter una variable ({nombre}, {apellido}, {medicamento}) donde
 * está el cursor del campo, sin tener que escribir las llaves. Con el campo sin
 * tocar todavía, va al final. Después deja el cursor justo detrás de lo que
 * insertó, para seguir escribiendo.
 */
const props = defineProps<{ para: string }>();
const texto = defineModel<string>({ required: true });

function insertar(marcador: string): void {
    const campo = document.getElementById(
        props.para,
    ) as HTMLTextAreaElement | null;
    const inicio = campo?.selectionStart ?? texto.value.length;
    const fin = campo?.selectionEnd ?? inicio;

    texto.value =
        texto.value.slice(0, inicio) + marcador + texto.value.slice(fin);

    nextTick(() => {
        campo?.focus();
        campo?.setSelectionRange(
            inicio + marcador.length,
            inicio + marcador.length,
        );
    });
}
</script>

<template>
    <div class="flex flex-wrap items-center gap-2">
        <span class="text-sm text-muted-foreground">Insertar:</span>
        <Button
            v-for="variable in VARIABLES"
            :key="variable.marcador"
            type="button"
            variant="outline"
            size="sm"
            :aria-label="`Insertar ${variable.rotulo.toLowerCase()} en el mensaje`"
            @click="insertar(variable.marcador)"
        >
            <Plus />
            {{ variable.rotulo }}
        </Button>
    </div>
</template>
