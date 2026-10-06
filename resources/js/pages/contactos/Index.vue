<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { BookUser, Plus, Send, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import ContactoController from '@/actions/App/Http/Controllers/ContactoController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
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
    SheetClose,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';

/*
 * La libreta de a quién se le manda documentación y, debajo, lo que se le
 * mandó. Las dos cosas juntas porque la pregunta que trae a alguien acá es
 * "¿qué le mandé a OSDE?", y siempre arranca por el contacto.
 *
 * Un envío NO se arma desde acá: arranca desde el documento ("Enviar" al lado
 * de una receta o una orden), que es cuando se sabe de qué paciente se trata.
 */

type Contacto = {
    id: number;
    nombre: string;
    email: string;
    tipo: string;
    tipoEtiqueta: string;
};

type Envio = {
    id: number;
    destinatario: string;
    destinatarioNombre: string;
    asunto: string;
    cuandoVisible: string;
    estado: string;
    estadoEtiqueta: string;
    archivos: string[];
};

const props = defineProps<{
    contactos: Contacto[];
    envios: Envio[];
    tipos: Array<{ valor: string; etiqueta: string }>;
    /** Viene de "Guardar como contacto" en una cobertura. */
    precarga: { nombre: string; tipo: string } | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Contactos', href: ContactoController.index() }],
    },
});

const campoUnaLinea =
    'flex min-h-11 w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';

// Si se llegó desde una cobertura, el alta abre sola y ya con el nombre puesto.
const sheetCrearAbierto = ref(props.precarga !== null);
const contactoAEditar = ref<Contacto | null>(null);
const contactoABorrar = ref<Contacto | null>(null);
</script>

<template>
    <Head title="Contactos" />

    <div class="space-y-6">
        <div class="flex items-center justify-between gap-4">
            <Heading
                variant="small"
                title="Contactos"
                description="A quién le mandás documentación"
            />
            <Button @click="sheetCrearAbierto = true">
                <Plus />
                Agregar
            </Button>
        </div>

        <div
            v-if="contactos.length === 0"
            class="rounded-lg border border-dashed p-8 text-center"
        >
            <BookUser class="mx-auto size-8 text-muted-foreground" />
            <p class="mt-3 text-sm text-muted-foreground">
                Todavía no agregaste a nadie. Cargá la farmacia, tu obra social
                o tu médico para poder mandarles documentos.
            </p>
        </div>

        <div v-else class="grid gap-3 sm:grid-cols-2">
            <Card v-for="contacto in contactos" :key="contacto.id">
                <CardContent class="space-y-3">
                    <div class="min-w-0 space-y-1">
                        <p class="font-medium break-words">
                            {{ contacto.nombre }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{ contacto.tipoEtiqueta }}
                        </p>
                        <p class="text-sm break-all">{{ contacto.email }}</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            variant="ghost"
                            @click="contactoAEditar = contacto"
                        >
                            Editar
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                            :aria-label="`Borrar a ${contacto.nombre}`"
                            @click="contactoABorrar = contacto"
                        >
                            <Trash2 class="size-4" />
                        </Button>
                    </div>
                </CardContent>
            </Card>
        </div>

        <!-- Lo que se mandó: lo que se consulta cuando alguien dice "no nos llegó nada". -->
        <section class="space-y-3">
            <h2 class="font-medium">Lo que mandaste</h2>

            <p v-if="envios.length === 0" class="text-sm text-muted-foreground">
                Todavía no mandaste nada. Para mandar un documento, abrilo y
                tocá «Enviar».
            </p>

            <Card v-for="envio in envios" :key="envio.id">
                <CardContent class="space-y-2">
                    <div class="flex items-start gap-3">
                        <Send
                            class="mt-0.5 size-5 shrink-0 text-muted-foreground"
                        />
                        <div class="min-w-0 space-y-1">
                            <p class="font-medium break-words">
                                {{ envio.asunto }}
                            </p>
                            <p class="text-sm break-all text-muted-foreground">
                                A {{ envio.destinatarioNombre }} ({{
                                    envio.destinatario
                                }})
                            </p>
                            <p class="text-sm text-muted-foreground">
                                {{ envio.cuandoVisible }} ·
                                {{ envio.estadoEtiqueta }}
                            </p>
                        </div>
                    </div>
                    <ul class="space-y-1 pl-8 text-sm">
                        <li
                            v-for="(archivo, i) in envio.archivos"
                            :key="i"
                            class="break-all"
                        >
                            {{ archivo }}
                        </li>
                    </ul>
                </CardContent>
            </Card>
        </section>

        <!-- Alta -->
        <Sheet v-model:open="sheetCrearAbierto">
            <SheetContent>
                <Form
                    v-bind="ContactoController.store.form()"
                    reset-on-success
                    :options="{ preserveScroll: true }"
                    @success="sheetCrearAbierto = false"
                    v-slot="{ errors, processing }"
                    class="flex h-full flex-col"
                >
                    <SheetHeader>
                        <SheetTitle>Agregar un contacto</SheetTitle>
                        <SheetDescription>
                            Revisá bien la dirección: es a donde van a ir los
                            documentos.
                        </SheetDescription>
                    </SheetHeader>

                    <div class="flex-1 space-y-4 overflow-y-auto px-4">
                        <div class="grid gap-2">
                            <Label for="nombre-crear">Nombre</Label>
                            <input
                                id="nombre-crear"
                                name="nombre"
                                type="text"
                                required
                                :value="precarga?.nombre ?? ''"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.nombre" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="email-crear">Dirección de correo</Label>
                            <input
                                id="email-crear"
                                name="email"
                                type="email"
                                inputmode="email"
                                autocomplete="off"
                                required
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.email" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="tipo-crear">Qué es</Label>
                            <select
                                id="tipo-crear"
                                name="tipo"
                                required
                                :class="campoUnaLinea"
                            >
                                <option
                                    v-for="tipo in tipos"
                                    :key="tipo.valor"
                                    :value="tipo.valor"
                                    :selected="tipo.valor === precarga?.tipo"
                                >
                                    {{ tipo.etiqueta }}
                                </option>
                            </select>
                            <InputError :message="errors.tipo" />
                        </div>
                    </div>

                    <SheetFooter>
                        <Button type="submit" :disabled="processing">
                            Guardar
                        </Button>
                        <SheetClose as-child>
                            <Button type="button" variant="secondary">
                                Cancelar
                            </Button>
                        </SheetClose>
                    </SheetFooter>
                </Form>
            </SheetContent>
        </Sheet>

        <!-- Edición -->
        <Sheet
            :open="!!contactoAEditar"
            @update:open="
                (v: boolean) => {
                    if (!v) contactoAEditar = null;
                }
            "
        >
            <SheetContent v-if="contactoAEditar">
                <Form
                    v-bind="
                        ContactoController.update.form({
                            contacto: contactoAEditar.id,
                        })
                    "
                    :options="{ preserveScroll: true }"
                    @success="contactoAEditar = null"
                    v-slot="{ errors, processing }"
                    class="flex h-full flex-col"
                >
                    <SheetHeader>
                        <SheetTitle>Editar el contacto</SheetTitle>
                        <SheetDescription>
                            Lo que ya le mandaste sigue registrado con la
                            dirección de ese momento.
                        </SheetDescription>
                    </SheetHeader>

                    <div class="flex-1 space-y-4 overflow-y-auto px-4">
                        <div class="grid gap-2">
                            <Label for="nombre-editar">Nombre</Label>
                            <input
                                id="nombre-editar"
                                name="nombre"
                                type="text"
                                required
                                :value="contactoAEditar.nombre"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.nombre" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="email-editar"
                                >Dirección de correo</Label
                            >
                            <input
                                id="email-editar"
                                name="email"
                                type="email"
                                inputmode="email"
                                autocomplete="off"
                                required
                                :value="contactoAEditar.email"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.email" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="tipo-editar">Qué es</Label>
                            <select
                                id="tipo-editar"
                                name="tipo"
                                required
                                :class="campoUnaLinea"
                            >
                                <option
                                    v-for="tipo in tipos"
                                    :key="tipo.valor"
                                    :value="tipo.valor"
                                    :selected="
                                        tipo.valor === contactoAEditar.tipo
                                    "
                                >
                                    {{ tipo.etiqueta }}
                                </option>
                            </select>
                            <InputError :message="errors.tipo" />
                        </div>
                    </div>

                    <SheetFooter>
                        <Button type="submit" :disabled="processing">
                            Guardar
                        </Button>
                        <SheetClose as-child>
                            <Button type="button" variant="secondary">
                                Cancelar
                            </Button>
                        </SheetClose>
                    </SheetFooter>
                </Form>
            </SheetContent>
        </Sheet>

        <!-- Borrar -->
        <Dialog
            :open="!!contactoABorrar"
            @update:open="
                (v: boolean) => {
                    if (!v) contactoABorrar = null;
                }
            "
        >
            <DialogContent v-if="contactoABorrar">
                <Form
                    v-bind="
                        ContactoController.destroy.form({
                            contacto: contactoABorrar.id,
                        })
                    "
                    :options="{ preserveScroll: true }"
                    @success="contactoABorrar = null"
                    v-slot="{ processing }"
                >
                    <DialogHeader class="space-y-3">
                        <DialogTitle>
                            ¿Borrar a {{ contactoABorrar.nombre }}?
                        </DialogTitle>
                        <DialogDescription>
                            Sale de tu libreta. Lo que ya le mandaste sigue en
                            el historial.
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
                            Borrar
                        </Button>
                    </DialogFooter>
                </Form>
            </DialogContent>
        </Dialog>
    </div>
</template>
