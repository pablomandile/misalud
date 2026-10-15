<!--
    El logo con el nombre: el ícono y "Mi Salud" al lado. Va en la barra lateral
    de escritorio y en el ingreso y el registro.

    Son DOS imágenes y no una con un filtro: el texto original es el mismo verde
    azulado del ícono, que sobre el fondo oscuro de la app casi no se lee. La
    variante oscura (`-oscuro.webp`) la genera `npm run generar:iconos`
    aclarando solo el texto, así el ícono queda idéntico en los dos temas. Un
    `filter: invert()` también daría vuelta el ícono.

    El ancho lo dicta el alto que se le pida (`h-10`, `h-14`): `w-auto` y la
    proporción real de la imagen en `width`/`height`, para que el navegador
    reserve el lugar antes de que cargue y nada salte.
-->
<template>
    <span class="inline-flex shrink-0">
        <img
            src="/img/mi-salud-logo-nombre.webp"
            alt="MiSalud"
            width="2436"
            height="776"
            decoding="async"
            class="h-full w-auto object-contain dark:hidden"
        />
        <img
            src="/img/mi-salud-logo-nombre-oscuro.webp"
            alt="MiSalud"
            width="2436"
            height="776"
            decoding="async"
            class="hidden h-full w-auto object-contain dark:block"
        />
    </span>
</template>
