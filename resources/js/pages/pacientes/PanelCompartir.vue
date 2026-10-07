<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import CompartirController from '@/actions/App/Http/Controllers/CompartirController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import type { Acceso, Paciente } from '@/pages/pacientes/Index.vue';

/*
 * Quién ve la ficha de un paciente, y (para el propietario) invitar, cambiar
 * permisos y sacar gente.
 *
 * ⚠️ El padre le pasa el paciente con un `computed` sobre las props, NO con una
 * copia guardada en un `ref`: invitar o sacar a alguien termina en un redirect a
 * la misma pantalla, Inertia trae props nuevas, y una copia vieja mostraría la
 * lista de antes. Es la lección del `const` de la Etapa 8.
 *
 * Los dos permisos que se pueden dar se dicen con lo que permiten ("solo ver" /
 * "ver y cargar datos") y no con el nombre del rol: "cuidador" no le dice a nadie
 * qué va a poder hacer la otra persona.
 */

defineProps<{
    paciente: Paciente | null;
    rolesInvitables: Array<{ valor: string; etiqueta: string }>;
}>();

const emit = defineEmits<{ cerrar: [] }>();

const campoUnaLinea =
    'flex min-h-11 w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';

const accesoASacar = ref<Acceso | null>(null);

function alCerrar(abierto: boolean): void {
    if (!abierto) {
        emit('cerrar');
    }
}
</script>

<template>
    <Sheet :open="!!paciente" @update:open="alCerrar">
        <SheetContent v-if="paciente">
            <SheetHeader>
                <SheetTitle
                    >Quién ve la ficha de {{ paciente.nombre }}</SheetTitle
                >
                <SheetDescription>
                    <template v-if="paciente.esPropietario">
                        La ficha es tuya. Podés invitar a alguien para que la
                        vea o para que te ayude a cargar datos.
                    </template>
                    <template v-else>
                        La ficha es de quien figura como propietario. Solo esa
                        persona puede invitar a alguien más.
                    </template>
                </SheetDescription>
            </SheetHeader>

            <div class="flex-1 space-y-6 overflow-y-auto px-4 pb-4">
                <ul class="space-y-2">
                    <li
                        v-for="acceso in paciente.accesos"
                        :key="acceso.id"
                        class="space-y-2 rounded-lg border p-3"
                    >
                        <div class="min-w-0">
                            <p class="font-medium break-words">
                                {{ acceso.nombre }}
                                <span
                                    v-if="acceso.esVos"
                                    class="font-normal text-muted-foreground"
                                    >(vos)</span
                                >
                            </p>
                            <p
                                v-if="acceso.email"
                                class="text-sm break-all text-muted-foreground"
                            >
                                {{ acceso.email }}
                            </p>
                            <p class="text-sm">
                                <template v-if="acceso.rol === 'propietario'">
                                    Propietario
                                </template>
                                <template v-else-if="acceso.rol === 'cuidador'">
                                    Puede ver y cargar datos
                                </template>
                                <template v-else>Solo puede ver</template>
                            </p>
                        </div>

                        <!-- El propietario: cambiar el permiso o sacar a alguien. -->
                        <div
                            v-if="
                                paciente.esPropietario &&
                                acceso.rol !== 'propietario'
                            "
                            class="flex flex-wrap gap-2"
                        >
                            <Form
                                v-bind="
                                    CompartirController.cambiarAcceso.form({
                                        paciente: paciente.id,
                                        usuario: acceso.id,
                                    })
                                "
                                :options="{ preserveScroll: true }"
                                v-slot="{ processing }"
                            >
                                <input
                                    type="hidden"
                                    name="rol"
                                    :value="
                                        acceso.rol === 'cuidador'
                                            ? 'lector'
                                            : 'cuidador'
                                    "
                                />
                                <Button
                                    type="submit"
                                    variant="secondary"
                                    :disabled="processing"
                                >
                                    {{
                                        acceso.rol === 'cuidador'
                                            ? 'Dejar solo para ver'
                                            : 'Dejarle cargar datos'
                                    }}
                                </Button>
                            </Form>
                            <Button
                                variant="ghost"
                                size="icon"
                                class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                :aria-label="`Sacarle el acceso a ${acceso.nombre}`"
                                @click="accesoASacar = acceso"
                            >
                                <Trash2 class="size-4" />
                            </Button>
                        </div>

                        <!-- Cualquiera que no sea el dueño se puede ir solo. -->
                        <Button
                            v-if="acceso.esVos && acceso.rol !== 'propietario'"
                            variant="secondary"
                            @click="accesoASacar = acceso"
                        >
                            Dejar de ver esta ficha
                        </Button>
                    </li>
                </ul>

                <!-- Invitar: solo el propietario. -->
                <section v-if="paciente.esPropietario" class="space-y-4">
                    <h3 class="font-medium">Invitar a alguien</h3>

                    <Form
                        v-bind="
                            CompartirController.invitar.form({
                                paciente: paciente.id,
                            })
                        "
                        reset-on-success
                        :options="{ preserveScroll: true }"
                        v-slot="{ errors, processing }"
                        class="space-y-4"
                    >
                        <div class="grid gap-2">
                            <Label for="invitar-email"
                                >Su dirección de correo</Label
                            >
                            <input
                                id="invitar-email"
                                name="email"
                                type="email"
                                inputmode="email"
                                autocomplete="off"
                                required
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.email" />
                        </div>

                        <fieldset class="grid gap-2">
                            <legend class="mb-2 text-sm font-medium">
                                Qué va a poder hacer
                            </legend>
                            <label
                                v-for="(rol, i) in rolesInvitables"
                                :key="rol.valor"
                                class="flex min-h-11 cursor-pointer items-start gap-3 rounded-md border p-3 has-[:checked]:border-primary"
                            >
                                <input
                                    type="radio"
                                    name="rol"
                                    :value="rol.valor"
                                    :checked="i === 0"
                                    class="mt-1 size-5 shrink-0"
                                />
                                <span class="text-sm">
                                    <template v-if="rol.valor === 'cuidador'">
                                        <strong>Ver y cargar datos</strong>:
                                        turnos, mediciones, estudios,
                                        tratamientos. No puede invitar a nadie
                                        ni borrar la ficha.
                                    </template>
                                    <template v-else>
                                        <strong>Solo ver</strong>: la historia
                                        completa, sin cargar ni cambiar nada.
                                    </template>
                                </span>
                            </label>
                            <InputError :message="errors.rol" />
                        </fieldset>

                        <p class="text-sm text-muted-foreground">
                            Le llega un mail con un enlace que vale 7 días.
                            Tiene que abrirlo con una cuenta de esa misma
                            dirección.
                        </p>

                        <Button type="submit" :disabled="processing">
                            {{ processing ? 'Mandando…' : 'Mandar invitación' }}
                        </Button>
                    </Form>
                </section>
            </div>
        </SheetContent>
    </Sheet>

    <!-- Confirmar: sacar a alguien, o irse uno mismo. -->
    <Dialog
        :open="!!accesoASacar"
        @update:open="
            (v: boolean) => {
                if (!v) accesoASacar = null;
            }
        "
    >
        <DialogContent v-if="accesoASacar && paciente">
            <Form
                v-bind="
                    CompartirController.revocarAcceso.form({
                        paciente: paciente.id,
                        usuario: accesoASacar.id,
                    })
                "
                :options="{ preserveScroll: true }"
                @success="accesoASacar = null"
                v-slot="{ processing }"
            >
                <DialogHeader class="space-y-3">
                    <DialogTitle>
                        <template v-if="accesoASacar.esVos">
                            ¿Dejar de ver la ficha de {{ paciente.nombre }}?
                        </template>
                        <template v-else>
                            ¿Sacarle el acceso a {{ accesoASacar.nombre }}?
                        </template>
                    </DialogTitle>
                    <DialogDescription>
                        <template v-if="accesoASacar.esVos">
                            Para volver a verla, te tienen que invitar de nuevo.
                        </template>
                        <template v-else>
                            Deja de ver la ficha al instante. Lo que ya cargó se
                            queda. Si te arrepentís, lo podés volver a invitar.
                        </template>
                    </DialogDescription>
                </DialogHeader>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button type="button" variant="secondary">
                            Cancelar
                        </Button>
                    </DialogClose>
                    <Button
                        type="submit"
                        variant="destructive"
                        :disabled="processing"
                    >
                        {{
                            accesoASacar.esVos
                                ? 'Dejar de verla'
                                : 'Sacarle el acceso'
                        }}
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
