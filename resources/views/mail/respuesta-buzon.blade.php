{{--
    Texto plano: ver el comentario de `RespuestaDelBuzon`.
    ⚠️ {!! !!} y nunca {{ }}: Blade escapa como HTML, y en un mail de texto eso
    solo rompe ("D'Angelo" llegaba "D&#039;Angelo"). Ver invitacion-ficha.blade.php.
--}}
Hola,

@if ($guardado)
Guardamos tu {!! mb_strtolower((string) $queEs) !!} en la ficha de {!! $ficha !!}.
@elseif ($porRevisar)
Recibimos tu documento, pero quedó para revisar. {!! $porque !!}

Entrá a MiSalud para guardarlo en la ficha que corresponde:
{!! $enlace !!}
@else
Recibimos tu mail, pero no encontramos ningún archivo para guardar. Mandá la receta, la orden o el estudio como adjunto en PDF, JPG o PNG.
@endif

Recordá poner en el asunto Receta, Orden o Estudio. Si administrás varias fichas, agregá el nombre del paciente (por ejemplo: «Receta Mamá»).

--
Este mail lo manda MiSalud automáticamente. No hace falta contestarlo.
