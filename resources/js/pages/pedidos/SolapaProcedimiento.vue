<script setup lang="ts">
import { Form, router } from '@inertiajs/vue3';
import { Check, History, ListPlus, Pencil, RotateCcw } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import AvanceDePedidoController from '@/actions/App/Http/Controllers/AvanceDePedidoController';
import PedidoMedicamentosController from '@/actions/App/Http/Controllers/PedidoMedicamentosController';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AvanceDelPedido from './AvanceDelPedido.vue';
import EtapasDelProcedimiento from './EtapasDelProcedimiento.vue';
import type { MedicamentoDelPedido, Procedimiento } from './tipos';

/*
 * La solapa «Procedimiento»: por cada medicamento de la ficha, las etapas que
 * hay que cumplir para pedirlo, el pedido en curso y el historial de pedidos.
 *
 * El medicamento viaja en la URL (`?medicamento=`): el servidor manda solo el
 * procedimiento del elegido. Sin elegir, es el primero de la lista (los que se
 * toman ahora van primero).
 */

const props = defineProps<{
    paciente: { id: number; nombre: string };
    medicamentos: MedicamentoDelPedido[];
    medicamentoElegido: number | null;
    procedimiento: Procedimiento;
    hoy: string;
}>();

const campoBase =
    'flex w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';

const elegido = ref(String(props.medicamentoElegido ?? ''));
watch(
    () => props.medicamentoElegido,
    (id) => {
        elegido.value = String(id ?? '');
    },
);

function cambiarMedicamento(): void {
    router.get(
        PedidoMedicamentosController.index.url({
            query: {
                paciente: props.paciente.id,
                solapa: 'procedimiento',
                medicamento: elegido.value,
            },
        }),
        {},
        { preserveScroll: true, preserveState: true },
    );
}

const medicamento = computed(() =>
    props.medicamentos.find((m) => m.id === props.medicamentoElegido),
);

const editando = ref(false);

/*
 * Cambia cuando cambia el medicamento, el pedido (se cerró, o se guardó el
 * primero) o la lista de etapas: ahí el formulario tiene que remontarse con lo
 * que mandó el servidor. Ver `AvanceDelPedido`.
 */
const claveDelFormulario = computed(
    () =>
        `${props.medicamentoElegido}-${props.procedimiento.enCurso?.id ?? 'nuevo'}-${props.procedimiento.etapas.map((e) => e.id).join('.')}`,
);
</script>

<template>
    <div v-if="medicamento" class="space-y-6">
        <div class="grid gap-2">
            <Label for="medicamento-procedimiento">Medicamento</Label>
            <select
                id="medicamento-procedimiento"
                v-model="elegido"
                :class="`${campoBase} min-h-11`"
                @change="cambiarMedicamento"
            >
                <option
                    v-for="opcion in medicamentos"
                    :key="opcion.id"
                    :value="String(opcion.id)"
                    class="bg-background text-foreground"
                >
                    {{ opcion.nombre }} · {{ opcion.dosis
                    }}{{ opcion.activo ? '' : ' (suspendido)' }}
                </option>
            </select>
        </div>

        <!-- Sin etapas todavía -->
        <div
            v-if="procedimiento.etapas.length === 0"
            class="rounded-lg border border-dashed p-8 text-center"
        >
            <ListPlus class="mx-auto size-8 text-muted-foreground" />
            <p class="mt-3 text-sm text-muted-foreground">
                {{ medicamento.nombre }} todavía no tiene procedimiento. Armalo
                con los pasos que hacés cada vez que lo pedís: la receta, la
                autorización, la farmacia.
            </p>
            <Button class="mt-4" @click="editando = true">
                <ListPlus />
                Armar el procedimiento
            </Button>
        </div>

        <!-- El pedido en curso -->
        <section v-else class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="grow basis-48 font-medium">
                    {{
                        procedimiento.enCurso
                            ? 'Pedido en curso'
                            : 'Nuevo pedido'
                    }}
                </h2>
                <Button variant="outline" size="sm" @click="editando = true">
                    <Pencil />
                    Editar etapas
                </Button>
            </div>

            <AvanceDelPedido
                :key="claveDelFormulario"
                :tratamiento-id="medicamento.id"
                :procedimiento="procedimiento"
                :hoy="hoy"
            />
        </section>

        <!-- El historial -->
        <section v-if="procedimiento.historial.length > 0" class="space-y-3">
            <h2 class="flex items-center gap-2 font-medium">
                <History class="size-4" />
                Historial de pedidos
            </h2>
            <ul class="grid grid-cols-1 gap-3">
                <li
                    v-for="pedido in procedimiento.historial"
                    :key="pedido.id"
                    class="space-y-3 rounded-md border p-3"
                >
                    <div
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <p class="grow basis-48 font-medium">
                            Del {{ pedido.inicioVisible }} al
                            {{ pedido.finVisible }}
                        </p>
                        <Form
                            v-if="procedimiento.reabrible === pedido.id"
                            v-bind="
                                AvanceDePedidoController.reabrir.form(pedido.id)
                            "
                            :options="{ preserveScroll: true }"
                            v-slot="{ processing }"
                        >
                            <Button
                                type="submit"
                                variant="outline"
                                size="sm"
                                :disabled="processing"
                            >
                                <RotateCcw />
                                Volver a abrir
                            </Button>
                        </Form>
                    </div>
                    <ol class="grid gap-2 text-sm">
                        <li
                            v-for="(paso, i) in pedido.pasos"
                            :key="paso.id"
                            class="space-y-1"
                        >
                            <p class="flex items-start gap-2 break-words">
                                <Check
                                    v-if="paso.completada"
                                    class="mt-0.5 size-4 shrink-0"
                                    aria-hidden="true"
                                />
                                <span>
                                    Etapa {{ i + 1 }}: {{ paso.nombre }}
                                    <span
                                        v-if="paso.completadaElVisible"
                                        class="text-muted-foreground"
                                    >
                                        · terminó el
                                        {{ paso.completadaElVisible }}
                                    </span>
                                </span>
                            </p>
                            <p
                                v-if="paso.observaciones"
                                class="ml-6 break-words whitespace-pre-line text-muted-foreground"
                            >
                                {{ paso.observaciones }}
                            </p>
                        </li>
                    </ol>
                </li>
            </ul>
        </section>

        <EtapasDelProcedimiento
            v-model:open="editando"
            :tratamiento-id="medicamento.id"
            :medicamento-nombre="medicamento.nombre"
            :etapas="procedimiento.etapas"
        />
    </div>
</template>
