/*
 * Genera el set de íconos de la PWA desde los SVG de `resources/marca/`.
 *
 * Los PNG NO se editan a mano. Cuando cambie la marca, se reemplazan los dos
 * SVG de origen, se corre esto, y se sube el número de versión (ver abajo).
 *
 *   npm run generar:iconos
 *
 * Rasteriza con el Chrome que ya usa `revisar-mobile.mjs`, así no hace falta
 * sumar una dependencia de imágenes solo para esto.
 *
 * ⚠️ Al cambiar un ícono hay que tocar TRES lugares o se sigue viendo el
 * viejo, y cada uno es una caché distinta:
 *
 *   1. el nombre de `CACHE` en `public/sw.js`   (caché del service worker)
 *   2. el `?v=` de los <link rel="icon"> en `app.blade.php`  (caché HTTP)
 *   3. el `?v=` de los "src" en `public/manifest.webmanifest` (base de
 *      favicons de Chrome mobile, que es aparte y muy pegajosa)
 */

import { mkdir, readFile, writeFile } from 'node:fs/promises';
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

/** @type {{ origen: string, destino: string, lado: number }[]} */
const SALIDAS = [
    { origen: 'icono.svg', destino: 'public/icons/icon-192.png', lado: 192 },
    { origen: 'icono.svg', destino: 'public/icons/icon-512.png', lado: 512 },
    {
        origen: 'icono-maskable.svg',
        destino: 'public/icons/icon-maskable-512.png',
        lado: 512,
    },
    /*
     * iOS no entiende `purpose: maskable` ni transparencias: recorta el ícono
     * con su propio radio y le pone fondo negro a lo que sea transparente. Por
     * eso el apple-touch-icon sale del maskable, que es el de fondo lleno.
     */
    {
        origen: 'icono-maskable.svg',
        destino: 'public/apple-touch-icon.png',
        lado: 180,
    },
];

const navegador = await puppeteer.launch({
    executablePath: CHROME,
    headless: 'new',
    args: ['--no-sandbox'],
});

const pagina = await navegador.newPage();

await mkdir(resolve(RAIZ, 'public/icons'), { recursive: true });

for (const { origen, destino, lado } of SALIDAS) {
    const svg = await readFile(
        resolve(RAIZ, 'resources/marca', origen),
        'utf8',
    );

    await pagina.setViewport({
        width: lado,
        height: lado,
        deviceScaleFactor: 1,
    });

    // `margin: 0` y el SVG al 100%: sin esto quedan bordes blancos del body.
    await pagina.setContent(
        `<!doctype html><meta charset="utf-8">
         <style>html,body{margin:0;padding:0;width:100%;height:100%}
         svg{display:block;width:100%;height:100%}</style>${svg}`,
        { waitUntil: 'load' },
    );

    /*
     * `omitBackground: true` o las esquinas redondeadas del ícono salen con el
     * fondo por defecto de Chrome headless —un gris casi negro, opaco— en vez
     * de transparentes. Se ve como un cuadrado oscuro en cualquier launcher
     * claro, y no se nota hasta instalar la app.
     *
     * No afecta al maskable ni al de iOS: esos llenan el lienzo entero, así
     * que no tienen zona transparente, que es justo lo que iOS necesita (la
     * transparencia la pinta de negro).
     */
    const png = await pagina.screenshot({
        type: 'png',
        omitBackground: true,
        clip: { x: 0, y: 0, width: lado, height: lado },
    });

    await writeFile(resolve(RAIZ, destino), png);
    console.log(`  ${destino.padEnd(38)} ${lado}x${lado}`);
}

// El favicon vectorial es el mismo SVG de origen, sin rasterizar.
await writeFile(
    resolve(RAIZ, 'public/favicon.svg'),
    await readFile(resolve(RAIZ, 'resources/marca/icono.svg'), 'utf8'),
);
console.log(
    '  public/favicon.svg                     (copia del SVG de origen)',
);

await navegador.close();

console.log(
    '\nListo. Si cambiaste la marca, subí el ?v= en app.blade.php y en el manifest,\ny el nombre de CACHE en public/sw.js.',
);
