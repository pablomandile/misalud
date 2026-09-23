/**
 * Le pide al service worker que olvide una credencial cacheada.
 *
 * Cuando se borra una credencial desde la app, la fila del servidor
 * desaparece, pero la copia que el service worker guardó para verse sin
 * señal (ver `public/sw.js`) no se entera sola: una app instalada casi
 * nunca hace una recarga completa que la renueve. Sin este aviso, quedaría
 * "disponible sin conexión" una foto que ya no existe.
 *
 * No hace nada si no hay un service worker activo -navegador sin soporte, o
 * la página todavía no lo registró-: la fila igual se borra del lado del
 * servidor, que es lo que de verdad importa. Esto es solo prolijidad para no
 * dejar una copia vieja dando vueltas.
 */
export function olvidarCredencial(url: string): void {
    navigator.serviceWorker?.controller?.postMessage({
        tipo: 'olvidar-credencial',
        url,
    });
}
