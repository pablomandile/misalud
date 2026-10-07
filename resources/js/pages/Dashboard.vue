<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import {
    Activity,
    CalendarClock,
    ClipboardList,
    CreditCard,
    FileText,
    Pill,
    Syringe,
} from '@lucide/vue';
import { ref } from 'vue';
import AplicacionVacunaController from '@/actions/App/Http/Controllers/AplicacionVacunaController';
import MedicionController from '@/actions/App/Http/Controllers/MedicionController';
import OrdenEstudioController from '@/actions/App/Http/Controllers/OrdenEstudioController';
import PacienteActivoController from '@/actions/App/Http/Controllers/PacienteActivoController';
import PacienteController from '@/actions/App/Http/Controllers/PacienteController';
import RecetaController from '@/actions/App/Http/Controllers/RecetaController';
import TratamientoController from '@/actions/App/Http/Controllers/TratamientoController';
import TurnoController from '@/actions/App/Http/Controllers/TurnoController';
import Heading from '@/components/Heading.vue';
import type { DocumentoVisible } from '@/components/VisorDocumento.vue';
import VisorDocumento from '@/components/VisorDocumento.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { dashboard } from '@/routes';

/*
 * Panel principal: lo que hace falta tener a mano de una ficha.
 *
 * Las tarjetas van en el orden de la urgencia con la que se buscan —la
 * credencial se muestra en un mostrador con alguien esperando— y cada una trae
 * pocas filas y el total: el panel orienta y lleva a la pantalla de cada cosa,
 * no la reemplaza. Sin datos se dice "sin datos" (regla 2), y ninguna tarjeta
 * pinta un valor de color ni opina sobre él (regla 1).
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
        <Heading
            variant="small"
            title="Panel"
            :description="
                pacienteActivo
                    ? `Mostrando la ficha de ${pacienteActivo.nombre}`
                    : undefined
            "
        />

        <!--
            Con más de una ficha, elegir cuál mirar es lo primero. Botones y no un
            desplegable: se ven todas las opciones de un vistazo, y tocar una
            alcanza (un <select> pide abrir, elegir y además confirmar).
        -->
        <div
            v-if="pacientes.length > 1"
            class="space-y-2"
            role="group"
            aria-label="Elegí qué ficha ver"
        >
            <p class="text-sm text-muted-foreground">Ver la ficha de</p>
            <div class="flex flex-wrap gap-2">
                <Form
                    v-for="paciente in pacientes"
                    :key="paciente.id"
                    v-bind="PacienteActivoController.update.form(paciente.id)"
                    :options="{ preserveScroll: true }"
                    v-slot="{ processing }"
                >
                    <Button
                        type="submit"
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

        <!-- Sin paciente: no hay a quién mostrarle nada. -->
        <div
            v-if="!pacienteActivo"
            class="rounded-lg border border-dashed p-8 text-center"
        >
            <p class="text-sm text-muted-foreground">
                Todavía no hay ningún paciente cargado.
            </p>
            <Button as-child class="mt-4">
                <Link :href="PacienteController.index()">Ir a Pacientes</Link>
            </Button>
        </div>

        <div v-else class="grid gap-4 lg:grid-cols-2">
            <!-- Credencial: lo que se muestra en un mostrador. -->
            <Card>
                <CardContent class="space-y-3">
                    <div class="flex items-center gap-2">
                        <CreditCard class="size-5 text-muted-foreground" />
                        <h2 class="font-medium">Credencial</h2>
                    </div>

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
                            class="flex min-h-11 items-center gap-3 rounded-md border p-3 text-left hover:bg-accent"
                            @click="documentoAbierto = credencial"
                        >
                            <CreditCard
                                class="size-5 shrink-0 text-muted-foreground"
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
                </CardContent>
            </Card>

            <!-- Próximos turnos -->
            <Card v-if="proximosTurnos">
                <CardContent class="space-y-3">
                    <div class="flex items-center gap-2">
                        <CalendarClock class="size-5 text-muted-foreground" />
                        <h2 class="font-medium">Próximos turnos</h2>
                    </div>

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
                            masDe(
                                proximosTurnos.total,
                                proximosTurnos.filas.length,
                            )
                        "
                        class="text-sm text-muted-foreground"
                    >
                        {{
                            masDe(
                                proximosTurnos.total,
                                proximosTurnos.filas.length,
                            )
                        }}
                    </p>

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
                </CardContent>
            </Card>

            <!-- Recetas: de la casilla, no de la ficha. -->
            <Card v-if="recetas">
                <CardContent class="space-y-3">
                    <div class="flex items-center gap-2">
                        <FileText class="size-5 text-muted-foreground" />
                        <h2 class="font-medium">Recetas sin usar</h2>
                    </div>
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

                    <Button variant="outline" size="sm" as-child>
                        <Link :href="RecetaController.index()">
                            Ver las recetas
                        </Link>
                    </Button>
                </CardContent>
            </Card>

            <!-- Tratamientos activos -->
            <Card>
                <CardContent class="space-y-3">
                    <div class="flex items-center gap-2">
                        <Pill class="size-5 text-muted-foreground" />
                        <h2 class="font-medium">Tratamientos activos</h2>
                    </div>

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
                </CardContent>
            </Card>

            <!-- Órdenes pendientes -->
            <Card v-if="ordenesPendientes">
                <CardContent class="space-y-3">
                    <div class="flex items-center gap-2">
                        <ClipboardList class="size-5 text-muted-foreground" />
                        <h2 class="font-medium">Estudios por hacer</h2>
                    </div>

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
                </CardContent>
            </Card>

            <!-- Próximas dosis -->
            <Card>
                <CardContent class="space-y-3">
                    <div class="flex items-center gap-2">
                        <Syringe class="size-5 text-muted-foreground" />
                        <h2 class="font-medium">Próximas vacunas</h2>
                    </div>

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
                </CardContent>
            </Card>

            <!-- Últimas mediciones: el valor y su fecha, sin juicio. -->
            <Card>
                <CardContent class="space-y-3">
                    <div class="flex items-center gap-2">
                        <Activity class="size-5 text-muted-foreground" />
                        <h2 class="font-medium">Últimas mediciones</h2>
                    </div>

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
                </CardContent>
            </Card>
        </div>

        <!-- UNO SOLO para toda la pantalla, fuera de todo v-for. -->
        <VisorDocumento
            :documento="documentoAbierto"
            @cerrar="documentoAbierto = null"
        />
    </div>
</template>
