import {
    Activity,
    BookUser,
    Cross,
    Building2,
    IdCard,
    KeyRound,
    LayoutGrid,
    Mail,
    Pill,
    Settings,
    Stethoscope,
    Syringe,
    Users,
} from '@lucide/vue';
import CentroController from '@/actions/App/Http/Controllers/CentroController';
import CasillaController from '@/actions/App/Http/Controllers/CasillaController';
import CredencialController from '@/actions/App/Http/Controllers/CredencialController';
import ClaveController from '@/actions/App/Http/Controllers/ClaveController';
import ContactoController from '@/actions/App/Http/Controllers/ContactoController';
import FarmaciaController from '@/actions/App/Http/Controllers/FarmaciaController';
import MedicionController from '@/actions/App/Http/Controllers/MedicionController';
import MedicoController from '@/actions/App/Http/Controllers/MedicoController';
import PacienteController from '@/actions/App/Http/Controllers/PacienteController';
import TratamientoController from '@/actions/App/Http/Controllers/TratamientoController';
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
    /*
     * La credencial del paciente principal (el que comparte el correo con la
     * cuenta), a un toque: es lo que se busca apurado en un mostrador.
     */
    {
        title: 'Credencial',
        href: CredencialController.index(),
        icon: IdCard,
        tono: 'celeste',
    },
    {
        title: 'Pacientes',
        href: PacienteController.index(),
        icon: Users,
        tono: 'rosa',
    },
    /*
     * A qué dirección reenviar recetas, órdenes y estudios, y lo que quedó por
     * revisar. Arriba y no con los ajustes: se usa cada vez que llega un papel.
     */
    {
        title: 'Casilla de recetas',
        href: CasillaController.index(),
        icon: Mail,
        tono: 'durazno',
    },
    /*
     * Variables y Medicamentos llevan directo a los del paciente principal
     * (la pantalla deja cambiar de ficha). Los catálogos de tipos de variable y
     * de medicamentos se abren desde ahí: como entradas del menú, con semillas
     * y botones «Copiar», confundían.
     */
    {
        title: 'Variables',
        href: MedicionController.principal(),
        icon: Activity,
        tono: 'rosa',
    },
    {
        title: 'Medicamentos',
        href: TratamientoController.principal(),
        icon: Pill,
        tono: 'lavanda',
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
     * Usuario y contraseña de los sitios de salud de cada ficha. Va acá arriba
     * y no adentro de la ficha porque se busca apurado ("¿cuál era la clave del
     * portal de OSDE de mamá?"), y desde acá están las de toda la familia.
     */
    {
        title: 'Claves',
        href: ClaveController.index(),
        icon: KeyRound,
        tono: 'limon',
    },
    /*
     * Los catálogos son del USUARIO, no de un paciente: por eso van al mismo
     * nivel que Pacientes y no adentro.
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
        title: 'Farmacias',
        href: FarmaciaController.index(),
        icon: Cross,
        tono: 'durazno',
    },
    {
        title: 'Vacunas',
        href: VacunaController.index(),
        icon: Syringe,
        tono: 'limon',
    },
];

/**
 * Lo que va abajo de todo: los ajustes, no el uso diario.
 */
export const destinosSecundarios: NavItem[] = [
    {
        title: 'Configuración',
        href: editarPerfil(),
        icon: Settings,
        tono: 'celeste',
    },
];
