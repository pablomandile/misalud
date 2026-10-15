<!--
    El ícono de MiSalud: la cruz con el corazón, sobre su cuadrado verde
    azulado. Es la imagen de la marca tal cual (`public/img/mi-salud-icono.webp`),
    la misma de la que salen los íconos de la PWA y el favicon con
    `npm run generar:iconos`.

    Se usa donde hay lugar para el ícono solo: el menú del celular y la barra
    lateral contraída. Con lugar de sobra va `LogoConNombre`.

    `alt` con el nombre: casi siempre es el único contenido del enlace que lleva
    al inicio, y sin él ese enlace no tendría nombre para un lector de pantalla.
-->
<template>
    <img
        src="/img/mi-salud-icono.webp"
        alt="MiSalud"
        width="1024"
        height="1024"
        decoding="async"
        class="shrink-0 object-contain"
    />
</template>
