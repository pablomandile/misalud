/*
 * Revisión mobile de MiSalud, en un navegador de verdad.
 *
 * Comprueba dos cosas que ningún test de PHP puede ver:
 *
 *   1. Que no haya scroll horizontal en la matriz completa de anchos por
 *      tamaños de letra por orientación. La matriz no es exceso de celo: los
 *      breakpoints de Tailwind se miden en px contra el viewport y NO escalan
 *      con el tamaño de letra, así que el caso "pantalla chica + letra muy
 *      grande" no lo cubre ningún `sm:`. Ya encontró un botón que se salía
 *      16 px a 320 px.
 *
 *   2. Que el menú hamburguesa se cierre al navegar, y que la barra lateral de
 *      escritorio NO se cierre. En una SPA la navegación no desmonta el sheet:
 *      la pantalla nueva carga detrás y el menú queda encima con el scroll del
 *      body bloqueado.
 *
 * Uso:
 *
 *   node scripts/revisar-mobile.mjs
 *   BASE=http://127.0.0.1:8001 EMAIL=... PASSWORD=... node scripts/revisar-mobile.mjs
 *
 * Necesita puppeteer-core y un Chrome instalado. Si no está:
 *
 *   npm i -D puppeteer-core
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

/*
 * Las pantallas de invitado se miden ANTES de iniciar sesión, en su propia
 * pasada. Estaban en la misma lista que el resto y por eso nunca se midieron:
 * con la sesión ya abierta, /login y /register redirigen al dashboard, así que
 * la matriz decía "sin desborde" sobre la pantalla equivocada. Es la primera
 * que ve cualquiera, y la que más texto apila cuando la letra es muy grande.
 */
const RUTAS_INVITADO = ['/login', '/register'];
const RUTAS = ['/dashboard', '/pacientes', '/settings/appearance'];
const ANCHOS = [320, 360, 414];
const TAMANIOS = ['normal', 'grande', 'muy-grande'];

let problemas = 0;

const navegador = await puppeteer.launch({
    executablePath: CHROME,
    headless: 'new',
    args: ['--no-sandbox'],
});

const pagina = await navegador.newPage();

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

async function iniciarSesion() {
    await pagina.setViewport({ width: 390, height: 844 });

    if (!(await ingresar(pagina))) {
        console.error(
            `No se pudo iniciar sesión con ${EMAIL}. Pasá EMAIL y PASSWORD, o creá ese usuario.`,
        );
        await navegador.close();
        process.exit(2);
    }
}

async function revisarDesborde(rutas, etiqueta) {
    console.log(`== Desborde horizontal (${etiqueta})`);

    const fallas = [];

    for (const [orientacion, apaisado] of [
        ['vertical', false],
        ['apaisado', true],
    ]) {
        for (const ancho of ANCHOS) {
            await pagina.setViewport({
                width: apaisado ? 844 : ancho,
                height: apaisado ? ancho : 844,
            });

            for (const tamanio of TAMANIOS) {
                await pagina.setCookie({
                    name: 'tamanio_texto',
                    value: tamanio,
                    url: BASE,
                });

                for (const ruta of rutas) {
                    await pagina.goto(BASE + ruta, {
                        waitUntil: 'networkidle0',
                    });

                    const dato = await pagina.evaluate(() => {
                        const exceso =
                            document.documentElement.scrollWidth -
                            window.innerWidth;

                        if (exceso <= 0) {
                            return { exceso: 0, culpable: null };
                        }

                        for (const el of document.querySelectorAll('*')) {
                            const caja = el.getBoundingClientRect();

                            if (
                                caja.right > window.innerWidth + 1 &&
                                caja.width > 0
                            ) {
                                return {
                                    exceso,
                                    culpable: `${el.tagName} "${(el.innerText || '').trim().slice(0, 30)}"`,
                                };
                            }
                        }

                        return { exceso, culpable: '(no identificado)' };
                    });

                    if (dato.exceso > 0) {
                        fallas.push({
                            orientacion,
                            ancho,
                            tamanio,
                            ruta,
                            exceso: `${dato.exceso}px`,
                            culpable: dato.culpable,
                        });
                    }
                }
            }
        }
    }

    const total = rutas.length * ANCHOS.length * TAMANIOS.length * 2;

    if (fallas.length === 0) {
        console.log(`   sin desborde en ${total} combinaciones\n`);

        return;
    }

    console.table(fallas);
    problemas += fallas.length;
}

async function revisarMenu() {
    console.log('== Menú al navegar');

    const estado = () =>
        pagina.evaluate(() => {
            const estilo = getComputedStyle(document.body);
            const sheet = document.querySelector('[data-mobile="true"]');

            return {
                abierto: !!sheet && sheet.getBoundingClientRect().width > 0,
                bloqueado:
                    estilo.overflow === 'hidden' ||
                    estilo.pointerEvents === 'none',
            };
        });

    await pagina.setViewport({ width: 390, height: 844 });
    await pagina.goto(`${BASE}/dashboard`, { waitUntil: 'networkidle0' });

    await pagina.evaluate(() =>
        document.querySelector('header button')?.click(),
    );
    await new Promise((r) => setTimeout(r, 600));

    if (!(await estado()).abierto) {
        console.log('   ✗ no se pudo abrir el menú: revisá el selector\n');
        problemas++;

        return;
    }

    await pagina.evaluate(() =>
        document
            .querySelector('[data-mobile="true"] a[href*="settings"]')
            ?.click(),
    );
    await new Promise((r) => setTimeout(r, 1500));

    const despues = await estado();

    console.log(
        despues.abierto
            ? '   ✗ el menú quedó abierto tapando la pantalla nueva'
            : '   ✓ el menú se cerró al navegar',
    );
    console.log(
        despues.bloqueado
            ? '   ✗ el body quedó con el scroll bloqueado'
            : '   ✓ el body responde',
    );

    if (despues.abierto || despues.bloqueado) {
        problemas++;
    }

    // En escritorio la barra es fija: cerrarla al navegar dejaría sin menú.
    await pagina.setViewport({ width: 1280, height: 800 });
    await pagina.goto(`${BASE}/dashboard`, { waitUntil: 'networkidle0' });
    await pagina.evaluate(() =>
        document.querySelector('[data-slot="sidebar"] a')?.click(),
    );
    await new Promise((r) => setTimeout(r, 1200));

    const anchoBarra = await pagina.evaluate(
        () =>
            document
                .querySelector('[data-slot="sidebar"]')
                ?.getBoundingClientRect().width ?? 0,
    );

    console.log(
        anchoBarra > 0
            ? `   ✓ en escritorio la barra sigue visible (${Math.round(anchoBarra)}px)\n`
            : '   ✗ en escritorio la barra se cerró al navegar\n',
    );

    if (anchoBarra === 0) {
        problemas++;
    }
}

await revisarDesborde(RUTAS_INVITADO, 'sin sesión');
await iniciarSesion();
await revisarDesborde(RUTAS, 'con sesión');
await revisarMenu();
await navegador.close();

console.log(problemas === 0 ? 'Todo en orden.' : `${problemas} problema(s).`);
process.exit(problemas === 0 ? 0 : 1);
