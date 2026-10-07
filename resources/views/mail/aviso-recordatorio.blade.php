{{--
    Texto plano: ver el comentario de `AvisoDeRecordatorio` para por qué.
    ⚠️ {!! !!} y nunca {{ }}: Blade escapa como HTML, y en un mail de texto eso
    solo rompe ("D'Angelo" llegaba "D&#039;Angelo"). Ver invitacion-ficha.blade.php.
--}}
Hola {!! $saludo !!},

@if ($paciente)
{!! $aviso !!} de {!! $paciente !!}: el {!! $cuando !!}.
@else
{!! $aviso !!}: el {!! $cuando !!}.
@endif

Entrá a MiSalud para ver el detalle:
{!! $enlace !!}

--
Recibís este aviso porque administrás esa ficha en MiSalud.
