<script setup lang="ts">
import { Minus, Plus } from '@lucide/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

/**
 * Cuántas tomas por día y un horario para cada una.
 *
 * Es un componente aparte porque **sí** tiene estado local: el contador
 * decide cuántos campos de hora hay. Ese estado redibuja solo este componente;
 * adentro del formulario de edición, que va con `:value`, pisaría lo tipeado
 * en los demás campos (la regla de la Etapa 10). Por lo mismo, acá todo va con
 * `v-model`.
 *
 * El contador y los horarios a partir del segundo se ven solo con "Todos los
 * días" o "Algunos días de la semana", y eso lo decide el CSS del contenedor de
 * la toma (`group/toma`), sin preguntarle a Vue. Con otra toma queda un solo
 * horario, y el servidor descarta lo que quedó oculto.
 */
const MAXIMO = 6;

const props = defineProps<{
    id: string;
    tomasIniciales?: number | null;
    horasIniciales?: string[];
    errores: Record<string, string | undefined>;
    claseCampo: string;
}>();

const tomas = ref(
    Math.min(
        Math.max(props.tomasIniciales ?? props.horasIniciales?.length ?? 1, 1),
        MAXIMO,
    ),
);
const horas = ref<string[]>([...(props.horasIniciales ?? [])]);

function cambiar(delta: number): void {
    tomas.value = Math.min(Math.max(tomas.value + delta, 1), MAXIMO);
}

/*
 * Clases escritas enteras: Tailwind solo genera las que encuentra en el
 * código, y un `has-[...]` armado en tiempo de ejecución no existiría.
 */
const soloSiCuenta =
    'hidden group-has-[option[value=diaria]:checked]/toma:flex group-has-[option[value=personalizada]:checked]/toma:flex';
const horarioExtra =
    'hidden gap-2 group-has-[option[value=diaria]:checked]/toma:grid group-has-[option[value=personalizada]:checked]/toma:grid';
</script>

<template>
    <div class="grid gap-3">
        <div :class="soloSiCuenta" class="flex-wrap items-center gap-3">
            <span :id="`${id}-tomas`" class="text-sm font-medium">
                Tomas por día
            </span>
            <div
                class="flex items-center gap-2"
                role="group"
                :aria-labelledby="`${id}-tomas`"
            >
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    aria-label="Una toma menos"
                    :disabled="tomas <= 1"
                    @click="cambiar(-1)"
                >
                    <Minus />
                </Button>
                <output
                    class="min-w-8 text-center text-lg font-semibold"
                    aria-live="polite"
                >
                    {{ tomas }}
                </output>
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    aria-label="Una toma más"
                    :disabled="tomas >= MAXIMO"
                    @click="cambiar(1)"
                >
                    <Plus />
                </Button>
            </div>
            <input type="hidden" name="tomas_por_dia" :value="tomas" />
            <InputError class="basis-full" :message="errores.tomas_por_dia" />
        </div>

        <div
            v-for="(_, indice) in tomas"
            :key="indice"
            :class="indice === 0 ? 'grid gap-2' : horarioExtra"
        >
            <Label :for="`${id}-hora-${indice}`">
                {{
                    tomas > 1
                        ? `Horario de la toma ${indice + 1} (opcional)`
                        : 'Horario (opcional)'
                }}
            </Label>
            <input
                :id="`${id}-hora-${indice}`"
                v-model="horas[indice]"
                name="horas[]"
                type="time"
                :class="claseCampo"
            />
            <InputError :message="errores[`horas.${indice}`]" />
        </div>
    </div>
</template>
