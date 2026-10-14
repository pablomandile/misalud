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
/**
 * El nombre de la caché de credenciales en `public/sw.js`. Repetido acá porque
 * la página la borra sin pasar por el service worker (ver abajo);
 * `AvisoCookiesTest` falla si los dos se separan.
 */
const CACHE_CREDENCIALES = 'misalud-credenciales-v1';

/**
 * Borra TODAS las credenciales guardadas para verse sin señal. Va al salir de
 * la cuenta: son datos de la historia de alguien en un teléfono que puede ser
 * compartido, y sin esto quedaban guardados después de cerrar la sesión.
 *
 * Lo hace la página directamente con la Cache Storage API, que comparte con
 * el service worker, y no por `postMessage`: un mensaje se pierde si el
 * service worker todavía no controla la página, y esto no puede depender de
 * eso.
 */
export function olvidarTodasLasCredenciales(): void {
    try {
        void window.caches?.delete(CACHE_CREDENCIALES);
    } catch {
        // Sin Cache Storage (navegador viejo, modo privado) no hay nada guardado.
    }
}

export function olvidarCredencial(url: string): void {
    navigator.serviceWorker?.controller?.postMessage({
        tipo: 'olvidar-credencial',
        url,
    });
}
