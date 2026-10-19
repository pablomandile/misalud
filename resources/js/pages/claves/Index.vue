<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import {
    Copy,
    Eye,
    EyeOff,
    KeyRound,
    LockOpen,
    Plus,
    Trash2,
    WandSparkles,
} from '@lucide/vue';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import ClaveController from '@/actions/App/Http/Controllers/ClaveController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
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
import { generarClave } from '@/lib/generadorDeClaves';
import { copiarSecreto } from '@/lib/portapapeles';

/*
 * Las claves de los sitios de salud de cada ficha.
 *
 * ⚠️ La clave NO llega en los props: se pide de a una a `revelar`, que exige la
 * contraseña de la cuenta reconfirmada hace poco. `desbloqueado` solo decide
 * qué botón se dibuja; lo que protege es el servidor.
 */

type Clave = {
    id: number;
    sitio: string;
    usuario: string | null;
    notas: string | null;
};

type FichaConClaves = {
    id: number;
    nombre: string;
    puedeEditar: boolean;
    claves: Clave[];
};

const props = defineProps<{
    pacientes: FichaConClaves[];
    desbloqueado: boolean;
    segundosDesbloqueo: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Claves', href: ClaveController.index() }],
    },
});

const campoUnaLinea =
    'flex min-h-11 w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';
const campoTexto =
    'flex min-h-24 w-full rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';

/** Cuánto queda a la vista una clave mostrada antes de volver a taparse. */
const TAPAR_A_LOS_MS = 30_000;

/*
 * Copia local del desbloqueo: si el servidor contesta 423 (pasaron los cinco
 * minutos), la pantalla vuelve a ofrecer «Desbloquear» sin recargar.
 */
const desbloqueado = ref(props.desbloqueado);
watch(
    () => props.desbloqueado,
    (valor) => (desbloqueado.value = valor),
);

const hayAlgunaQueSePuedaVer = computed(() =>
    props.pacientes.some((p) => p.puedeEditar && p.claves.length > 0),
);

// --- Mostrar y copiar -------------------------------------------------------

const mostradas = reactive(new Map<number, string>());
const temporizadores = new Map<number, number>();

class ClaveBloqueada extends Error {}

async function pedirClave(clave: Clave): Promise<string> {
    const respuesta = await fetch(
        ClaveController.revelar.url({ clave: clave.id }),
        {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            cache: 'no-store',
        },
    );

    if (respuesta.status === 423) {
        throw new ClaveBloqueada();
    }

    if (!respuesta.ok) {
        throw new Error(String(respuesta.status));
    }

    return ((await respuesta.json()) as { clave: string }).clave;
}

function avisarFallo(error: unknown): void {
    if (error instanceof ClaveBloqueada) {
        desbloqueado.value = false;
        toast.info('Pasaron unos minutos: volvé a desbloquear para verlas.');

        return;
    }

    toast.error('No se pudo traer la clave. Probá de nuevo.', {
        duration: Infinity,
    });
}

function tapar(id: number): void {
    mostradas.delete(id);
    window.clearTimeout(temporizadores.get(id));
    temporizadores.delete(id);
}

async function alternarMostrar(clave: Clave): Promise<void> {
    if (mostradas.has(clave.id)) {
        tapar(clave.id);

        return;
    }

    try {
        mostradas.set(clave.id, await pedirClave(clave));
        temporizadores.set(
            clave.id,
            window.setTimeout(() => tapar(clave.id), TAPAR_A_LOS_MS),
        );
    } catch (error) {
        avisarFallo(error);
    }
}

function copiar(clave: Clave): void {
    const yaMostrada = mostradas.get(clave.id);
    const valor = yaMostrada ?? pedirClave(clave);

    if (valor instanceof Promise) {
        // El fallo lo avisa esto; copiarSecreto solo vería "no se pudo copiar".
        valor.catch(avisarFallo);
    }

    void copiarSecreto(valor);
}

onBeforeUnmount(() => {
    temporizadores.forEach((t) => window.clearTimeout(t));
    mostradas.clear();
});

// --- Alta y edición ---------------------------------------------------------

/*
 * Un solo sheet para agregar y editar. ⚠️ Todos los campos con `v-model`: el
 * botón «Generar» cambia estado local, y con un solo campo así, los `:value` de
 * los hermanos se pisarían con cada cambio (la regla de la Etapa 10).
 */
const fichaDelAlta = ref<FichaConClaves | null>(null);
const claveAEditar = ref<Clave | null>(null);
const sheetAbierto = computed(
    () => fichaDelAlta.value !== null || claveAEditar.value !== null,
);
const campos = reactive({ sitio: '', usuario: '', clave: '', notas: '' });
const verLoQueEscribo = ref(false);

function abrirAlta(ficha: FichaConClaves): void {
    Object.assign(campos, { sitio: '', usuario: '', clave: '', notas: '' });
    verLoQueEscribo.value = false;
    claveAEditar.value = null;
    fichaDelAlta.value = ficha;
}

function abrirEdicion(clave: Clave): void {
    Object.assign(campos, {
        sitio: clave.sitio,
        usuario: clave.usuario ?? '',
        clave: '',
        notas: clave.notas ?? '',
    });
    verLoQueEscribo.value = false;
    fichaDelAlta.value = null;
    claveAEditar.value = clave;
}

function cerrarSheet(): void {
    fichaDelAlta.value = null;
    claveAEditar.value = null;
}

function generar(): void {
    campos.clave = generarClave();
    // Recién generada, la persona tiene que poder verla para anotarla.
    verLoQueEscribo.value = true;
}

const accionDelSheet = computed(() =>
    claveAEditar.value
        ? ClaveController.update.form({ clave: claveAEditar.value.id })
        : ClaveController.store.form({ paciente: fichaDelAlta.value?.id ?? 0 }),
);

// --- Borrar -----------------------------------------------------------------

const claveABorrar = ref<Clave | null>(null);
</script>

<template>
    <Head title="Claves" />

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Claves"
            description="Usuario y contraseña de los sitios de salud de cada ficha: la obra social, el laboratorio, los turnos"
        />

        <!--
            El desbloqueo, una sola vez arriba y no un botón por clave: pide la
            contraseña de la cuenta y vale para todas por unos minutos.
        -->
        <div
            v-if="hayAlgunaQueSePuedaVer && !desbloqueado"
            class="flex flex-wrap items-center gap-3 rounded-lg border bg-card p-4"
        >
            <p class="min-w-[12rem] flex-1 text-sm">
                Para ver o copiar una clave, primero confirmá tu contraseña de
                MiSalud. Queda desbloqueado
                {{ Math.round(segundosDesbloqueo / 60) }} minutos.
            </p>
            <Button as-child>
                <Link :href="ClaveController.desbloquear()">
                    <LockOpen />
                    Desbloquear
                </Link>
            </Button>
        </div>

        <div
            v-if="pacientes.length === 0"
            class="rounded-lg border border-dashed p-8 text-center"
        >
            <KeyRound class="mx-auto size-8 text-muted-foreground" />
            <p class="mt-3 text-sm text-muted-foreground">
                Primero agregá un paciente: las claves se guardan en su ficha.
            </p>
        </div>

        <Card
            v-for="ficha in pacientes"
            :id="`paciente-${ficha.id}`"
            :key="ficha.id"
            class="scroll-mt-4 gap-0 overflow-hidden py-0"
        >
            <div
                class="flex flex-wrap items-center gap-3 bg-linear-to-br from-limon/60 to-card px-5 py-4"
            >
                <h2
                    class="min-w-[9rem] flex-1 text-lg font-semibold wrap-break-word"
                >
                    {{ ficha.nombre }}
                </h2>
                <Button
                    v-if="ficha.puedeEditar"
                    variant="outline"
                    class="bg-card/80"
                    @click="abrirAlta(ficha)"
                >
                    <Plus />
                    Agregar una clave
                </Button>
            </div>

            <p
                v-if="ficha.claves.length === 0"
                class="px-5 py-4 text-sm text-muted-foreground"
            >
                Sin datos.
            </p>

            <ul v-else class="divide-y">
                <li
                    v-for="clave in ficha.claves"
                    :key="clave.id"
                    class="space-y-3 px-5 py-4"
                >
                    <div class="min-w-0 space-y-1">
                        <p class="font-medium wrap-break-word">
                            {{ clave.sitio }}
                        </p>
                        <p
                            v-if="clave.usuario"
                            class="text-sm break-all text-muted-foreground"
                        >
                            Usuario: {{ clave.usuario }}
                        </p>
                        <p
                            v-if="mostradas.has(clave.id)"
                            class="rounded-md bg-muted px-3 py-2 font-mono text-base break-all"
                            aria-live="polite"
                        >
                            {{ mostradas.get(clave.id) }}
                        </p>
                        <p
                            v-if="clave.notas"
                            class="text-sm wrap-break-word whitespace-pre-line"
                        >
                            {{ clave.notas }}
                        </p>
                    </div>

                    <div v-if="ficha.puedeEditar" class="flex flex-wrap gap-2">
                        <template v-if="desbloqueado">
                            <Button
                                variant="outline"
                                @click="alternarMostrar(clave)"
                            >
                                <EyeOff v-if="mostradas.has(clave.id)" />
                                <Eye v-else />
                                {{
                                    mostradas.has(clave.id)
                                        ? 'Ocultar'
                                        : 'Mostrar'
                                }}
                            </Button>
                            <Button variant="outline" @click="copiar(clave)">
                                <Copy />
                                Copiar
                            </Button>
                        </template>
                        <Button variant="ghost" @click="abrirEdicion(clave)">
                            Editar
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                            :aria-label="`Borrar la clave de ${clave.sitio}`"
                            @click="claveABorrar = clave"
                        >
                            <Trash2 class="size-4" />
                        </Button>
                    </div>
                </li>
            </ul>
        </Card>

        <!-- Alta y edición -->
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
                    v-bind="accionDelSheet"
                    :options="{ preserveScroll: true }"
                    @success="cerrarSheet()"
                    v-slot="{ errors, processing }"
                    class="flex h-full flex-col"
                >
                    <SheetHeader>
                        <SheetTitle>
                            {{
                                claveAEditar
                                    ? `Editar la clave de ${claveAEditar.sitio}`
                                    : `Agregar una clave de ${fichaDelAlta?.nombre}`
                            }}
                        </SheetTitle>
                        <SheetDescription>
                            Se guarda cifrada. Para volver a verla se pide tu
                            contraseña de MiSalud.
                        </SheetDescription>
                    </SheetHeader>

                    <div class="flex-1 space-y-4 overflow-y-auto px-4">
                        <div class="grid gap-2">
                            <Label for="clave-sitio">Sitio</Label>
                            <input
                                id="clave-sitio"
                                v-model="campos.sitio"
                                name="sitio"
                                type="text"
                                required
                                autocomplete="off"
                                placeholder="Por ejemplo: portal de la obra social"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.sitio" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="clave-usuario">Usuario</Label>
                            <input
                                id="clave-usuario"
                                v-model="campos.usuario"
                                name="usuario"
                                type="text"
                                autocomplete="off"
                                autocapitalize="off"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.usuario" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="clave-clave">Clave</Label>
                            <!--
                                new-password: que el navegador no ofrezca
                                completar con la contraseña de MiSalud.
                            -->
                            <input
                                id="clave-clave"
                                v-model="campos.clave"
                                name="clave"
                                :type="verLoQueEscribo ? 'text' : 'password'"
                                :required="!claveAEditar"
                                autocomplete="new-password"
                                autocapitalize="off"
                                spellcheck="false"
                                :class="`${campoUnaLinea} font-mono`"
                            />
                            <p
                                v-if="claveAEditar"
                                class="text-sm text-muted-foreground"
                            >
                                Dejala vacía para no cambiarla.
                            </p>
                            <div class="flex flex-wrap gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    @click="verLoQueEscribo = !verLoQueEscribo"
                                >
                                    <EyeOff v-if="verLoQueEscribo" />
                                    <Eye v-else />
                                    {{
                                        verLoQueEscribo
                                            ? 'Ocultar lo que escribo'
                                            : 'Ver lo que escribo'
                                    }}
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    @click="generar()"
                                >
                                    <WandSparkles />
                                    Generar una clave
                                </Button>
                            </div>
                            <InputError :message="errors.clave" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="clave-notas">Notas</Label>
                            <textarea
                                id="clave-notas"
                                v-model="campos.notas"
                                name="notas"
                                rows="3"
                                :class="campoTexto"
                            ></textarea>
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
            :open="!!claveABorrar"
            @update:open="
                (v: boolean) => {
                    if (!v) claveABorrar = null;
                }
            "
        >
            <DialogContent v-if="claveABorrar">
                <Form
                    v-bind="
                        ClaveController.destroy.form({
                            clave: claveABorrar.id,
                        })
                    "
                    :options="{ preserveScroll: true }"
                    @success="
                        () => {
                            if (claveABorrar) tapar(claveABorrar.id);
                            claveABorrar = null;
                        }
                    "
                    v-slot="{ processing }"
                >
                    <DialogHeader class="space-y-3">
                        <DialogTitle>
                            ¿Borrar la clave de {{ claveABorrar.sitio }}?
                        </DialogTitle>
                        <DialogDescription>
                            Se borra de verdad y no se puede recuperar.
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
