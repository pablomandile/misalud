/*
 * Lo que la pantalla de pedido de medicamentos recibe del servidor
 * (`PedidoMedicamentosController::index` y `SeguimientoDePedido::vista`).
 */

export type MedicamentoDelPedido = {
    id: number;
    nombre: string;
    dosis: string;
    activo: boolean;
    // Con sus variables sin reemplazar: la propia, o el genérico de la cuenta.
    plantilla: string;
    plantillaPropia: boolean;
};

export type DestinatarioOpcion = {
    clave: string;
    nombre: string;
    email: string;
    etiqueta: string;
};

export type PasoEnCurso = {
    etapaId: number;
    nombre: string;
    completada: boolean;
    completadaEl: string | null;
    observaciones: string;
};

export type PedidoDelHistorial = {
    id: number;
    inicioVisible: string;
    finVisible: string | null;
    pasos: {
        id: number;
        nombre: string;
        completada: boolean;
        completadaElVisible: string | null;
        observaciones: string | null;
    }[];
};

export type Procedimiento = {
    etapas: { id: number; nombre: string }[];
    enCurso: { id: number; iniciadoEl: string } | null;
    pasos: PasoEnCurso[];
    historial: PedidoDelHistorial[];
    reabrible: number | null;
};
