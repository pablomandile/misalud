{{--
    ⚠️ TEXTO PLANO: {!! !!} y nunca {{ }} (ver envio-documentos.blade.php).
--}}
{!! $remitente !!} te manda este pedido desde MiSalud.

@foreach ($renglones as $renglon)
- {!! $renglon['medicamento'] !!}
{!! $renglon['mensaje'] !!}

@endforeach
Si respondés este mail, la respuesta le llega a {!! $remitente !!}.
