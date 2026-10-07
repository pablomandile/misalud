{{--
    ⚠️ TEXTO PLANO: todo va con {!! !!} y NUNCA con {{ }}. Blade escapa como HTML,
    y en un mail de texto no hay HTML que proteger: lo único que logra es romper.
    Con {{ }} el enlace salía con "&amp;" en vez de "&", la firma no coincidía, y
    TODA invitación llegaba inservible. Lo encontró la verificación en Chrome
    leyendo el mail real; el test de Pest pasaba porque solo buscaba "signature=".
--}}
{!! $invita !!} te invitó a la ficha de salud de {!! $paciente !!} en MiSalud.

@if ($puedeEditar)
Vas a poder ver su historia y también cargar y corregir datos: turnos, mediciones, estudios, tratamientos. La ficha sigue siendo de {!! $invita !!}.
@else
Vas a poder ver su historia completa, pero no cargar ni modificar nada.
@endif

Para aceptar, abrí este enlace con la cuenta de este mismo correo (si no tenés una, la podés crear ahí):

{!! $enlace !!}

La invitación vence el {!! $vence !!}. Si no esperabas este mail, ignoralo: sin aceptar, no pasa nada.
