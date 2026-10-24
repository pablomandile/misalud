<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';

/**
 * El día y el mes de un medicamento de "una vez por año". Sin año: la próxima
 * aplicación la calcula el servidor. Sin estado local, con `:value`, igual que
 * el resto del formulario de edición.
 */
defineProps<{
    id: string;
    dia?: number | null;
    mes?: number | null;
    errorDia?: string;
    errorMes?: string;
}>();

const campo =
    'flex min-h-11 w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';

const meses = [
    'enero',
    'febrero',
    'marzo',
    'abril',
    'mayo',
    'junio',
    'julio',
    'agosto',
    'septiembre',
    'octubre',
    'noviembre',
    'diciembre',
];
</script>

<template>
    <fieldset class="grid gap-2">
        <legend class="mb-2 text-sm leading-none font-medium">
            Fecha de la aplicación, cada año
        </legend>
        <div class="grid grid-cols-[minmax(0,1fr)_minmax(0,2fr)] gap-2">
            <div class="grid gap-1">
                <Label :for="`${id}-dia`">Día</Label>
                <select :id="`${id}-dia`" name="aplicacion_dia" :class="campo">
                    <option value="" class="bg-background text-foreground">
                        —
                    </option>
                    <option
                        v-for="n in 31"
                        :key="n"
                        :value="n"
                        :selected="dia === n"
                        class="bg-background text-foreground"
                    >
                        {{ n }}
                    </option>
                </select>
            </div>
            <div class="grid gap-1">
                <Label :for="`${id}-mes`">Mes</Label>
                <select :id="`${id}-mes`" name="aplicacion_mes" :class="campo">
                    <option value="" class="bg-background text-foreground">
                        —
                    </option>
                    <option
                        v-for="(nombre, i) in meses"
                        :key="nombre"
                        :value="i + 1"
                        :selected="mes === i + 1"
                        class="bg-background text-foreground"
                    >
                        {{ nombre }}
                    </option>
                </select>
            </div>
        </div>
        <p class="text-sm text-muted-foreground">
            La próxima se calcula sola. Te avisamos por mail un mes antes y una
            semana antes.
        </p>
        <InputError :message="errorDia" />
        <InputError :message="errorMes" />
    </fieldset>
</template>
