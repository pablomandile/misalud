<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EstadoReceta;
use App\Enums\TipoAdjunto;
use App\Models\CuentaMail;
use App\Models\Receta;
use App\Support\MensajeDeCasilla;
use App\Support\ResumenDeSincronizacion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Trae las recetas de una casilla. **Acá viven todas las decisiones del paso.**
 *
 * No habla IMAP: recibe `MensajeDeCasilla` de `LectorDeCasilla`. Esa separación
 * es lo que permite probar sin red la ventana, el solapamiento, los filtros, la
 * deduplicación y qué adjunto entra — que es donde están los errores que
 * importan.
 */
class SincronizadorDeRecetas
{
    public function __construct(
        private readonly LectorDeCasilla $lector,
        private readonly ArchivoService $archivos,
    ) {}

    public function sincronizar(CuentaMail $cuenta, bool $seco = false): ResumenDeSincronizacion
    {
        $inicio = CarbonImmutable::now();
        $tope = (int) config('misalud.recetas.tope_por_corrida');

        $mensajes = $this->lector->mensajesDesde($cuenta, $this->ventana($cuenta), $tope);

        $importadas = 0;
        $repetidas = 0;
        $filtradas = 0;
        $sinArchivos = 0;
        $fallidas = 0;
        $ultimaFecha = null;

        foreach ($mensajes as $mensaje) {
            // Se mueve con CADA mensaje mirado, no solo con los importados: lo
            // que marca hasta dónde se llegó es lo que se revisó, no lo que
            // sirvió.
            $ultimaFecha = $mensaje->fecha;

            if (! $this->pasaElFiltro($cuenta, $mensaje)) {
                $filtradas++;

                continue;
            }

            /*
             * Se revisa ANTES de abrir la transacción, y sin escribir nada: un
             * mail sin ningún archivo servible no es una receta. Ver
             * `archivosUtiles()`.
             */
            $utiles = $this->archivosUtiles($mensaje);

            if ($utiles === []) {
                $sinArchivos++;

                continue;
            }

            if ($this->yaEsta($cuenta, $mensaje)) {
                $repetidas++;

                continue;
            }

            if ($seco) {
                $importadas++;

                continue;
            }

            try {
                $this->importar($cuenta, $mensaje, $utiles);
                $importadas++;
            } catch (Throwable $e) {
                /*
                 * Por mensaje, igual que el `try` por destinatario de
                 * `misalud:enviar-recordatorios`: un mail roto no puede llevarse
                 * la tanda. Al log van ids y nada más -ni el remitente, ni el
                 * asunto, que son contenido de alguien-.
                 */
                $fallidas++;

                Log::error('No se pudo importar una receta', [
                    'cuenta_mail_id' => $cuenta->id,
                    'usuario_id' => $cuenta->usuario_id,
                    'excepcion' => $e::class,
                    'mensaje' => $e->getMessage(),
                ]);
            }
        }

        if (! $seco) {
            $this->moverLaMarca($cuenta, $inicio, $ultimaFecha, count($mensajes) >= $tope);
        }

        return new ResumenDeSincronizacion(
            miradas: count($mensajes),
            importadas: $importadas,
            repetidas: $repetidas,
            filtradas: $filtradas,
            sinArchivos: $sinArchivos,
            fallidas: $fallidas,
        );
    }

    /**
     * Desde cuándo pedirle mensajes a la casilla.
     *
     * ⚠️ **No es "desde el día 1 del mes"**, que es lo que decía el plan
     * original. Con ese criterio, una receta que llegó el 31 de enero desaparece
     * el 1 de febrero aunque nadie la haya usado. El contador del mes de la
     * bandeja es una **vista sobre lo importado**, no el criterio con el que se
     * importa.
     *
     * Primera corrida: `dias_iniciales` hacia atrás. Después: desde la última
     * sincronización **menos el solapamiento**, porque el `SINCE` de IMAP compara
     * por día y un mail puede entregarse tarde o fuera de orden. Reimportar es
     * gratis -lo frena el UNIQUE del índice ciego-, así que de más no cuesta nada
     * y de menos sería un agujero que nadie nota.
     */
    private function ventana(CuentaMail $cuenta): CarbonImmutable
    {
        $marca = $cuenta->sincronizado_hasta;

        if ($marca === null) {
            return CarbonImmutable::now()
                ->subDays((int) config('misalud.recetas.dias_iniciales'));
        }

        return CarbonImmutable::instance($marca)
            ->subDays((int) config('misalud.recetas.dias_de_solapamiento'));
    }

    /**
     * Hasta dónde quedó sincronizada la casilla.
     *
     * Dos casos, y el segundo es el que evita un bucle:
     *
     * - **Se vio la ventana entera** (volvieron menos mensajes que el tope): la
     *   marca va al instante en que **arrancó** la corrida, no a `now()`. Un mail
     *   que llegó mientras corríamos no puede quedar del lado ya revisado.
     * - **Se truncó por el tope**: la marca va a la fecha del último mensaje
     *   mirado. Si fuera al inicio de la corrida, todo lo que quedó sin mirar
     *   caería fuera de la próxima ventana y se perdería; y si no se moviera, la
     *   corrida siguiente traería **los mismos** y la casilla no avanzaría nunca.
     *
     * ⚠️ **Nunca hacia atrás.** Cuando todos los mensajes de la ventana están en
     * la zona de solapamiento, la fecha del último es anterior a la marca actual:
     * moverla ahí agrandaría la ventana en cada corrida hasta volver a mirar la
     * casilla entera.
     */
    private function moverLaMarca(
        CuentaMail $cuenta,
        CarbonImmutable $inicio,
        ?CarbonImmutable $ultimaFecha,
        bool $seTrunco,
    ): void {
        $nueva = $seTrunco && $ultimaFecha !== null ? $ultimaFecha : $inicio;
        $anterior = $cuenta->sincronizado_hasta;

        if ($anterior !== null && $nueva->lessThan($anterior)) {
            return;
        }

        $cuenta->setAttribute('sincronizado_hasta', $nueva);
        $cuenta->save();
    }

    /**
     * ¿Es de alguien de quien queremos importar?
     *
     * Sin filtros, **todo pasa**: quien ya armó una regla en su correo para que
     * las recetas caigan en una carpeta propia filtró antes que nosotros, y la
     * carpeta es el otro filtro.
     *
     * Un filtro puede ser una dirección entera o un dominio. El dominio también
     * acepta subdominios, porque una obra social grande manda desde
     * `avisos.osde.com.ar` y quien escribió `osde.com.ar` quiso decir eso.
     */
    private function pasaElFiltro(CuentaMail $cuenta, MensajeDeCasilla $mensaje): bool
    {
        $filtros = $cuenta->remitentesAceptados();

        if ($filtros === []) {
            return true;
        }

        $remitente = mb_strtolower($mensaje->remitente);
        $dominio = Str::after($remitente, '@');

        foreach ($filtros as $filtro) {
            if ($remitente === $filtro
                || $dominio === $filtro
                || str_ends_with($dominio, '.'.$filtro)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Los adjuntos que de verdad sirven, con su mime ya deducido del contenido.
     *
     * ⚠️ **Un mail sin ningún archivo servible NO es una receta.** Una receta
     * *es* su PDF: la farmacia que avisa "tu receta está lista, entrá a nuestro
     * sitio" no manda ninguna, y guardar una fila por ese mail llenaría la bandeja
     * de recetas que no se pueden mostrar.
     *
     * Y se descarta de a uno, no de a mail entero: un adjunto que se pasa de los
     * 12 MB o que es un `.docx` es un resultado **normal**, no un error de la
     * tanda. Si el mail trae la receta en PDF y además un instructivo en Word, se
     * importa la receta.
     *
     * @return list<array{nombre: string, contenido: string}>
     */
    private function archivosUtiles(MensajeDeCasilla $mensaje): array
    {
        $utiles = [];

        foreach ($mensaje->adjuntos as $adjunto) {
            try {
                // Solo pregunta; no escribe nada todavía.
                $this->archivos->mimeAceptado($adjunto->contenido);
            } catch (RuntimeException) {
                continue;
            }

            $utiles[] = ['nombre' => $adjunto->nombre, 'contenido' => $adjunto->contenido];
        }

        return $utiles;
    }

    /**
     * ¿Ya se importó este mail?
     *
     * ⚠️ **Con `withTrashed()`.** Una receta en la papelera sigue ocupando su
     * `message_id_hash` —el UNIQUE de la base no sabe de soft deletes—, así que sin
     * esto el `create()` se estrellaría contra la base en vez de contarse como
     * repetida. Y es el comportamiento correcto: esa receta todavía existe y se
     * puede restaurar.
     */
    private function yaEsta(CuentaMail $cuenta, MensajeDeCasilla $mensaje): bool
    {
        return Receta::withTrashed()
            ->where('usuario_id', $cuenta->usuario_id)
            ->dondeIndiceCiego('message_id', $mensaje->identificador)
            ->exists();
    }

    /**
     * Crea la receta con sus archivos, todo o nada.
     *
     * Las filas van en una transacción, y si se cae hay que **borrar del disco lo
     * que ya se había escrito**: un rollback deshace las filas pero no los
     * archivos, y un archivo cifrado que nada referencia es invisible y para
     * siempre.
     *
     * @param  list<array{nombre: string, contenido: string}>  $utiles
     */
    private function importar(CuentaMail $cuenta, MensajeDeCasilla $mensaje, array $utiles): void
    {
        $escritas = [];

        try {
            DB::transaction(function () use ($cuenta, $mensaje, $utiles, &$escritas): void {
                $receta = $cuenta->usuario->recetas()->create([
                    'cuenta_mail_id' => $cuenta->id,
                    'message_id' => $mensaje->identificador,
                    'remitente' => $mensaje->remitente,
                    'asunto' => $mensaje->asunto,
                    // La fecha del MAIL, no la de la importación: si el servidor
                    // estuvo caído tres días, la receta no gana tres días de vida.
                    'fecha_recepcion' => $mensaje->fecha,
                    'vigencia_dias' => (int) config('misalud.recetas.vigencia_dias'),
                    'estado' => EstadoReceta::Disponible,
                ]);

                foreach ($utiles as $archivo) {
                    $datos = $this->archivos->guardarContenido(
                        $archivo['contenido'],
                        $archivo['nombre'],
                        $receta->carpetaDeArchivos(),
                    );

                    $escritas[] = $datos['ruta'];

                    // Por la relación: `adjuntable_type`/`_id` no son fillable,
                    // por lo mismo que `usuario_id` no lo es.
                    $receta->adjuntos()->create([
                        ...$datos,
                        'tipo' => TipoAdjunto::Receta,
                    ]);
                }
            });
        } catch (Throwable $e) {
            foreach ($escritas as $ruta) {
                $this->archivos->borrarRuta($ruta);
            }

            throw $e;
        }
    }
}
