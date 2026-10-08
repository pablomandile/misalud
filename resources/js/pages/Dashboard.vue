<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import {
    Activity,
    CalendarClock,
    ClipboardList,
    CreditCard,
    FileText,
    Pill,
    Syringe,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import AplicacionVacunaController from '@/actions/App/Http/Controllers/AplicacionVacunaController';
import MedicionController from '@/actions/App/Http/Controllers/MedicionController';
import OrdenEstudioController from '@/actions/App/Http/Controllers/OrdenEstudioController';
import PacienteActivoController from '@/actions/App/Http/Controllers/PacienteActivoController';
import PacienteController from '@/actions/App/Http/Controllers/PacienteController';
import RecetaController from '@/actions/App/Http/Controllers/RecetaController';
import TratamientoController from '@/actions/App/Http/Controllers/TratamientoController';
import TurnoController from '@/actions/App/Http/Controllers/TurnoController';
import TarjetaPanel from '@/components/TarjetaPanel.vue';
import type { DocumentoVisible } from '@/components/VisorDocumento.vue';
import VisorDocumento from '@/components/VisorDocumento.vue';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';

/*
 * Panel principal: lo que hace falta tener a mano de una ficha.
 *
 * Las tarjetas van en el orden de la urgencia con la que se buscan —la
 * credencial se muestra en un mostrador con alguien esperando— y cada una trae
 * pocas filas y el total: el panel orienta y lleva a la pantalla de cada cosa,
 * no la reemplaza. Sin datos se dice "sin datos" (regla 2), y ninguna tarjeta
 * pinta un valor de color ni opina sobre él (regla 1): el color de cada
 * tarjeta es el de su SECCIÓN, igual que en el menú.
 */

type Credencial = DocumentoVisible & {
    id: number;
    entidad: string;
};

type Tarjeta<T> = { total: number; filas: T[] };

defineProps<{
    pacientes: Array<{ id: number; nombre: string }>;
    pacienteActivo: { id: number; nombre: string } | null;
    credenciales: Credencial[];
    proximosTurnos: Tarjeta<{
        id: number;
        fechaVisible: string;
        motivo: string | null;
        donde: string | null;
    }> | null;
    tratamientosActivos: Array<{
        id: number;
        medicamento: string;
        dosis: string;
        frecuencia: string;
    }>;
    ordenesPendientes: Tarjeta<{
        id: number;
        estudio: string;
        fechaVisible: string;
    }> | null;
    proximasVacunas: Array<{
        id: number;
        vacuna: string;
        fechaVisible: string;
    }>;
    ultimasMediciones: Array<{
        id: number;
        tipo: string;
        valor: string;
        unidad: string | null;
        fechaVisible: string;
    }>;
    recetas: Tarjeta<{
        id: number;
        asunto: string | null;
        venceVisible: string;
    }> | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Panel', href: dashboard() }],
    },
});

const pagina = usePage();

// El saludo usa solo el primer nombre: "Hola, Pablo", no el nombre completo.
const nombre = computed(
    () => pagina.props.auth.user?.name?.trim().split(/\s+/)[0] ?? '',
);

/*
 * Mismo patrón que pacientes/Index.vue: UN SOLO documento abierto para
 * toda la pantalla, y un solo <VisorDocumento> al final del template.
 */
const documentoAbierto = ref<DocumentoVisible | null>(null);

function masDe(total: number, mostradas: number): string | null {
    return total > mostradas ? `y ${total - mostradas} más` : null;
}
</script>

<template>
    <Head title="Panel" />

    <div class="space-y-6">
        <!--
            El saludo: un banner con las formas de la marca. Lleva el nombre de
            la ficha que se está mirando, que es lo que hay que confirmar antes
            de leer cualquier tarjeta, y el selector de ficha adentro.
        -->
        <section
            class="relative isolate overflow-hidden rounded-2xl border border-lavanda-fuerte/15 bg-linear-to-br from-lavanda via-celeste to-menta p-5 shadow-sm sm:p-7"
        >
            <svg
                aria-hidden="true"
                class="absolute -top-10 -right-10 -z-10 size-48 text-card opacity-50"
                viewBox="0 0 100 100"
            >
                <circle cx="50" cy="50" r="50" fill="currentColor" />
            </svg>
            <svg
                aria-hidden="true"
                class="absolute -right-4 -bottom-16 -z-10 size-40 text-rosa opacity-70"
                viewBox="0 0 100 100"
            >
                <circle cx="50" cy="50" r="50" fill="currentColor" />
            </svg>
            <svg
                aria-hidden="true"
                class="absolute top-6 right-40 -z-10 hidden size-16 text-durazno sm:block"
                viewBox="0 0 100 100"
            >
                <circle cx="50" cy="50" r="50" fill="currentColor" />
            </svg>

            <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">
                {{ nombre ? `Hola, ${nombre}` : 'Hola' }}
            </h1>
            <p v-if="pacienteActivo" class="mt-1 text-foreground/80">
                Estás viendo la ficha de
                <strong class="font-semibold">{{
                    pacienteActivo.nombre
                }}</strong>
            </p>

            <!--
                Con más de una ficha, elegir cuál mirar es lo primero. Botones y
                no un desplegable: se ven todas las opciones de un vistazo, y
                tocar una alcanza (un <select> pide abrir, elegir y además
                confirmar).
            -->
            <div
                v-if="pacientes.length > 1"
                class="mt-4 space-y-2"
                role="group"
                aria-label="Elegí qué ficha ver"
            >
                <p class="text-sm text-foreground/80">Ver la ficha de</p>
                <div class="flex flex-wrap gap-2">
                    <Form
                        v-for="paciente in pacientes"
                        :key="paciente.id"
                        v-bind="
                            PacienteActivoController.update.form(paciente.id)
                        "
                        :options="{ preserveScroll: true }"
                        v-slot="{ processing }"
                    >
                        <Button
                            type="submit"
                            class="rounded-full"
                            :class="
                                paciente.id === pacienteActivo?.id
                                    ? ''
                                    : 'bg-card/80 hover:bg-card'
                            "
                            :variant="
                                paciente.id === pacienteActivo?.id
                                    ? 'default'
                                    : 'outline'
                            "
                            :aria-pressed="paciente.id === pacienteActivo?.id"
                            :disabled="processing"
                        >
                            {{ paciente.nombre }}
                        </Button>
                    </Form>
                </div>
            </div>
        </section>

        <!-- Sin paciente: no hay a quién mostrarle nada. -->
        <div
            v-if="!pacienteActivo"
            class="rounded-2xl border border-dashed bg-card/70 p-8 text-center"
        >
            <p class="text-muted-foreground">
                Todavía no hay ningún paciente cargado.
            </p>
            <Button as-child class="mt-4">
                <Link :href="PacienteController.index()">Ir a Pacientes</Link>
            </Button>
        </div>

        <div v-else class="grid gap-4 lg:grid-cols-2">
            <!-- Credencial: lo que se muestra en un mostrador. -->
            <TarjetaPanel :icon="CreditCard" tono="celeste" titulo="Credencial">
                <p
                    v-if="credenciales.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    Sin datos. Todavía no cargaste ninguna credencial en
                    Cobertura médica.
                </p>

                <div v-else class="grid gap-2 sm:grid-cols-2">
                    <button
                        v-for="credencial in credenciales"
                        :key="credencial.id"
                        type="button"
                        class="flex min-h-11 items-center gap-3 rounded-xl border bg-card/80 p-3 text-left hover:bg-accent"
                        @click="documentoAbierto = credencial"
                    >
                        <CreditCard
                            class="size-5 shrink-0 text-celeste-fuerte"
                        />
                        <span class="min-w-0">
                            <span class="block truncate font-medium">
                                {{ credencial.entidad }}
                            </span>
                            <span
                                class="block truncate text-sm text-muted-foreground"
                            >
                                {{ credencial.nombre }}
                            </span>
                        </span>
                    </button>
                </div>
            </TarjetaPanel>

            <!-- Próximos turnos -->
            <TarjetaPanel
                v-if="proximosTurnos"
                :icon="CalendarClock"
                tono="lavanda"
                titulo="Próximos turnos"
            >
                <p
                    v-if="proximosTurnos.total === 0"
                    class="text-sm text-muted-foreground"
                >
                    Sin datos. No hay turnos por delante.
                </p>

                <ul v-else class="divide-y">
                    <li
                        v-for="turno in proximosTurnos.filas"
                        :key="turno.id"
                        class="py-2"
                    >
                        <p class="font-medium">{{ turno.fechaVisible }}</p>
                        <p
                            v-if="turno.motivo || turno.donde"
                            class="text-sm text-muted-foreground"
                        >
                            {{
                                [turno.motivo, turno.donde]
                                    .filter(Boolean)
                                    .join(' · ')
                            }}
                        </p>
                    </li>
                </ul>
                <p
                    v-if="
                        masDe(proximosTurnos.total, proximosTurnos.filas.length)
                    "
                    class="text-sm text-muted-foreground"
                >
                    {{
                        masDe(proximosTurnos.total, proximosTurnos.filas.length)
                    }}
                </p>

                <template #accion>
                    <Button variant="outline" size="sm" as-child>
                        <Link
                            :href="
                                TurnoController.index({
                                    paciente: pacienteActivo.id,
                                })
                            "
                        >
                            Ver la agenda
                        </Link>
                    </Button>
                </template>
            </TarjetaPanel>

            <!-- Recetas: de la casilla, no de la ficha. -->
            <TarjetaPanel
                v-if="recetas"
                :icon="FileText"
                tono="durazno"
                titulo="Recetas sin usar"
            >
                <p class="text-sm text-muted-foreground">
                    Las que llegaron a tu casilla, de cualquier ficha.
                </p>

                <p
                    v-if="recetas.total === 0"
                    class="text-sm text-muted-foreground"
                >
                    Sin datos. No hay recetas sin usar.
                </p>

                <ul v-else class="divide-y">
                    <li
                        v-for="receta in recetas.filas"
                        :key="receta.id"
                        class="py-2"
                    >
                        <p class="font-medium wrap-break-word">
                            {{ receta.asunto || 'Receta sin asunto' }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            Vence el {{ receta.venceVisible }}
                        </p>
                    </li>
                </ul>
                <p
                    v-if="masDe(recetas.total, recetas.filas.length)"
                    class="text-sm text-muted-foreground"
                >
                    {{ masDe(recetas.total, recetas.filas.length) }}
                </p>

                <template #accion>
                    <Button variant="outline" size="sm" as-child>
                        <Link :href="RecetaController.index()">
                            Ver las recetas
                        </Link>
                    </Button>
                </template>
            </TarjetaPanel>

            <!-- Tratamientos activos -->
            <TarjetaPanel
                :icon="Pill"
                tono="rosa"
                titulo="Tratamientos activos"
            >
                <p
                    v-if="tratamientosActivos.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    Sin datos. No hay tratamientos activos cargados.
                </p>

                <ul v-else class="divide-y">
                    <li
                        v-for="tratamiento in tratamientosActivos"
                        :key="tratamiento.id"
                        class="py-2"
                    >
                        <p class="font-medium">
                            {{ tratamiento.medicamento }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{ tratamiento.dosis }} ·
                            {{ tratamiento.frecuencia }}
                        </p>
                    </li>
                </ul>

                <template #accion>
                    <Button variant="outline" size="sm" as-child>
                        <Link
                            :href="
                                TratamientoController.index({
                                    paciente: pacienteActivo.id,
                                })
                            "
                        >
                            Ver todos
                        </Link>
                    </Button>
                </template>
            </TarjetaPanel>

            <!-- Órdenes pendientes -->
            <TarjetaPanel
                v-if="ordenesPendientes"
                :icon="ClipboardList"
                tono="turquesa"
                titulo="Estudios por hacer"
            >
                <p
                    v-if="ordenesPendientes.total === 0"
                    class="text-sm text-muted-foreground"
                >
                    Sin datos. No hay órdenes pendientes.
                </p>

                <ul v-else class="divide-y">
                    <li
                        v-for="orden in ordenesPendientes.filas"
                        :key="orden.id"
                        class="py-2"
                    >
                        <p class="font-medium wrap-break-word">
                            {{ orden.estudio }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            Orden del {{ orden.fechaVisible }}
                        </p>
                    </li>
                </ul>
                <p
                    v-if="
                        masDe(
                            ordenesPendientes.total,
                            ordenesPendientes.filas.length,
                        )
                    "
                    class="text-sm text-muted-foreground"
                >
                    {{
                        masDe(
                            ordenesPendientes.total,
                            ordenesPendientes.filas.length,
                        )
                    }}
                </p>

                <template #accion>
                    <Button variant="outline" size="sm" as-child>
                        <Link
                            :href="
                                OrdenEstudioController.index({
                                    paciente: pacienteActivo.id,
                                })
                            "
                        >
                            Ver las órdenes
                        </Link>
                    </Button>
                </template>
            </TarjetaPanel>

            <!-- Próximas dosis -->
            <TarjetaPanel
                :icon="Syringe"
                tono="limon"
                titulo="Próximas vacunas"
            >
                <p
                    v-if="proximasVacunas.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    Sin datos. No hay próximas dosis anotadas.
                </p>

                <ul v-else class="divide-y">
                    <li
                        v-for="vacuna in proximasVacunas"
                        :key="vacuna.id"
                        class="py-2"
                    >
                        <p class="font-medium">{{ vacuna.vacuna }}</p>
                        <p class="text-sm text-muted-foreground">
                            El {{ vacuna.fechaVisible }}
                        </p>
                    </li>
                </ul>

                <template #accion>
                    <Button variant="outline" size="sm" as-child>
                        <Link
                            :href="
                                AplicacionVacunaController.index({
                                    paciente: pacienteActivo.id,
                                })
                            "
                        >
                            Ver el carnet
                        </Link>
                    </Button>
                </template>
            </TarjetaPanel>

            <!-- Últimas mediciones: el valor y su fecha, sin juicio. -->
            <TarjetaPanel
                :icon="Activity"
                tono="menta"
                titulo="Últimas mediciones"
            >
                <p
                    v-if="ultimasMediciones.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    Sin datos. Todavía no hay mediciones cargadas.
                </p>

                <ul v-else class="divide-y">
                    <li
                        v-for="medicion in ultimasMediciones"
                        :key="medicion.id"
                        class="flex flex-wrap items-baseline justify-between gap-x-3 py-2"
                    >
                        <span class="font-medium">{{ medicion.tipo }}</span>
                        <span>
                            {{ medicion.valor }}
                            <span
                                v-if="medicion.unidad"
                                class="text-sm text-muted-foreground"
                                >{{ medicion.unidad }}</span
                            >
                        </span>
                        <span class="w-full text-sm text-muted-foreground">
                            {{ medicion.fechaVisible }}
                        </span>
                    </li>
                </ul>

                <template #accion>
                    <Button variant="outline" size="sm" as-child>
                        <Link
                            :href="
                                MedicionController.index({
                                    paciente: pacienteActivo.id,
                                })
                            "
                        >
                            Ver la evolución
                        </Link>
                    </Button>
                </template>
            </TarjetaPanel>
        </div>

        <!-- UNO SOLO para toda la pantalla, fuera de todo v-for. -->
        <VisorDocumento
            :documento="documentoAbierto"
            @cerrar="documentoAbierto = null"
        />
    </div>
</template>
