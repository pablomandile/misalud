<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import {
    Copy,
    Cross,
    Globe,
    Mail,
    MessageCircle,
    Phone,
    Plus,
    Trash2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import FarmaciaController from '@/actions/App/Http/Controllers/FarmaciaController';
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
 * El catálogo de farmacias: dónde quedan, cómo contactarlas y cuándo atienden.
 * Del USUARIO, como los demás catálogos: la farmacia del barrio es la de toda
 * la familia. Copiado del patrón de centros, sin tipo ni médicos.
 */

type Farmacia = {
    id: number;
    nombre: string;
    direccion: string | null;
    telefono: string | null;
    whatsapp: string | null;
    /** Armado por el servidor (`Whatsapp::enlace()`), con el 549 delante. */
    whatsappEnlace: string | null;
    email: string | null;
    web: string | null;
    horarios: string | null;
    notas: string | null;
    esSemilla: boolean;
};

defineProps<{
    registros: Farmacia[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Farmacias', href: FarmaciaController.index() }],
    },
});

const campoBase =
    'flex w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';
const campoUnaLinea = `${campoBase} min-h-11`;
const campoTexto = `${campoBase} min-h-24`;

const sheetAlta = ref(false);
const farmaciaAEditar = ref<Farmacia | null>(null);
const farmaciaABorrar = ref<Farmacia | null>(null);

const sheetAbierto = computed(() => sheetAlta.value || !!farmaciaAEditar.value);

function cerrarSheet(): void {
    sheetAlta.value = false;
    farmaciaAEditar.value = null;
}

/** El número para llamar: solo dígitos y el +. */
function enlaceTelefono(telefono: string): string {
    return `tel:${telefono.replace(/[^\d+]/g, '')}`;
}
</script>

<template>
    <Head title="Farmacias" />

    <div class="space-y-6">
        <div class="flex items-center justify-between gap-4">
            <Heading
                variant="small"
                title="Farmacias"
                description="Dónde quedan, cómo contactarlas y cuándo atienden"
            />
            <Button @click="sheetAlta = true">
                <Plus />
                Agregar
            </Button>
        </div>

        <div
            v-if="registros.length === 0"
            class="rounded-lg border border-dashed p-8 text-center"
        >
            <Cross class="mx-auto size-8 text-muted-foreground" />
            <p class="mt-3 text-sm text-muted-foreground">
                Todavía no agregaste ninguna farmacia.
            </p>
        </div>

        <!-- Tarjetas apiladas y no una tabla: a 320px una tabla desborda. -->
        <div v-else class="grid gap-3 sm:grid-cols-2">
            <Card v-for="farmacia in registros" :key="farmacia.id">
                <CardContent class="space-y-3">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0 space-y-1">
                            <p class="font-medium wrap-break-word">
                                {{ farmacia.nombre }}
                            </p>
                            <p
                                v-if="farmacia.direccion"
                                class="text-sm text-muted-foreground"
                            >
                                {{ farmacia.direccion }}
                            </p>
                            <p
                                v-if="farmacia.telefono"
                                class="text-sm text-muted-foreground"
                            >
                                Tel. {{ farmacia.telefono }}
                            </p>
                            <p
                                v-if="farmacia.whatsapp"
                                class="text-sm text-muted-foreground"
                            >
                                WhatsApp {{ farmacia.whatsapp }}
                            </p>
                            <p
                                v-if="farmacia.horarios"
                                class="text-sm whitespace-pre-line text-muted-foreground"
                            >
                                {{ farmacia.horarios }}
                            </p>
                            <p
                                v-if="farmacia.notas"
                                tabindex="0"
                                aria-label="Notas"
                                class="max-h-32 overflow-y-auto rounded-md bg-muted/40 p-2 text-sm whitespace-pre-line focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                            >
                                {{ farmacia.notas }}
                            </p>
                        </div>

                        <div class="flex shrink-0 flex-wrap justify-end gap-1">
                            <Form
                                v-if="farmacia.esSemilla"
                                v-bind="
                                    FarmaciaController.duplicar.form({
                                        farmacia: farmacia.id,
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
                                    @click="farmaciaAEditar = farmacia"
                                >
                                    Editar
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon-sm"
                                    class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                    :aria-label="`Eliminar ${farmacia.nombre}`"
                                    @click="farmaciaABorrar = farmacia"
                                >
                                    <Trash2 class="size-4" />
                                </Button>
                            </template>
                        </div>
                    </div>

                    <!--
                        Llamar, escribir, mandar un correo o abrir la web, a un
                        toque: con texto al lado del ícono y a 44 px.
                    -->
                    <div
                        v-if="
                            farmacia.telefono ||
                            farmacia.whatsappEnlace ||
                            farmacia.email ||
                            farmacia.web
                        "
                        class="flex flex-wrap gap-2"
                    >
                        <Button
                            v-if="farmacia.telefono"
                            variant="outline"
                            size="sm"
                            as-child
                        >
                            <a :href="enlaceTelefono(farmacia.telefono)">
                                <Phone />
                                Llamar
                            </a>
                        </Button>
                        <Button
                            v-if="farmacia.whatsappEnlace"
                            variant="outline"
                            size="sm"
                            as-child
                        >
                            <a
                                :href="farmacia.whatsappEnlace"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                <MessageCircle />
                                WhatsApp
                            </a>
                        </Button>
                        <Button
                            v-if="farmacia.email"
                            variant="outline"
                            size="sm"
                            as-child
                        >
                            <a :href="`mailto:${farmacia.email}`">
                                <Mail />
                                Correo
                            </a>
                        </Button>
                        <Button
                            v-if="farmacia.web"
                            variant="outline"
                            size="sm"
                            as-child
                        >
                            <a
                                :href="farmacia.web"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                <Globe />
                                Web
                            </a>
                        </Button>
                    </div>
                </CardContent>
            </Card>
        </div>

        <!-- Alta y edición, en el mismo panel -->
        <Sheet
            :open="sheetAbierto"
            @update:open="
                (v: boolean) => {
                    if (!v) cerrarSheet();
                }
            "
        >
            <SheetContent v-if="sheetAbierto">
                <Form
                    :key="farmaciaAEditar?.id ?? 'nueva'"
                    v-bind="
                        farmaciaAEditar
                            ? FarmaciaController.update.form({
                                  farmacia: farmaciaAEditar.id,
                              })
                            : FarmaciaController.store.form()
                    "
                    :options="{ preserveScroll: true }"
                    @success="cerrarSheet()"
                    v-slot="{ errors, processing }"
                    class="flex h-full flex-col"
                >
                    <SheetHeader>
                        <SheetTitle>
                            {{
                                farmaciaAEditar
                                    ? 'Editar la farmacia'
                                    : 'Agregar una farmacia'
                            }}
                        </SheetTitle>
                        <SheetDescription>
                            Solo el nombre es obligatorio.
                        </SheetDescription>
                    </SheetHeader>

                    <div class="flex-1 space-y-4 overflow-y-auto px-4">
                        <div class="grid gap-2">
                            <Label for="farmacia-nombre">Nombre</Label>
                            <input
                                id="farmacia-nombre"
                                name="nombre"
                                type="text"
                                required
                                :value="farmaciaAEditar?.nombre ?? ''"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.nombre" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="farmacia-direccion">Dirección</Label>
                            <input
                                id="farmacia-direccion"
                                name="direccion"
                                type="text"
                                :value="farmaciaAEditar?.direccion ?? ''"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.direccion" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="farmacia-telefono">Teléfono</Label>
                            <input
                                id="farmacia-telefono"
                                name="telefono"
                                type="tel"
                                inputmode="tel"
                                :value="farmaciaAEditar?.telefono ?? ''"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.telefono" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="farmacia-whatsapp">WhatsApp</Label>
                            <input
                                id="farmacia-whatsapp"
                                name="whatsapp"
                                type="tel"
                                inputmode="tel"
                                placeholder="Por ejemplo: 11 2345 6789"
                                aria-describedby="farmacia-whatsapp-ayuda"
                                :value="farmaciaAEditar?.whatsapp ?? ''"
                                :class="campoUnaLinea"
                            />
                            <p
                                id="farmacia-whatsapp-ayuda"
                                class="text-sm text-muted-foreground"
                            >
                                Con el código de área, sin el 0 ni el 15.
                            </p>
                            <InputError :message="errors.whatsapp" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="farmacia-email"
                                >Correo electrónico</Label
                            >
                            <input
                                id="farmacia-email"
                                name="email"
                                type="email"
                                inputmode="email"
                                autocomplete="off"
                                :value="farmaciaAEditar?.email ?? ''"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.email" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="farmacia-web">Página web</Label>
                            <input
                                id="farmacia-web"
                                name="web"
                                type="text"
                                inputmode="url"
                                autocapitalize="off"
                                placeholder="Por ejemplo: www.farmacia.com.ar"
                                :value="farmaciaAEditar?.web ?? ''"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.web" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="farmacia-horarios">
                                Horarios de atención
                            </Label>
                            <textarea
                                id="farmacia-horarios"
                                name="horarios"
                                rows="3"
                                placeholder="Por ejemplo: lunes a sábado de 8 a 21"
                                :class="campoTexto"
                                >{{
                                    farmaciaAEditar?.horarios ?? ''
                                }}</textarea>
                            <InputError :message="errors.horarios" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="farmacia-notas">Notas</Label>
                            <textarea
                                id="farmacia-notas"
                                name="notas"
                                rows="3"
                                :class="campoTexto"
                                >{{ farmaciaAEditar?.notas ?? '' }}</textarea>
                            <InputError :message="errors.notas" />
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
            :open="!!farmaciaABorrar"
            @update:open="
                (v: boolean) => {
                    if (!v) farmaciaABorrar = null;
                }
            "
        >
            <DialogContent v-if="farmaciaABorrar">
                <Form
                    v-bind="
                        FarmaciaController.destroy.form({
                            farmacia: farmaciaABorrar.id,
                        })
                    "
                    :options="{ preserveScroll: true }"
                    @success="farmaciaABorrar = null"
                    v-slot="{ processing }"
                >
                    <DialogHeader class="space-y-3">
                        <DialogTitle>
                            ¿Eliminar {{ farmaciaABorrar.nombre }}?
                        </DialogTitle>
                        <DialogDescription>
                            Sale de tu lista de farmacias.
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
