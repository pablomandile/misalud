<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import {
    Building2,
    Copy,
    Globe,
    Mail,
    MessageCircle,
    Plus,
    Trash2,
} from '@lucide/vue';
import { ref } from 'vue';
import CentroController from '@/actions/App/Http/Controllers/CentroController';
import Heading from '@/components/Heading.vue';
import SolapasDeCatalogo from '@/components/SolapasDeCatalogo.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
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
 * El catálogo de centros: copiado de médicos (ver esa pantalla), con dos
 * cosas de más — el tipo de centro y el checklist de médicos que atienden
 * ahí, que arma `centro_medico`.
 *
 * Un catálogo es del USUARIO y no de un paciente: por eso tampoco acá hay
 * selector de paciente.
 */

type MedicoDisponible = { id: number; nombre: string };

type Centro = {
    id: number;
    nombre: string;
    tipo: string;
    tipoEtiqueta: string;
    direccion: string | null;
    telefono: string | null;
    whatsapp: string | null;
    /** Armado por el servidor (`Centro::enlaceWhatsapp()`), con el 549 delante. */
    whatsappEnlace: string | null;
    email: string | null;
    web: string | null;
    horarios: string | null;
    notas: string | null;
    esSemilla: boolean;
    /** Lo que no es de esta cuenta: una semilla o lo de una ficha compartida. */
    soloLectura: boolean;
    medicos: MedicoDisponible[];
};

defineProps<{
    registros: Centro[];
    fichasCompartidas: { id: number; nombre: string }[];
    ficha: { id: number; nombre: string } | null;
    fichaCompleta: boolean;
    medicosDisponibles: MedicoDisponible[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Centros', href: CentroController.index() }],
    },
});

const campoBase =
    'flex w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';
const campoUnaLinea = `${campoBase} min-h-11`;
const campoTexto = `${campoBase} min-h-24`;

const sheetCrearAbierto = ref(false);
const centroAEditar = ref<Centro | null>(null);
const centroABorrar = ref<Centro | null>(null);

function estaVinculado(centro: Centro, medicoId: number): boolean {
    return centro.medicos.some((m) => m.id === medicoId);
}
</script>

<template>
    <Head title="Centros" />

    <div class="space-y-6">
        <div class="flex items-center justify-between gap-4">
            <Heading
                variant="small"
                title="Centros"
                description="Consultorios, clínicas y laboratorios donde atienden tus médicos"
            />
            <Button v-if="!ficha" @click="sheetCrearAbierto = true">
                <Plus />
                Agregar
            </Button>
        </div>

        <SolapasDeCatalogo
            :fichas="fichasCompartidas"
            :ficha="ficha"
            :completa="fichaCompleta"
            :enlace="
                (id) =>
                    CentroController.index(id ? { query: { ficha: id } } : {})
            "
        />

        <div
            v-if="registros.length === 0"
            class="rounded-lg border border-dashed p-8 text-center"
        >
            <Building2 class="mx-auto size-8 text-muted-foreground" />
            <p v-if="ficha" class="mt-3 text-sm text-muted-foreground">
                {{
                    fichaCompleta
                        ? `Quien comparte la ficha de ${ficha.nombre} todavía no cargó ninguno.`
                        : `La ficha de ${ficha.nombre} todavía no usa ninguno.`
                }}
            </p>
            <p v-else class="mt-3 text-sm text-muted-foreground">
                Todavía no agregaste ningún centro.
            </p>
        </div>

        <!-- Tarjetas apiladas y no una tabla: a 320px una tabla desborda. -->
        <div v-else class="grid gap-3 sm:grid-cols-2">
            <Card v-for="centro in registros" :key="centro.id">
                <CardContent class="flex items-start justify-between gap-3">
                    <div class="min-w-0 space-y-1">
                        <p class="truncate font-medium">{{ centro.nombre }}</p>
                        <p class="text-sm text-muted-foreground">
                            {{ centro.tipoEtiqueta }}
                        </p>
                        <p
                            v-if="centro.direccion"
                            class="text-sm text-muted-foreground"
                        >
                            {{ centro.direccion }}
                        </p>
                        <p
                            v-if="centro.telefono"
                            class="text-sm text-muted-foreground"
                        >
                            Tel. {{ centro.telefono }}
                        </p>
                        <p
                            v-if="centro.whatsapp"
                            class="text-sm text-muted-foreground"
                        >
                            WhatsApp {{ centro.whatsapp }}
                        </p>
                        <p
                            v-if="centro.horarios"
                            class="text-sm whitespace-pre-line text-muted-foreground"
                        >
                            {{ centro.horarios }}
                        </p>
                        <!--
                            Unas notas largas no estiran la tarjeta: se leen
                            con su propio scroll. Con tabindex, también con el
                            teclado.
                        -->
                        <p
                            v-if="centro.notas"
                            tabindex="0"
                            aria-label="Notas"
                            class="max-h-32 overflow-y-auto rounded-md bg-muted/40 p-2 text-sm whitespace-pre-line focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                        >
                            {{ centro.notas }}
                        </p>
                        <!--
                            Escribir, mandar un correo o abrir la web, a un
                            toque: con texto al lado del ícono y a 44 px.
                        -->
                        <div
                            v-if="
                                centro.whatsappEnlace ||
                                centro.email ||
                                centro.web
                            "
                            class="flex flex-wrap gap-2 pt-1"
                        >
                            <Button
                                v-if="centro.whatsappEnlace"
                                variant="outline"
                                size="sm"
                                as-child
                            >
                                <a
                                    :href="centro.whatsappEnlace"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    <MessageCircle />
                                    WhatsApp
                                </a>
                            </Button>
                            <Button
                                v-if="centro.email"
                                variant="outline"
                                size="sm"
                                as-child
                            >
                                <a :href="`mailto:${centro.email}`">
                                    <Mail />
                                    Correo
                                </a>
                            </Button>
                            <Button
                                v-if="centro.web"
                                variant="outline"
                                size="sm"
                                as-child
                            >
                                <a
                                    :href="centro.web"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    <Globe />
                                    Web
                                </a>
                            </Button>
                        </div>
                        <p
                            v-if="centro.medicos.length > 0"
                            class="text-sm text-muted-foreground"
                        >
                            {{ centro.medicos.map((m) => m.nombre).join(', ') }}
                        </p>
                        <p
                            v-if="centro.esSemilla"
                            class="text-sm text-muted-foreground"
                        >
                            De la lista compartida
                        </p>
                    </div>

                    <div class="flex shrink-0 flex-wrap justify-end gap-1">
                        <!--
                            Una semilla no se edita: la única acción posible es
                            copiarla al catálogo propio.
                        -->
                        <Form
                            v-if="centro.soloLectura"
                            v-bind="
                                CentroController.duplicar.form({
                                    centro: centro.id,
                                })
                            "
                            :options="{ preserveScroll: true }"
                            v-slot="{ processing }"
                        >
                            <Button
                                type="submit"
                                variant="ghost"
                                size="sm"
                                :disabled="processing"
                            >
                                <Copy />
                                Copiar
                            </Button>
                        </Form>

                        <template v-else>
                            <Button
                                variant="ghost"
                                size="sm"
                                @click="centroAEditar = centro"
                            >
                                Editar
                            </Button>
                            <Button
                                variant="ghost"
                                size="icon-sm"
                                class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                :aria-label="`Eliminar ${centro.nombre}`"
                                @click="centroABorrar = centro"
                            >
                                <Trash2 class="size-4" />
                            </Button>
                        </template>
                    </div>
                </CardContent>
            </Card>
        </div>

        <!-- Crear -->
        <Sheet v-model:open="sheetCrearAbierto">
            <SheetContent>
                <Form
                    v-bind="CentroController.store.form()"
                    reset-on-success
                    :options="{ preserveScroll: true }"
                    @success="sheetCrearAbierto = false"
                    v-slot="{ errors, processing }"
                    class="flex h-full flex-col"
                >
                    <SheetHeader>
                        <SheetTitle>Agregar un centro</SheetTitle>
                        <SheetDescription>
                            Consultorio, clínica o laboratorio, para vincular
                            con tus médicos.
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
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.nombre" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="tipo-crear">Tipo</Label>
                            <select
                                id="tipo-crear"
                                name="tipo"
                                :class="campoUnaLinea"
                            >
                                <option value="consultorio">Consultorio</option>
                                <option value="clinica">Clínica</option>
                                <option value="hospital">Hospital</option>
                                <option value="sanatorio">Sanatorio</option>
                                <option value="laboratorio">Laboratorio</option>
                                <option value="centro_diagnostico">
                                    Centro de diagnóstico
                                </option>
                            </select>
                            <InputError :message="errors.tipo" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="direccion-crear">Dirección</Label>
                            <input
                                id="direccion-crear"
                                name="direccion"
                                type="text"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.direccion" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="telefono-crear">Teléfono</Label>
                            <input
                                id="telefono-crear"
                                name="telefono"
                                type="tel"
                                inputmode="tel"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.telefono" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="whatsapp-crear">WhatsApp</Label>
                            <input
                                id="whatsapp-crear"
                                name="whatsapp"
                                type="tel"
                                inputmode="tel"
                                placeholder="Por ejemplo: 11 2345 6789"
                                aria-describedby="whatsapp-crear-ayuda"
                                :class="campoUnaLinea"
                            />
                            <p
                                id="whatsapp-crear-ayuda"
                                class="text-sm text-muted-foreground"
                            >
                                Con el código de área, sin el 0 ni el 15.
                            </p>
                            <InputError :message="errors.whatsapp" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="email-crear">Correo electrónico</Label>
                            <input
                                id="email-crear"
                                name="email"
                                type="email"
                                inputmode="email"
                                autocomplete="off"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.email" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="web-crear">Página web</Label>
                            <input
                                id="web-crear"
                                name="web"
                                type="text"
                                inputmode="url"
                                autocapitalize="off"
                                placeholder="Por ejemplo: www.hospital.com.ar"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.web" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="horarios-crear"
                                >Horarios de atención</Label
                            >
                            <textarea
                                id="horarios-crear"
                                name="horarios"
                                rows="3"
                                placeholder="Por ejemplo: lunes a viernes de 8 a 20"
                                :class="campoTexto"
                            ></textarea>
                            <InputError :message="errors.horarios" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="notas-crear">Notas</Label>
                            <textarea
                                id="notas-crear"
                                name="notas"
                                rows="3"
                                :class="campoTexto"
                            ></textarea>
                            <InputError :message="errors.notas" />
                        </div>

                        <div
                            v-if="medicosDisponibles.length > 0"
                            class="grid gap-2"
                        >
                            <Label>Médicos que atienden acá</Label>
                            <div class="grid gap-1 rounded-md border p-2">
                                <Label
                                    v-for="medico in medicosDisponibles"
                                    :key="medico.id"
                                    :for="`medico-crear-${medico.id}`"
                                    class="flex min-h-11 items-center space-x-3 rounded-md px-1"
                                >
                                    <Checkbox
                                        :id="`medico-crear-${medico.id}`"
                                        name="medicos[]"
                                        :value="medico.id"
                                    />
                                    <span>{{ medico.nombre }}</span>
                                </Label>
                            </div>
                            <InputError :message="errors.medicos" />
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

        <!-- Editar -->
        <Sheet
            :open="!!centroAEditar"
            @update:open="
                (v: boolean) => {
                    if (!v) centroAEditar = null;
                }
            "
        >
            <SheetContent v-if="centroAEditar">
                <Form
                    v-bind="
                        CentroController.update.form({
                            centro: centroAEditar.id,
                        })
                    "
                    :options="{ preserveScroll: true }"
                    @success="centroAEditar = null"
                    v-slot="{ errors, processing }"
                    class="flex h-full flex-col"
                >
                    <SheetHeader>
                        <SheetTitle>
                            Editar a {{ centroAEditar.nombre }}
                        </SheetTitle>
                    </SheetHeader>

                    <div class="flex-1 space-y-4 overflow-y-auto px-4">
                        <div class="grid gap-2">
                            <Label for="nombre-editar">Nombre</Label>
                            <input
                                id="nombre-editar"
                                name="nombre"
                                type="text"
                                required
                                :value="centroAEditar.nombre"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.nombre" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="tipo-editar">Tipo</Label>
                            <select
                                id="tipo-editar"
                                name="tipo"
                                :value="centroAEditar.tipo"
                                :class="campoUnaLinea"
                            >
                                <option value="consultorio">Consultorio</option>
                                <option value="clinica">Clínica</option>
                                <option value="hospital">Hospital</option>
                                <option value="sanatorio">Sanatorio</option>
                                <option value="laboratorio">Laboratorio</option>
                                <option value="centro_diagnostico">
                                    Centro de diagnóstico
                                </option>
                            </select>
                            <InputError :message="errors.tipo" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="direccion-editar">Dirección</Label>
                            <input
                                id="direccion-editar"
                                name="direccion"
                                type="text"
                                :value="centroAEditar.direccion"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.direccion" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="telefono-editar">Teléfono</Label>
                            <input
                                id="telefono-editar"
                                name="telefono"
                                type="tel"
                                inputmode="tel"
                                :value="centroAEditar.telefono"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.telefono" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="whatsapp-editar">WhatsApp</Label>
                            <input
                                id="whatsapp-editar"
                                name="whatsapp"
                                type="tel"
                                inputmode="tel"
                                placeholder="Por ejemplo: 11 2345 6789"
                                aria-describedby="whatsapp-editar-ayuda"
                                :value="centroAEditar.whatsapp"
                                :class="campoUnaLinea"
                            />
                            <p
                                id="whatsapp-editar-ayuda"
                                class="text-sm text-muted-foreground"
                            >
                                Con el código de área, sin el 0 ni el 15.
                            </p>
                            <InputError :message="errors.whatsapp" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="email-editar">Correo electrónico</Label>
                            <input
                                id="email-editar"
                                name="email"
                                type="email"
                                inputmode="email"
                                autocomplete="off"
                                :value="centroAEditar.email"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.email" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="web-editar">Página web</Label>
                            <input
                                id="web-editar"
                                name="web"
                                type="text"
                                inputmode="url"
                                autocapitalize="off"
                                placeholder="Por ejemplo: www.hospital.com.ar"
                                :value="centroAEditar.web"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.web" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="horarios-editar"
                                >Horarios de atención</Label
                            >
                            <textarea
                                id="horarios-editar"
                                name="horarios"
                                rows="3"
                                placeholder="Por ejemplo: lunes a viernes de 8 a 20"
                                :class="campoTexto"
                                >{{ centroAEditar.horarios }}</textarea>
                            <InputError :message="errors.horarios" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="notas-editar">Notas</Label>
                            <textarea
                                id="notas-editar"
                                name="notas"
                                rows="3"
                                :class="campoTexto"
                                >{{ centroAEditar.notas }}</textarea>
                            <InputError :message="errors.notas" />
                        </div>

                        <div
                            v-if="medicosDisponibles.length > 0"
                            class="grid gap-2"
                        >
                            <Label>Médicos que atienden acá</Label>
                            <div class="grid gap-1 rounded-md border p-2">
                                <Label
                                    v-for="medico in medicosDisponibles"
                                    :key="medico.id"
                                    :for="`medico-editar-${medico.id}`"
                                    class="flex min-h-11 items-center space-x-3 rounded-md px-1"
                                >
                                    <Checkbox
                                        :id="`medico-editar-${medico.id}`"
                                        name="medicos[]"
                                        :value="medico.id"
                                        :default-value="
                                            estaVinculado(
                                                centroAEditar,
                                                medico.id,
                                            )
                                        "
                                    />
                                    <span>{{ medico.nombre }}</span>
                                </Label>
                            </div>
                            <InputError :message="errors.medicos" />
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
            :open="!!centroABorrar"
            @update:open="
                (v: boolean) => {
                    if (!v) centroABorrar = null;
                }
            "
        >
            <DialogContent v-if="centroABorrar">
                <Form
                    v-bind="
                        CentroController.destroy.form({
                            centro: centroABorrar.id,
                        })
                    "
                    :options="{ preserveScroll: true }"
                    @success="centroABorrar = null"
                    v-slot="{ processing }"
                >
                    <DialogHeader class="space-y-3">
                        <DialogTitle>
                            ¿Eliminar a {{ centroABorrar.nombre }}?
                        </DialogTitle>
                        <DialogDescription>
                            Se saca de tu catálogo. Esto no se puede deshacer.
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
                            Eliminar
                        </Button>
                    </DialogFooter>
                </Form>
            </DialogContent>
        </Dialog>
    </div>
</template>
