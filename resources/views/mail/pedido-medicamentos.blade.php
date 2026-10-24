{{--
    ⚠️ TEXTO PLANO: {!! !!} y nunca {{ }} (ver envio-documentos.blade.php).
--}}
{!! $remitente !!} te manda este pedido desde MiSalud.

{!! $mensaje !!}

@foreach ($medicamentos as $medicamento)
- {!! $medicamento !!}
@endforeach

Si respondés este mail, la respuesta le llega a {!! $remitente !!}.
