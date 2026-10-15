/*
 * Genera los íconos de la PWA, el favicon y la variante oscura del logo desde
 * las dos imágenes de la marca, en `public/img/`:
 *
 *   mi-salud-icono.webp          el ícono solo (1024×1024, esquinas transparentes)
 *   mi-salud-logo-nombre.webp    el ícono con "Mi Salud" al lado
 *
 * Los PNG NO se editan a mano. Cuando cambie la marca, se reemplazan esas dos
 * imágenes, se corre esto, y se sube el número de versión (ver abajo).
 *
 *   npm run generar:iconos
 *
 * Dibuja con un <canvas> del Chrome que ya usa `revisar-mobile.mjs`, así no
 * hace falta sumar una dependencia de imágenes solo para esto.
 *
 * ⚠️ Al cambiar un ícono hay que tocar TRES lugares o se sigue viendo el
 * viejo, y cada uno es una caché distinta:
 *
 *   1. el nombre de `CACHE` en `public/sw.js`   (caché del service worker)
 *   2. el `?v=` de los <link rel="icon"> en `app.blade.php`  (caché HTTP)
 *   3. el `?v=` de los "src" en `public/manifest.webmanifest` (base de
 *      favicons de Chrome mobile, que es aparte y muy pegajosa)
 */

import { readFile, writeFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

let puppeteer;

try {
    ({ default: puppeteer } = await import('puppeteer-core'));
} catch {
    console.error(
        'Falta puppeteer-core. Instalalo con: npm i -D puppeteer-core',
    );
    process.exit(2);
}

const RAIZ = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const CHROME =
    process.env.CHROME ??
    'C:/Program Files/Google/Chrome/Application/chrome.exe';

/** El verde azulado del fondo del ícono: es también el `theme-color`. */
const FONDO = '#0e6b6b';

/**
 * El texto del logo en modo oscuro. El original es el mismo verde azulado del
 * fondo del ícono, que sobre el fondo oscuro de la app casi no se lee.
 */
const TEXTO_OSCURO = '#cdeae6';

/**
 * - `lleno`: el maskable y el de iOS. Android recorta el ícono con su propia
 *   máscara, así que el lienzo tiene que estar lleno hasta el borde; y iOS
 *   pinta de negro cualquier transparencia. Se pinta el fondo de la marca y el
 *   ícono encima: sus esquinas redondeadas se funden con el fondo, y la cruz
 *   (un 53% del lado) queda bien adentro de la zona segura del 80%.
 * - sin `lleno`: las esquinas quedan transparentes, como en la imagen.
 *
 * @type {{ destino: string, lado: number, lleno?: boolean }[]}
 */
const ICONOS = [
    { destino: 'public/icons/icon-192.png', lado: 192 },
    { destino: 'public/icons/icon-512.png', lado: 512 },
    { destino: 'public/icons/icon-maskable-512.png', lado: 512, lleno: true },
    { destino: 'public/apple-touch-icon.png', lado: 180, lleno: true },
    { destino: 'public/icons/favicon-32.png', lado: 32 },
    { destino: 'public/icons/favicon-48.png', lado: 48 },
];

async function comoDataUrl(ruta) {
    const bytes = await readFile(resolve(RAIZ, ruta));

    return `data:image/webp;base64,${bytes.toString('base64')}`;
}

const navegador = await puppeteer.launch({
    executablePath: CHROME,
    headless: 'new',
    args: ['--no-sandbox'],
});

const pagina = await navegador.newPage();
await pagina.setContent('<!doctype html><meta charset="utf-8">');

const icono = await comoDataUrl('public/img/mi-salud-icono.webp');

for (const { destino, lado, lleno = false } of ICONOS) {
    const png = await pagina.evaluate(
        async (fuente, lado, lleno, fondo) => {
            const imagen = new Image();
            imagen.src = fuente;
            await imagen.decode();

            const lienzo = document.createElement('canvas');
            lienzo.width = lado;
            lienzo.height = lado;
            const ctx = lienzo.getContext('2d');
            ctx.imageSmoothingQuality = 'high';

            if (lleno) {
                ctx.fillStyle = fondo;
                ctx.fillRect(0, 0, lado, lado);
            }

            ctx.drawImage(imagen, 0, 0, lado, lado);

            return lienzo.toDataURL('image/png').split(',')[1];
        },
        icono,
        lado,
        lleno,
        FONDO,
    );

    await writeFile(resolve(RAIZ, destino), Buffer.from(png, 'base64'));
    console.log(`  ${destino.padEnd(40)} ${lado}x${lado}`);
}

/*
 * La variante oscura del logo con nombre: el ícono queda igual (ya tiene su
 * propio fondo) y se aclara solo el texto. El corte entre los dos es la
 * primera franja de columnas vacía después del ícono, buscada en la imagen y
 * no fijada a mano: si cambia la proporción del logo, el corte la sigue.
 */
const logo = await comoDataUrl('public/img/mi-salud-logo-nombre.webp');
const oscuro = await pagina.evaluate(
    async (fuente, color) => {
        const imagen = new Image();
        imagen.src = fuente;
        await imagen.decode();

        const lienzo = document.createElement('canvas');
        lienzo.width = imagen.naturalWidth;
        lienzo.height = imagen.naturalHeight;
        const ctx = lienzo.getContext('2d');
        ctx.drawImage(imagen, 0, 0);

        const { width: ancho, height: alto } = lienzo;
        const datos = ctx.getImageData(0, 0, ancho, alto);
        const px = datos.data;

        const columnaVacia = (x) => {
            for (let y = 0; y < alto; y++) {
                if (px[(y * ancho + x) * 4 + 3] !== 0) {
                    return false;
                }
            }

            return true;
        };

        // Saltear el margen transparente de la izquierda, recorrer el ícono y
        // frenar en la primera columna vacía: ahí empieza el texto.
        let x = 0;
        while (x < ancho && columnaVacia(x)) x++;
        while (x < ancho && !columnaVacia(x)) x++;
        const corte = x;

        const r = parseInt(color.slice(1, 3), 16);
        const g = parseInt(color.slice(3, 5), 16);
        const b = parseInt(color.slice(5, 7), 16);

        for (let y = 0; y < alto; y++) {
            for (let cx = corte; cx < ancho; cx++) {
                const i = (y * ancho + cx) * 4;
                // Solo el color: el alfa queda, y con él el suavizado del borde.
                px[i] = r;
                px[i + 1] = g;
                px[i + 2] = b;
            }
        }

        ctx.putImageData(datos, 0, 0);

        return {
            corte,
            webp: lienzo.toDataURL('image/webp', 0.92).split(',')[1],
        };
    },
    logo,
    TEXTO_OSCURO,
);

await writeFile(
    resolve(RAIZ, 'public/img/mi-salud-logo-nombre-oscuro.webp'),
    Buffer.from(oscuro.webp, 'base64'),
);
console.log(
    `  public/img/mi-salud-logo-nombre-oscuro.webp  (texto aclarado desde x=${oscuro.corte})`,
);

await navegador.close();

console.log(
    '\nListo. Si cambiaste la marca, subí el ?v= en app.blade.php y en el manifest,\ny el nombre de CACHE en public/sw.js.',
);
