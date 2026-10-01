<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { Inbox, Info, Plus, PlugZap, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import CuentaMailController from '@/actions/App/Http/Controllers/CuentaMailController';
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
 * La casilla de correo de la que se importan las recetas.
 *
 * Copiada del patrón de los catálogos (ver `catalogos/Medicos.vue`): mismo
 * listado en tarjetas, mismo sheet para alta y edición, mismo dialog para
 * borrar. Lo único propio es el botón de probar la conexión.
 *
 * ⚠️ La CONTRASEÑA no viaja en los props -ver `CuentaMailController`-, así que
 * el formulario de edición abre con ese campo vacío y eso significa "dejá la
 * que está". El cartel al lado del campo lo dice, porque un campo de
 * contraseña vacío en una pantalla de edición se lee como "se perdió".
 */

type Casilla = {
    id: number;
    host: string;
    puerto: number;
    direccion: string;
    carpeta: string;
    filtros: string[];
};

defineProps<{ cuentas: Casilla[] }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Casilla de recetas', href: CuentaMailController.index() },
        ],
    },
});

const campoBase =
    'flex w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';
const campoUnaLinea = `${campoBase} min-h-11`;
const campoTexto = `${campoBase} min-h-24`;

const sheetCrearAbierto = ref(false);
const casillaAEditar = ref<Casilla | null>(null);
const casillaABorrar = ref<Casilla | null>(null);
</script>

<template>
    <Head title="Casilla de recetas" />

    <div class="space-y-6">
        <div class="flex items-center justify-between gap-4">
            <Heading
                variant="small"
                title="Casilla de recetas"
                description="De acá se importan las recetas que te llegan por mail"
            />
            <Button @click="sheetCrearAbierto = true">
                <Plus />
                Agregar
            </Button>
        </div>

        <!--
            Va arriba y siempre visible, no escondido en un "¿ayuda?": es el
            dato sin el cual la configuración falla, y falla con un mensaje
            que parece decir que la contraseña está mal escrita.
        -->
        <Card>
            <CardContent class="flex gap-3">
                <Info class="mt-0.5 size-5 shrink-0 text-muted-foreground" />
                <div class="space-y-2 text-sm">
                    <p>
                        MiSalud se conecta a tu casilla, busca los mails con
                        recetas y se guarda los archivos. Solo lee: no manda
                        mails ni borra nada.
                    </p>
                    <p>
                        <strong>Si usás Gmail</strong>, necesitás una
                        «contraseña de aplicación», que se genera en la
                        configuración de tu cuenta de Google y requiere tener
                        activada la verificación en dos pasos. La contraseña con
                        la que entrás a Gmail no sirve para esto.
                    </p>
                </div>
            </CardContent>
        </Card>

        <div
            v-if="cuentas.length === 0"
            class="rounded-lg border border-dashed p-8 text-center"
        >
            <Inbox class="mx-auto size-8 text-muted-foreground" />
            <p class="mt-3 text-sm text-muted-foreground">
                Todavía no configuraste ninguna casilla.
            </p>
        </div>

        <div v-else class="space-y-3">
            <Card v-for="casilla in cuentas" :key="casilla.id">
                <CardContent class="space-y-4">
                    <div class="space-y-1">
                        <p class="font-medium break-all">
                            {{ casilla.direccion }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{ casilla.host }}, puerto {{ casilla.puerto }} ·
                            carpeta {{ casilla.carpeta }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            <template v-if="casilla.filtros.length === 0">
                                Se mira todo lo que haya en esa carpeta.
                            </template>
                            <template v-else>
                                Solo de:
                                {{ casilla.filtros.join(', ') }}
                            </template>
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <Form
                            v-bind="
                                CuentaMailController.probar.form({
                                    cuenta: casilla.id,
                                })
                            "
                            :options="{ preserveScroll: true }"
                            v-slot="{ processing }"
                        >
                            <!--
                                La prueba abre una conexión de verdad y puede
                                tardar unos segundos: sin el cambio de texto,
                                parece que el botón no hizo nada y se lo toca
                                de nuevo.
                            -->
                            <Button
                                type="submit"
                                variant="secondary"
                                :disabled="processing"
                            >
                                <PlugZap />
                                {{
                                    processing ? 'Probando…' : 'Probar conexión'
                                }}
                            </Button>
                        </Form>

                        <Button
                            variant="ghost"
                            @click="casillaAEditar = casilla"
                        >
                            Editar
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                            :aria-label="`Borrar la casilla ${casilla.direccion}`"
                            @click="casillaABorrar = casilla"
                        >
                            <Trash2 class="size-4" />
                        </Button>
                    </div>
                </CardContent>
            </Card>
        </div>

        <!-- Crear -->
        <Sheet v-model:open="sheetCrearAbierto">
            <SheetContent>
                <Form
                    v-bind="CuentaMailController.store.form()"
                    reset-on-success
                    :options="{ preserveScroll: true }"
                    @success="sheetCrearAbierto = false"
                    v-slot="{ errors, processing }"
                    class="flex h-full flex-col"
                >
                    <SheetHeader>
                        <SheetTitle>Agregar una casilla</SheetTitle>
                        <SheetDescription>
                            Después de guardarla, probá la conexión para
                            confirmar que anda.
                        </SheetDescription>
                    </SheetHeader>

                    <div class="flex-1 space-y-4 overflow-y-auto px-4">
                        <div class="grid gap-2">
                            <Label for="direccion-crear">
                                Dirección de correo
                            </Label>
                            <input
                                id="direccion-crear"
                                name="direccion"
                                type="email"
                                inputmode="email"
                                autocomplete="off"
                                required
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.direccion" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="password-crear">Contraseña</Label>
                            <!--
                                `new-password` y no `current-password`: con el
                                segundo, el navegador ofrece la contraseña con
                                la que la persona entra a MiSalud, que no es
                                la que va acá.
                            -->
                            <input
                                id="password-crear"
                                name="password"
                                type="password"
                                autocomplete="new-password"
                                required
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.password" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="host-crear">Servidor</Label>
                            <input
                                id="host-crear"
                                name="host"
                                type="text"
                                autocomplete="off"
                                required
                                value="imap.gmail.com"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.host" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="puerto-crear">Puerto</Label>
                            <select
                                id="puerto-crear"
                                name="puerto"
                                required
                                :class="campoUnaLinea"
                            >
                                <option value="993">993 — lo normal</option>
                                <option value="143">143</option>
                            </select>
                            <InputError :message="errors.puerto" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="carpeta-crear">Carpeta</Label>
                            <input
                                id="carpeta-crear"
                                name="carpeta"
                                type="text"
                                autocomplete="off"
                                required
                                value="INBOX"
                                :class="campoUnaLinea"
                            />
                            <p class="text-sm text-muted-foreground">
                                INBOX es la bandeja de entrada. Si tenés una
                                carpeta aparte para las recetas, poné su nombre
                                completo.
                            </p>
                            <InputError :message="errors.carpeta" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="filtros-crear">
                                Importar solo de (opcional)
                            </Label>
                            <textarea
                                id="filtros-crear"
                                name="filtros"
                                rows="3"
                                :class="campoTexto"
                            ></textarea>
                            <p class="text-sm text-muted-foreground">
                                Una dirección o un dominio por línea, por
                                ejemplo recetas@farmacia.com.ar o
                                farmacia.com.ar. Si lo dejás vacío, se mira todo
                                lo que haya en la carpeta.
                            </p>
                            <InputError :message="errors.filtros" />
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
            :open="!!casillaAEditar"
            @update:open="
                (v: boolean) => {
                    if (!v) casillaAEditar = null;
                }
            "
        >
            <SheetContent v-if="casillaAEditar">
                <Form
                    v-bind="
                        CuentaMailController.update.form({
                            cuenta: casillaAEditar.id,
                        })
                    "
                    :options="{ preserveScroll: true }"
                    @success="casillaAEditar = null"
                    v-slot="{ errors, processing }"
                    class="flex h-full flex-col"
                >
                    <SheetHeader>
                        <SheetTitle>Editar la casilla</SheetTitle>
                    </SheetHeader>

                    <div class="flex-1 space-y-4 overflow-y-auto px-4">
                        <div class="grid gap-2">
                            <Label for="direccion-editar">
                                Dirección de correo
                            </Label>
                            <input
                                id="direccion-editar"
                                name="direccion"
                                type="email"
                                inputmode="email"
                                autocomplete="off"
                                required
                                :value="casillaAEditar.direccion"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.direccion" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="password-editar">Contraseña</Label>
                            <input
                                id="password-editar"
                                name="password"
                                type="password"
                                autocomplete="new-password"
                                :class="campoUnaLinea"
                            />
                            <p class="text-sm text-muted-foreground">
                                Dejala vacía para seguir usando la que ya tenías
                                guardada.
                            </p>
                            <InputError :message="errors.password" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="host-editar">Servidor</Label>
                            <input
                                id="host-editar"
                                name="host"
                                type="text"
                                autocomplete="off"
                                required
                                :value="casillaAEditar.host"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.host" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="puerto-editar">Puerto</Label>
                            <select
                                id="puerto-editar"
                                name="puerto"
                                required
                                :class="campoUnaLinea"
                            >
                                <option
                                    value="993"
                                    :selected="casillaAEditar.puerto === 993"
                                >
                                    993 — lo normal
                                </option>
                                <option
                                    value="143"
                                    :selected="casillaAEditar.puerto === 143"
                                >
                                    143
                                </option>
                            </select>
                            <InputError :message="errors.puerto" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="carpeta-editar">Carpeta</Label>
                            <input
                                id="carpeta-editar"
                                name="carpeta"
                                type="text"
                                autocomplete="off"
                                required
                                :value="casillaAEditar.carpeta"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.carpeta" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="filtros-editar">
                                Importar solo de (opcional)
                            </Label>
                            <textarea
                                id="filtros-editar"
                                name="filtros"
                                rows="3"
                                :class="campoTexto"
                                >{{
                                    casillaAEditar.filtros.join('\n')
                                }}</textarea>
                            <p class="text-sm text-muted-foreground">
                                Una dirección o un dominio por línea. Vacío
                                significa que se mira todo lo que haya en la
                                carpeta.
                            </p>
                            <InputError :message="errors.filtros" />
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
            :open="!!casillaABorrar"
            @update:open="
                (v: boolean) => {
                    if (!v) casillaABorrar = null;
                }
            "
        >
            <DialogContent v-if="casillaABorrar">
                <Form
                    v-bind="
                        CuentaMailController.destroy.form({
                            cuenta: casillaABorrar.id,
                        })
                    "
                    :options="{ preserveScroll: true }"
                    @success="casillaABorrar = null"
                    v-slot="{ processing }"
                >
                    <DialogHeader class="space-y-3">
                        <DialogTitle>¿Borrar esta casilla?</DialogTitle>
                        <DialogDescription>
                            Se borra la configuración y la contraseña guardada,
                            y se deja de importar de
                            {{ casillaABorrar.direccion }}. Las recetas que ya
                            se importaron se quedan. Esto no se puede deshacer.
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
