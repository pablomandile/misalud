{{-- Texto plano: ver el comentario de `AvisoDeRecordatorio` para por qué. --}}
Hola {{ $saludo }},

@if ($paciente)
{{ $aviso }} de {{ $paciente }}: el {{ $cuando }}.
@else
{{ $aviso }}: el {{ $cuando }}.
@endif

Entrá a MiSalud para ver el detalle:
{{ $enlace }}

--
Recibís este aviso porque administrás esa ficha en MiSalud.
