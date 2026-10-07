{{--
    ⚠️ TEXTO PLANO: {!! !!} y nunca {{ }}. Blade escapa como HTML, y en un mail de
    texto eso solo rompe: "D'Angelo" llegaba escrito "D&#039;Angelo". Ver
    invitacion-ficha.blade.php, donde el mismo escape dejaba el enlace inservible.
--}}
{!! $remitente !!} te manda esta documentación desde MiSalud.
@if ($mensaje)

{!! $mensaje !!}
@endif

Adjuntos:
@foreach ($archivos as $archivo)
- {!! $archivo !!}
@endforeach

Si respondés este mail, la respuesta le llega a {!! $remitente !!}.
