{{ $remitente }} te manda esta documentación desde MiSalud.
@if ($mensaje)

{{ $mensaje }}
@endif

Adjuntos:
@foreach ($archivos as $archivo)
- {{ $archivo }}
@endforeach

Si respondés este mail, la respuesta le llega a {{ $remitente }}.
