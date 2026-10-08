import {
    Activity,
    BookUser,
    Building2,
    FileText,
    LayoutGrid,
    Mail,
    Pill,
    Settings,
    Stethoscope,
    Syringe,
    Users,
} from '@lucide/vue';
import CentroController from '@/actions/App/Http/Controllers/CentroController';
import ContactoController from '@/actions/App/Http/Controllers/ContactoController';
import CuentaMailController from '@/actions/App/Http/Controllers/CuentaMailController';
import MedicamentoController from '@/actions/App/Http/Controllers/MedicamentoController';
import MedicoController from '@/actions/App/Http/Controllers/MedicoController';
import PacienteController from '@/actions/App/Http/Controllers/PacienteController';
import RecetaController from '@/actions/App/Http/Controllers/RecetaController';
import TipoMedicionController from '@/actions/App/Http/Controllers/TipoMedicionController';
import VacunaController from '@/actions/App/Http/Controllers/VacunaController';
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
        tono: 'lavanda',
    },
    {
        title: 'Pacientes',
        href: PacienteController.index(),
        icon: Users,
        tono: 'rosa',
    },
    /*
     * La bandeja de recetas va arriba, al lado de Pacientes: se abre en el
     * mostrador de la farmacia. La casilla que la alimenta va abajo, con los
     * ajustes, porque se configura una vez.
     */
    {
        title: 'Recetas',
        href: RecetaController.index(),
        icon: FileText,
        tono: 'durazno',
    },
    /*
     * La libreta y, en la misma pantalla, lo que se le mandó a cada uno. Un
     * envío no arranca desde acá sino desde un documento ("Enviar" al lado de
     * una receta o una orden): esta es la pantalla de "¿qué le mandé a OSDE?".
     */
    {
        title: 'Contactos',
        href: ContactoController.index(),
        icon: BookUser,
        tono: 'celeste',
    },
    /*
     * Los catálogos son del USUARIO, no de un paciente: por eso van al mismo
     * nivel que Pacientes y no adentro. Ya son las cuatro entradas que este
     * comentario anticipaba -cuatro sueltas son muchas para un menú que usan
     * personas mayores-; agruparlas bajo "Catálogos" queda pendiente y sigue
     * viviendo entero en este archivo el día que se haga.
     */
    {
        title: 'Médicos',
        href: MedicoController.index(),
        icon: Stethoscope,
        tono: 'menta',
    },
    {
        title: 'Centros',
        href: CentroController.index(),
        icon: Building2,
        tono: 'turquesa',
    },
    {
        title: 'Medicamentos',
        href: MedicamentoController.index(),
        icon: Pill,
        tono: 'lavanda',
    },
    {
        title: 'Vacunas',
        href: VacunaController.index(),
        icon: Syringe,
        tono: 'limon',
    },
    {
        title: 'Variables',
        href: TipoMedicionController.index(),
        icon: Activity,
        tono: 'rosa',
    },
];

/**
 * Lo que va abajo de todo: ajustes y ayuda, no el uso diario.
 *
 * La casilla de correo va acá y no arriba a propósito: se configura una vez y
 * después no se vuelve a tocar. Lo que sí es de uso diario es la bandeja de
 * recetas que esa casilla alimenta, y esa va arriba.
 */
export const destinosSecundarios: NavItem[] = [
    {
        title: 'Casilla de recetas',
        href: CuentaMailController.index(),
        icon: Mail,
        tono: 'durazno',
    },
    {
        title: 'Configuración',
        href: editarPerfil(),
        icon: Settings,
        tono: 'celeste',
    },
];
