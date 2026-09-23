/*
 * ¿La app es instalable de verdad?
 *
 * No alcanza con "se ve el botón". Chrome tiene su propio veredicto y lo expone
 * por CDP: `Page.getInstallabilityErrors` devuelve exactamente qué le falta.
 * Preguntarle es más rápido y más confiable que revisar el manifest a ojo.
 *
 *   npm run revisar:pwa
 *   BASE=https://misalud.pablomandile.com.ar npm run revisar:pwa
 *
 * ⚠️ Esto hay que correrlo también **en producción**, no solo en local: ahí
 * aparecen los problemas de CDN y de cabeceras que en `artisan serve` no
 * existen.
 */

let puppeteer;

try {
    ({ default: puppeteer } = await import('puppeteer-core'));
} catch {
    console.error(
        'Falta puppeteer-core. Instalalo con: npm i -D puppeteer-core',
    );
    process.exit(2);
}

const CHROME =
    process.env.CHROME ??
    'C:/Program Files/Google/Chrome/Application/chrome.exe';
const BASE = process.env.BASE ?? 'http://127.0.0.1:8001';
const EMAIL = process.env.EMAIL ?? 'prueba@misalud.test';
const PASSWORD = process.env.PASSWORD ?? 'prueba-1234';

async function ingresar(pagina) {
    await pagina.goto(`${BASE}/login`, { waitUntil: 'networkidle0' });
    await pagina.type('input[name="email"]', EMAIL);
    await pagina.type('input[name="password"]', PASSWORD);

    /*
     * `data-test` y no `button[type=submit]`: en esta pantalla hay más de un
     * botón y el orden puede cambiar. Y se espera por la URL, no con
     * `waitForNavigation`: el submit de Inertia es un XHR y no dispara el
     * evento de navegación, así que la espera vence y parece que falló.
     */
    await pagina.click('[data-test="login-button"]');

    await pagina
        .waitForFunction(() => !location.pathname.startsWith('/login'), {
            timeout: 10000,
        })
        .catch(() => {});

    return !pagina.url().includes('/login');
}

let problemas = 0;

const navegador = await puppeteer.launch({
    executablePath: CHROME,
    headless: 'new',
    args: ['--no-sandbox'],
});

const pagina = await navegador.newPage();
const cdp = await pagina.createCDPSession();

await pagina.goto(`${BASE}/login`, { waitUntil: 'networkidle0' });

// --- manifest ---
const { errors, url, data } = await cdp.send('Page.getAppManifest');

console.log(`== Manifest (${url || 'no declarado'})`);

if (errors.length > 0) {
    for (const e of errors) {
        console.log(`   ✗ ${e.message}`);
    }

    problemas += errors.length;
} else {
    console.log('   ✓ sin errores');
}

const manifest = data ? JSON.parse(data) : {};

/*
 * La rotación es un requisito de esta app: se usa el celular apaisado para
 * mirar los gráficos. Un `orientation: portrait` la bloquea en la PWA
 * instalada, y el síntoma NO se ve en el navegador: solo con la app instalada.
 */
const orientacion = manifest.orientation ?? '(sin declarar)';

console.log(
    orientacion === 'any' || orientacion === '(sin declarar)'
        ? `   ✓ orientation: ${orientacion} (se puede rotar)`
        : `   ✗ orientation: ${orientacion} — bloquea la rotación en la app instalada`,
);

if (orientacion !== 'any' && orientacion !== '(sin declarar)') {
    problemas++;
}

const tieneMaskable = (manifest.icons ?? []).some((i) =>
    (i.purpose ?? '').includes('maskable'),
);

console.log(
    tieneMaskable
        ? '   ✓ hay un ícono maskable para Android'
        : '   ✗ falta un ícono con purpose: maskable',
);

if (!tieneMaskable) {
    problemas++;
}

// --- instalabilidad ---
const { installabilityErrors } = await cdp.send('Page.getInstallabilityErrors');

console.log('\n== Instalabilidad según Chrome');

if (installabilityErrors.length === 0) {
    console.log('   ✓ instalable');
} else {
    for (const e of installabilityErrors) {
        console.log(
            `   ✗ ${e.errorId} ${e.errorArguments.map((a) => a.value).join(' ')}`,
        );
    }

    problemas += installabilityErrors.length;
}

// --- service worker ---
const sw = await pagina.evaluate(async () => {
    if (!('serviceWorker' in navigator)) {
        return { soportado: false };
    }

    const registro = await navigator.serviceWorker.getRegistration();

    return {
        soportado: true,
        activo: !!registro?.active,
        scriptURL: registro?.active?.scriptURL ?? null,
    };
});

console.log('\n== Service worker');
console.log(
    sw.activo
        ? `   ✓ activo (${sw.scriptURL})`
        : '   ✗ no quedó activo: sin un handler de fetch no cuenta para instalar',
);

if (!sw.activo) {
    problemas++;
}

// --- cabeceras de sw.js y manifest ---
console.log('\n== Cabeceras (importan en producción, con CDN adelante)');

for (const archivo of ['/sw.js', '/manifest.webmanifest']) {
    const r = await fetch(`${BASE}${archivo}?${Date.now()}`);
    const cc = r.headers.get('cache-control') ?? '(sin cache-control)';
    const tipo = r.headers.get('content-type') ?? '(sin content-type)';

    console.log(`   ${archivo.padEnd(22)} ${cc}  ·  ${tipo}`);
}

await navegador.close();

/*
 * El caso "ya está instalada": el botón de instalar tiene que desaparecer.
 *
 * ⚠️ No se puede probar con `Emulation.setEmulatedMedia`: CDP **no emula**
 * `display-mode`. Y como sí emula `prefers-color-scheme`, uno prueba lo mismo
 * con el tema, ve que anda, y se queda con un falso negativo. Lo que sí sirve
 * es levantar Chrome en modo app, que es exactamente cómo corre una PWA
 * instalada.
 */
console.log('\n== Con la app ya instalada (Chrome en modo app)');

const comoApp = await puppeteer.launch({
    executablePath: CHROME,
    headless: 'new',
    args: ['--no-sandbox', `--app=${BASE}/login`],
});

const pestania = (await comoApp.pages())[0];
await pestania.waitForNetworkIdle({ idleTime: 500 }).catch(() => {});

/*
 * Hay que entrar: el botón de instalar vive en la barra lateral, así que en
 * la pantalla de ingreso no está y el chequeo daría verde sin probar nada.
 * Esta ventana tiene su propio perfil, así que no hereda la sesión de
 * arriba.
 */
const enDashboard = await ingresar(pestania);
await new Promise((r) => setTimeout(r, 800));

if (!enDashboard) {
    console.log('   ? no se pudo entrar, así que este chequeo no prueba nada');
}

const enModoApp = await pestania.evaluate(() => ({
    standalone: matchMedia('(display-mode: standalone)').matches,
    /*
     * Se filtra por `offsetParent !== null`: hay dos layouts —barra lateral y
     * menú—, así que el botón puede estar dos veces en el DOM y el primero
     * puede ser el que está oculto.
     */
    botones: [...document.querySelectorAll('button')].filter(
        (b) => /Instalar la app/.test(b.innerText) && b.offsetParent !== null,
    ).length,
}));

console.log(
    enModoApp.standalone
        ? '   ✓ Chrome se comporta como app instalada'
        : '   ? no se pudo simular el modo app; lo de abajo no vale',
);

console.log(
    enModoApp.botones === 0
        ? '   ✓ el botón de instalar no se muestra'
        : `   ✗ el botón de instalar sigue visible (${enModoApp.botones})`,
);

if (enDashboard && enModoApp.standalone && enModoApp.botones > 0) {
    problemas++;
}

await comoApp.close();

console.log(
    problemas === 0 ? '\nTodo en orden.' : `\n${problemas} problema(s).`,
);
process.exit(problemas === 0 ? 0 : 1);
