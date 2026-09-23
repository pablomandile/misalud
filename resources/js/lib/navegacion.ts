import {
    Building2,
    LayoutGrid,
    Settings,
    Stethoscope,
    Users,
} from '@lucide/vue';
import CentroController from '@/actions/App/Http/Controllers/CentroController';
import MedicoController from '@/actions/App/Http/Controllers/MedicoController';
import PacienteController from '@/actions/App/Http/Controllers/PacienteController';
import { dashboard } from '@/routes';
import { edit as editarPerfil } from '@/routes/profile';
import type { NavItem } from '@/types';

/**
 * Definición única de la navegación de MiSalud.
 *
 * La usan la barra lateral de escritorio y el menú hamburguesa del celular: es
 * el mismo árbol en los dos, no dos apps distintas. Cada etapa suma acá sus
 * destinos y aparecen solos en ambos lugares.
 *
 * **No hay barra inferior de pestañas**, al revés que en otras apps de esta
 * casa. Una barra de cinco íconos obliga a etiquetas de una palabra y a áreas
 * táctiles chicas; acá la app la usan personas mayores y hay más módulos que
 * cinco. Un menú hamburguesa con etiquetas de texto legibles gana en las dos
 * cosas, y el costo —un toque más para llegar— es el correcto de pagar.
 */
export const destinosPrincipales: NavItem[] = [
    {
        title: 'Inicio',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Pacientes',
        href: PacienteController.index(),
        icon: Users,
    },
    /*
     * Los catálogos son del USUARIO, no de un paciente: por eso van al mismo
     * nivel que Pacientes y no adentro. Con los otros dos (medicamentos,
     * vacunas) va a convenir agruparlos bajo "Catálogos" -cuatro entradas
     * sueltas son muchas para un menú que usan personas mayores-, y ese
     * cambio vive entero en este archivo.
     */
    {
        title: 'Médicos',
        href: MedicoController.index(),
        icon: Stethoscope,
    },
    {
        title: 'Centros',
        href: CentroController.index(),
        icon: Building2,
    },
];

/**
 * Lo que va abajo de todo: ajustes y ayuda, no el uso diario.
 */
export const destinosSecundarios: NavItem[] = [
    {
        title: 'Configuración',
        href: editarPerfil(),
        icon: Settings,
    },
];
