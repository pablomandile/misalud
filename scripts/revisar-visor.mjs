/*
 * Verificación del visor de documentos en un Chrome real.
 *
 * Es el único lugar donde se ve si pdf.js quedó bien: ningún test de PHP
 * renderiza nada, y el modo de falla típico de un visor de PDF es una pantalla
 * en blanco sin ningún error.
 *
 * Sube un PDF de dos páginas armado acá mismo -con xref y offsets válidos- y
 * comprueba que se guarde, que se liste, que pdf.js lo DIBUJE de verdad (mira
 * los píxeles del canvas, no que el canvas exista), que reconozca las dos
 * páginas, que la X propia llegue a 44 px y que cierre.
 *
 * ⚠️ NO alcanza con esperar a que el canvas tenga ancho: un <canvas> mide
 * 300x150 por defecto, así que esa condición es verdadera desde el primer
 * instante y la espera no espera nada. Se espera a que desaparezca el cartel
 * de "Abriendo el documento". Costó un rato descubrirlo.
 *
 * ⚠️ Esto NO reemplaza probarlo en un iPhone real. El caso que define al visor
 * es iOS Safari, donde un PDF en <iframe> muestra la primera página o nada y
 * no da ningún error. Chrome headless no puede decir nada sobre eso.
 *
 * Uso:
 *   node scripts/revisar-visor.mjs
 *   BASE=http://localhost:8001 EMAIL=... PASSWORD=... node scripts/revisar-visor.mjs
 */
import { writeFileSync, unlinkSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const { default: puppeteer } = await import('puppeteer-core');

const CHROME = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const BASE = process.env.BASE ?? 'http://localhost:8001';
const EMAIL = process.env.EMAIL ?? 'prueba@misalud.test';
const PASSWORD = process.env.PASSWORD ?? 'prueba-1234';

let problemas = 0;
const decir = (ok, texto) => {
    if (!ok) problemas++;
    console.log(`   ${ok ? '✓' : '✗'} ${texto}`);
};

/** Un PDF de 2 paginas, valido de verdad: con xref y offsets correctos. */
function pdfDeDosPaginas() {
    const objetos = [
        '<</Type/Catalog/Pages 2 0 R>>',
        '<</Type/Pages/Kids[3 0 R 4 0 R]/Count 2>>',
        '<</Type/Page/Parent 2 0 R/MediaBox[0 0 300 300]/Contents 5 0 R/Resources<</Font<</F1 7 0 R>>>>>>',
        '<</Type/Page/Parent 2 0 R/MediaBox[0 0 300 300]/Contents 6 0 R/Resources<</Font<</F1 7 0 R>>>>>>',
        null, // 5: contenido pagina 1
        null, // 6: contenido pagina 2
        '<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>',
    ];

    const flujo = (texto) => {
        const cuerpo = `BT /F1 28 Tf 30 150 Td (${texto}) Tj ET`;
        return `<</Length ${cuerpo.length}>>stream\n${cuerpo}\nendstream`;
    };

    objetos[4] = flujo('Pagina UNO');
    objetos[5] = flujo('Pagina DOS');

    let pdf = '%PDF-1.4\n';
    const offsets = [];

    objetos.forEach((cuerpo, i) => {
        offsets.push(pdf.length);
        pdf += `${i + 1} 0 obj\n${cuerpo}\nendobj\n`;
    });

    const inicioXref = pdf.length;
    pdf += `xref\n0 ${objetos.length + 1}\n0000000000 65535 f \n`;
    for (const o of offsets) {
        pdf += `${String(o).padStart(10, '0')} 00000 n \n`;
    }
    pdf += `trailer\n<</Size ${objetos.length + 1}/Root 1 0 R>>\nstartxref\n${inicioXref}\n%%EOF\n`;

    return Buffer.from(pdf, 'latin1');
}

const rutaPdf = join(tmpdir(), 'misalud-visor.pdf');
writeFileSync(rutaPdf, pdfDeDosPaginas());

const navegador = await puppeteer.launch({
    headless: 'new',
    executablePath: CHROME,
    args: ['--no-sandbox'],
});
const pagina = await navegador.newPage();
pagina.on('pageerror', (e) =>
    console.log('   JS ERROR:', e.message.slice(0, 300)),
);
pagina.on('console', (m) => {
    if (m.type() !== 'info') console.log('   CONSOLE:', m.text().slice(0, 300));
});
await pagina.setViewport({ width: 390, height: 844 });

// --- ingresar ---
await pagina.goto(`${BASE}/login`, { waitUntil: 'networkidle0' });
await pagina.type('input[name="email"]', EMAIL);
await pagina.type('input[name="password"]', PASSWORD);
await pagina.click('[data-test="login-button"]');
await pagina
    .waitForFunction(() => !location.pathname.startsWith('/login'), {
        timeout: 10000,
    })
    .catch(() => {});

if (pagina.url().includes('/login')) {
    console.error('No se pudo iniciar sesion.');
    await navegador.close();
    process.exit(2);
}

console.log('== Subida y visor');

await pagina.goto(`${BASE}/pacientes`, { waitUntil: 'networkidle0' });

// Asegurar que haya al menos un paciente: la base de desarrollo puede estar vacia.
const sinPacientes = await pagina.evaluate(() =>
    document.body.innerText.includes('Todavia no agregaste'.replace('i', 'í')),
);

if (sinPacientes) {
    await pagina.evaluate(async () => {
        const c = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
        await fetch('/pacientes', {
            method: 'POST',
            headers: {
                'X-XSRF-TOKEN': decodeURIComponent(c[1]),
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            body: JSON.stringify({ nombre: 'Paciente de Prueba' }),
            redirect: 'manual',
        });
    });
    await pagina.goto(`${BASE}/pacientes`, { waitUntil: 'networkidle0' });
    console.log('   (se creo un paciente de prueba)');
}

// Abrir el panel de documentos del primer paciente.
const abrio = await pagina.evaluate(() => {
    const b = [...document.querySelectorAll('button')].find(
        (x) =>
            (x.innerText || '').includes('Documentos') ||
            !!x
                .querySelector('span.sr-only')
                ?.textContent?.includes('Documentos'),
    );
    b?.click();
    return !!b;
});
decir(abrio, 'se abre el panel de documentos');
await new Promise((r) => setTimeout(r, 700));

// Subir el PDF.
const input = await pagina.$('input[type="file"]');
if (!input) {
    decir(false, 'hay un input de archivo en el panel');
} else {
    await input.uploadFile(rutaPdf);
    await new Promise((r) => setTimeout(r, 400));

    const listado = await pagina.evaluate(() =>
        document.body.innerText.includes('misalud-visor.pdf'),
    );
    decir(listado, 'la vista previa muestra el archivo elegido');

    await pagina.evaluate(() => {
        const b = [...document.querySelectorAll('button[type="submit"]')].find(
            (x) => (x.innerText || '').trim() === 'Subir',
        );
        b?.click();
    });
    await new Promise((r) => setTimeout(r, 2500));
}

// Reabrir el panel (la pagina se recargo tras el submit) y abrir el visor.
await pagina.evaluate(() => {
    const b = [...document.querySelectorAll('button')].find(
        (x) =>
            !!x
                .querySelector('span.sr-only')
                ?.textContent?.includes('Documentos'),
    );
    b?.click();
});
await new Promise((r) => setTimeout(r, 700));

const guardado = await pagina.evaluate(() =>
    document.body.innerText.includes('misalud-visor.pdf'),
);
decir(guardado, 'el documento quedo guardado y listado');

await pagina.evaluate(() => {
    const b = [...document.querySelectorAll('button')].find((x) =>
        (x.innerText || '').includes('misalud-visor.pdf'),
    );
    b?.click();
});

// pdf.js tarda: hay que bajar el worker y renderizar.
await pagina
    .waitForFunction(
        () => {
            /*
             * NO sirve mirar `c.width > 0`: un <canvas> mide 300x150 por
             * defecto, asi que esa condicion es verdadera desde el primer
             * instante y la espera no espera nada. Lo que delata que pdf.js
             * termino es que se haya ido el cartel de "Abriendo el documento".
             */
            const t = document.body.innerText;
            return !t.includes('Abriendo el documento');
        },
        { timeout: 90000 },
    )
    .catch(() => {});

const visor = await pagina.evaluate(() => {
    const canvas = document.querySelector('canvas');
    const cerrar = [...document.querySelectorAll('button')].find(
        (b) => b.getAttribute('aria-label') === 'Cerrar el documento',
    );
    const cajaCerrar = cerrar?.getBoundingClientRect();

    // Cuantos pixeles del canvas NO son transparentes: si es 0, se renderizo en blanco.
    let pintados = 0;
    if (canvas) {
        const ctx = canvas.getContext('2d');
        const d = ctx?.getImageData(0, 0, canvas.width, canvas.height).data;
        if (d) for (let i = 3; i < d.length; i += 4) if (d[i] > 0) pintados++;
    }

    return {
        hayCanvas: !!canvas,
        ancho: canvas?.width ?? 0,
        alto: canvas?.height ?? 0,
        pintados,
        hayCerrar: !!cerrar,
        altoCerrar: Math.round(cajaCerrar?.height ?? 0),
        anchoCerrar: Math.round(cajaCerrar?.width ?? 0),
        paginacion: document.body.innerText.includes('1 de 2'),
        desborde: document.documentElement.scrollWidth - window.innerWidth,
    };
});

decir(
    visor.hayCanvas && visor.ancho > 0,
    `pdf.js dibujo el PDF (${visor.ancho}x${visor.alto})`,
);
decir(
    visor.pintados > 0,
    `el canvas NO quedo en blanco (${visor.pintados} pixeles pintados)`,
);
decir(visor.paginacion, 'reconoce las 2 paginas del PDF');
decir(visor.hayCerrar, 'hay una X propia para cerrar');
decir(
    visor.altoCerrar >= 44 && visor.anchoCerrar >= 44,
    `la X mide ${visor.anchoCerrar}x${visor.altoCerrar}px (minimo 44)`,
);
decir(
    visor.desborde <= 0,
    `sin desborde horizontal con el visor abierto (${visor.desborde}px)`,
);

// Cerrar con la X.
await pagina.evaluate(() => {
    const b = [...document.querySelectorAll('button')].find(
        (x) => x.getAttribute('aria-label') === 'Cerrar el documento',
    );
    b?.click();
});
await new Promise((r) => setTimeout(r, 600));

const cerrado = await pagina.evaluate(() => !document.querySelector('canvas'));
decir(cerrado, 'la X cierra el visor');

// Captura solo si la piden: por defecto no deja archivos sueltos en el repo.
if (process.argv[2]) {
    await pagina.screenshot({ path: process.argv[2] });
}

unlinkSync(rutaPdf);
await navegador.close();

console.log(
    problemas === 0 ? '\nTodo en orden.' : `\n${problemas} problema(s).`,
);
process.exit(problemas === 0 ? 0 : 1);
