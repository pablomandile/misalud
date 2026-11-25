<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, Plus, X } from '@lucide/vue';
import { nextTick, ref, watch } from 'vue';
import ProcedimientoController from '@/actions/App/Http/Controllers/ProcedimientoController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetClose,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';

/*
 * Armar o editar las etapas del procedimiento de un medicamento: la lista
 * entera, en orden, y un solo «Guardar». Subir y bajar con botones y no
 * arrastrando: arrastrar en un celular es justo lo que esta app evita.
 *
 * ⚠️ Todo con `v-model`: la lista es estado local (sumar, mover, sacar). Se
 * vuelve a copiar de las props cada vez que se abre, así no queda lo que se
 * tocó y no se guardó.
 */

type Fila = { clave: string; id: number | null; nombre: string };

const props = defineProps<{
    tratamientoId: number;
    medicamentoNombre: string;
    etapas: { id: number; nombre: string }[];
}>();

const abierto = defineModel<boolean>('open', { required: true });

const campoBase =
    'flex w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';

const filas = ref<Fila[]>([]);
let siguiente = 0;

watch(
    abierto,
    (valor) => {
        if (!valor) {
            return;
        }

        filas.value = props.etapas.map((etapa) => ({
            clave: `e${etapa.id}`,
            id: etapa.id,
            nombre: etapa.nombre,
        }));

        if (filas.value.length === 0) {
            agregar(false);
        }
    },
    { immediate: true },
);

function agregar(enfocar = true): void {
    const clave = `n${siguiente++}`;
    filas.value.push({ clave, id: null, nombre: '' });

    if (enfocar) {
        nextTick(() =>
            document.getElementById(`etapa-nombre-${clave}`)?.focus(),
        );
    }
}

function mover(i: number, hacia: -1 | 1): void {
    const destino = i + hacia;

    if (destino < 0 || destino >= filas.value.length) {
        return;
    }

    const copia = [...filas.value];
    [copia[i], copia[destino]] = [copia[destino], copia[i]];
    filas.value = copia;
}

function sacar(i: number): void {
    filas.value = filas.value.filter((_, j) => j !== i);
}
</script>

<template>
    <Sheet v-model:open="abierto">
        <SheetContent>
            <Form
                v-bind="ProcedimientoController.update.form(tratamientoId)"
                :options="{ preserveScroll: true }"
                v-slot="{ errors, processing }"
                class="flex h-full flex-col"
                @success="abierto = false"
            >
                <SheetHeader>
                    <SheetTitle>Etapas del pedido</SheetTitle>
                    <SheetDescription>
                        Los pasos para pedir {{ medicamentoNombre }}, en orden.
                        Cambiarlos no toca los pedidos que ya están en el
                        historial.
                    </SheetDescription>
                </SheetHeader>

                <div class="flex-1 space-y-3 overflow-y-auto px-4">
                    <ol class="grid gap-3">
                        <li
                            v-for="(fila, i) in filas"
                            :key="fila.clave"
                            class="space-y-2 rounded-md border p-3"
                        >
                            <input
                                v-if="fila.id !== null"
                                type="hidden"
                                :name="`etapas[${i}][id]`"
                                :value="fila.id"
                            />
                            <div class="grid gap-2">
                                <Label :for="`etapa-nombre-${fila.clave}`">
                                    Etapa {{ i + 1 }}
                                </Label>
                                <input
                                    :id="`etapa-nombre-${fila.clave}`"
                                    v-model="fila.nombre"
                                    :name="`etapas[${i}][nombre]`"
                                    type="text"
                                    required
                                    maxlength="200"
                                    placeholder="Pedir la receta al médico"
                                    :class="`${campoBase} min-h-11`"
                                />
                                <InputError
                                    :message="
                                        errors[`etapas.${i}.nombre`] ??
                                        errors[`etapas.${i}.id`]
                                    "
                                />
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    :disabled="i === 0"
                                    @click="mover(i, -1)"
                                >
                                    <ArrowUp />
                                    Subir
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    :disabled="i === filas.length - 1"
                                    @click="mover(i, 1)"
                                >
                                    <ArrowDown />
                                    Bajar
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    @click="sacar(i)"
                                >
                                    <X />
                                    Sacar
                                </Button>
                            </div>
                        </li>
                    </ol>

                    <p
                        v-if="filas.length === 0"
                        class="rounded-md border border-dashed p-4 text-sm text-muted-foreground"
                    >
                        Sin etapas: al guardar, este medicamento queda sin
                        procedimiento.
                    </p>
                    <InputError :message="errors.etapas" />

                    <Button type="button" variant="outline" @click="agregar()">
                        <Plus />
                        Agregar etapa
                    </Button>
                </div>

                <SheetFooter>
                    <Button type="submit" :disabled="processing">
                        {{ processing ? 'Guardando…' : 'Guardar etapas' }}
                    </Button>
                    <SheetClose as-child>
                        <Button type="button" variant="outline">
                            Cancelar
                        </Button>
                    </SheetClose>
                </SheetFooter>
            </Form>
        </SheetContent>
    </Sheet>
</template>
