<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { Save } from '@lucide/vue';
import { ref } from 'vue';
import AvanceDePedidoController from '@/actions/App/Http/Controllers/AvanceDePedidoController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import type { Procedimiento } from './tipos';

/*
 * El pedido en curso de un medicamento: cuándo empezó y, por cada etapa, si se
 * completó, cuándo terminó y qué pasó. Al guardar con todas tildadas, el
 * servidor lo pasa al historial y esto vuelve en blanco.
 *
 * ⚠️ Todo con `v-model`: tildar muestra la fecha (estado local), y un `:value`
 * en un hermano se pisaría con cada cambio (la regla de la Etapa 10). El estado
 * sale de las props UNA vez: quien lo usa le pone un `:key` que cambia cuando
 * el pedido o sus etapas cambian, y así se remonta con los datos nuevos.
 *
 * La casilla del kit es un botón y no un input: su valor viaja en un
 * `<input type="hidden">` espejo, con "1" o "0".
 */

const props = defineProps<{
    tratamientoId: number;
    procedimiento: Procedimiento;
    hoy: string;
}>();

const campoBase =
    'flex w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';

const iniciadoEl = ref(props.procedimiento.enCurso?.iniciadoEl ?? props.hoy);

const pasos = ref(
    props.procedimiento.pasos.map((paso) => ({
        etapaId: paso.etapaId,
        completada: paso.completada,
        completadaEl: paso.completadaEl ?? '',
        observaciones: paso.observaciones,
    })),
);

// Al tildar, la fecha de fin viene con hoy (del servidor, en la zona de la cuenta).
function tildar(i: number, valor: boolean | 'indeterminate'): void {
    const paso = pasos.value[i];
    paso.completada = valor === true;

    if (paso.completada && paso.completadaEl === '') {
        paso.completadaEl = props.hoy;
    }
}

const nombreDe = (i: number) => props.procedimiento.pasos[i]?.nombre ?? '';
</script>

<template>
    <Form
        v-bind="AvanceDePedidoController.update.form(tratamientoId)"
        :options="{ preserveScroll: true }"
        v-slot="{ errors, processing }"
        class="space-y-4"
    >
        <div class="grid gap-2 sm:max-w-xs">
            <Label for="inicio-pedido">Inicio del pedido</Label>
            <input
                id="inicio-pedido"
                v-model="iniciadoEl"
                name="iniciado_el"
                type="date"
                required
                :max="hoy"
                :class="`${campoBase} min-h-11`"
            />
            <InputError :message="errors.iniciado_el" />
        </div>

        <ol class="grid gap-3">
            <li
                v-for="(paso, i) in pasos"
                :key="paso.etapaId"
                class="space-y-3 rounded-md border p-3"
            >
                <input
                    type="hidden"
                    :name="`pasos[${i}][etapa_id]`"
                    :value="paso.etapaId"
                />
                <input
                    type="hidden"
                    :name="`pasos[${i}][completada]`"
                    :value="paso.completada ? '1' : '0'"
                />

                <div class="grid gap-1">
                    <Label
                        :for="`etapa-${paso.etapaId}`"
                        class="flex min-h-11 items-center gap-3"
                    >
                        <Checkbox
                            :id="`etapa-${paso.etapaId}`"
                            :model-value="paso.completada"
                            @update:model-value="tildar(i, $event)"
                        />
                        <span class="text-base font-medium break-words">
                            Etapa {{ i + 1 }}: {{ nombreDe(i) }}
                        </span>
                    </Label>
                    <InputError :message="errors[`pasos.${i}.completada`]" />
                </div>

                <div v-if="paso.completada" class="grid gap-2 sm:max-w-xs">
                    <Label :for="`fin-etapa-${paso.etapaId}`">Terminó el</Label>
                    <input
                        :id="`fin-etapa-${paso.etapaId}`"
                        v-model="paso.completadaEl"
                        :name="`pasos[${i}][completada_el]`"
                        type="date"
                        required
                        :min="iniciadoEl"
                        :max="hoy"
                        :class="`${campoBase} min-h-11`"
                    />
                    <InputError :message="errors[`pasos.${i}.completada_el`]" />
                </div>

                <div class="grid gap-2">
                    <Label :for="`observaciones-etapa-${paso.etapaId}`">
                        Observaciones
                    </Label>
                    <textarea
                        :id="`observaciones-etapa-${paso.etapaId}`"
                        v-model="paso.observaciones"
                        :name="`pasos[${i}][observaciones]`"
                        rows="2"
                        :class="`${campoBase} min-h-16`"
                    />
                    <InputError
                        :message="
                            errors[`pasos.${i}.observaciones`] ??
                            errors[`pasos.${i}.etapa_id`]
                        "
                    />
                </div>
            </li>
        </ol>
        <InputError :message="errors.pasos" />

        <p class="text-sm text-muted-foreground">
            Cuando completes la última etapa, el pedido pasa al historial y esto
            queda listo para el próximo.
        </p>

        <Button type="submit" class="w-full sm:w-auto" :disabled="processing">
            <Save />
            {{ processing ? 'Guardando…' : 'Guardar avance' }}
        </Button>
    </Form>
</template>
