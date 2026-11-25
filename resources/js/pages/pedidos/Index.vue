<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ListChecks, Mail, Pill } from '@lucide/vue';
import PedidoMedicamentosController from '@/actions/App/Http/Controllers/PedidoMedicamentosController';
import TratamientoController from '@/actions/App/Http/Controllers/TratamientoController';
import Heading from '@/components/Heading.vue';
import SelectorDeFicha from '@/components/SelectorDeFicha.vue';
import { Button } from '@/components/ui/button';
import type { VariablesDelPaciente } from '@/lib/plantillaDePedido';
import SolapaMensajes from './SolapaMensajes.vue';
import SolapaProcedimiento from './SolapaProcedimiento.vue';
import type {
    DestinatarioOpcion,
    MedicamentoDelPedido,
    Procedimiento,
} from './tipos';

/*
 * Pedido de medicamentos, en dos solapas que no se tocan entre sí:
 *
 * - «Procedimiento», la primera y la de por defecto: las etapas que hay que
 *   cumplir cada vez que se pide un medicamento, y el seguimiento de cada pedido.
 * - «Mensajes»: el mail que le pide los medicamentos al médico, un centro o la
 *   farmacia, con un mensaje por medicamento.
 *
 * La solapa viaja en la URL (`?solapa=`), así una recarga vuelve a la misma.
 * «Mensajes» se oculta con `v-show` y no se desmonta: el pedido a medio armar
 * sigue ahí al volver de la otra solapa.
 */

const props = defineProps<{
    solapa: 'mensajes' | 'procedimiento';
    paciente: {
        id: number;
        nombre: string;
        puedeEditar: boolean;
        variables: VariablesDelPaciente;
    } | null;
    plantillaGenerica: string;
    plantillaOriginal: string;
    fichas: { id: number; nombre: string }[];
    medicamentos: MedicamentoDelPedido[];
    destinatarios: DestinatarioOpcion[];
    medicamentoElegido: number | null;
    procedimiento: Procedimiento | null;
    hoy: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Pedido de medicamentos', href: '' }],
    },
});

const enlaceFicha = (id: number) =>
    PedidoMedicamentosController.index({
        query: { paciente: id, solapa: props.solapa },
    });

const enlaceSolapa = (solapa: 'mensajes' | 'procedimiento') =>
    PedidoMedicamentosController.index({
        query: { paciente: props.paciente?.id, solapa },
    });

const solapas = [
    { valor: 'procedimiento', titulo: 'Procedimiento', icono: ListChecks },
    { valor: 'mensajes', titulo: 'Mensajes', icono: Mail },
] as const;

const clase = (activa: boolean) =>
    activa
        ? 'border-primary bg-primary text-primary-foreground'
        : 'bg-background hover:bg-accent';
</script>

<template>
    <Head title="Pedido de medicamentos" />

    <div class="space-y-6">
        <Heading
            variant="small"
            :title="
                paciente
                    ? `Pedido de medicamentos de ${paciente.nombre}`
                    : 'Pedido de medicamentos'
            "
            description="Pedile por mail los medicamentos y llevá el paso a paso de cada pedido"
        />

        <SelectorDeFicha
            v-if="paciente"
            :fichas="fichas"
            :actual="paciente.id"
            :enlace="enlaceFicha"
        />

        <p
            v-if="!paciente"
            class="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground"
        >
            Todavía no tenés ninguna ficha.
        </p>

        <p
            v-else-if="!paciente.puedeEditar"
            class="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground"
        >
            Solo quien puede cargar datos en esta ficha arma pedidos.
        </p>

        <div
            v-else-if="medicamentos.length === 0"
            class="rounded-lg border border-dashed p-8 text-center"
        >
            <Pill class="mx-auto size-8 text-muted-foreground" />
            <p class="mt-3 text-sm text-muted-foreground">
                Esta ficha todavía no tiene medicamentos cargados.
            </p>
            <Button variant="outline" class="mt-4" as-child>
                <Link
                    :href="
                        TratamientoController.index({ paciente: paciente.id })
                    "
                >
                    <Pill />
                    Ir a Medicamentos
                </Link>
            </Button>
        </div>

        <template v-else>
            <nav aria-label="Solapas" class="flex flex-wrap gap-2">
                <Link
                    v-for="opcion in solapas"
                    :key="opcion.valor"
                    :href="enlaceSolapa(opcion.valor)"
                    preserve-state
                    preserve-scroll
                    :aria-current="solapa === opcion.valor ? 'page' : undefined"
                    class="inline-flex min-h-11 items-center gap-2 rounded-md border px-4 text-sm font-medium transition-colors focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                    :class="clase(solapa === opcion.valor)"
                >
                    <component :is="opcion.icono" class="size-4" />
                    {{ opcion.titulo }}
                </Link>
            </nav>

            <div v-show="solapa === 'mensajes'">
                <SolapaMensajes
                    :paciente="paciente"
                    :medicamentos="medicamentos"
                    :destinatarios="destinatarios"
                    :plantilla-generica="plantillaGenerica"
                    :plantilla-original="plantillaOriginal"
                />
            </div>

            <SolapaProcedimiento
                v-if="solapa === 'procedimiento' && procedimiento"
                :paciente="paciente"
                :medicamentos="medicamentos"
                :medicamento-elegido="medicamentoElegido"
                :procedimiento="procedimiento"
                :hoy="hoy"
            />
        </template>
    </div>
</template>
