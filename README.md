<p align="center">
  <img src="public/img/mi-salud-logo-nombre.webp" alt="MiSalud" width="360">
</p>

<p align="center">
  <strong>Tu historia clínica y la de tu familia, en un solo lugar.</strong><br>
  Estudios, tratamientos, turnos, vacunas, mediciones, la credencial de la obra social
  y las recetas que llegan por mail, siempre a mano y desde el celular.
</p>

---

## Qué es

MiSalud es una app web instalable (PWA) para llevar la historia clínica personal y familiar.
Una misma cuenta administra varias fichas —la propia, la de los hijos, la de un padre— y cada
ficha se puede compartir con otra persona, para que la vea o para que ayude a cargarla.

Está pensada para **personas mayores** y para quien las cuida, y eso manda sobre todo el diseño:
letra grande por defecto (con tres tamaños a elección), áreas táctiles de 44 px, menú con
nombres escritos y no íconos sueltos, y una interfaz que se puede rotar y usar con una mano.

**MiSalud registra, no aconseja.** Muestra lo que se cargó, con su rango de referencia cuando lo
tiene, pero nunca dice si un valor "está mal": interpretar es del médico.

## Qué hace

**La historia clínica**

- Fichas de pacientes con cobertura médica y la **credencial a un toque**, visible incluso sin
  señal, para mostrarla en un mostrador.
- Enfermedades con su bitácora, alergias, tratamientos, vacunas aplicadas (con aviso de la
  próxima dosis) y consultas, con la **grabación de lo que dijo el médico**.
- Órdenes de estudio ("qué me falta hacerme") y estudios con sus resultados, con la evolución de
  cada valor en un gráfico.
- Mediciones (peso, presión, glucemia y las que se definan), con gráficos de evolución y el IMC.
- Salud ocular: recetas de anteojos con un diagrama de cada ojo y la transposición del cilindro.
- Un visor a pantalla completa para PDF e imágenes, que anda también en iPhone.

**Lo que trabaja solo**

- **Recordatorios por mail** de turnos, fin de tratamientos y próximas dosis.
- **Importación de recetas** desde una casilla de correo (IMAP), con una bandeja que ordena las
  disponibles por la que vence primero.

**Compartir y enviar**

- Invitar a otra persona a una ficha, como lectora o como cuidadora.
- Mandar documentos (una orden con la credencial, una receta) a una farmacia, una óptica o una
  obra social, desde una libreta de contactos y con historial de lo enviado.

**Privacidad**

- Todo el contenido clínico se guarda **cifrado**, en la base y en el disco.
- Descargar todos los datos en un ZIP y eliminar la cuenta de verdad, desde el perfil.
- Términos, política de privacidad (Ley 25.326) y aviso de cookies escritos para esta app.

## Tecnología

| Capa          | Qué se usa                                                                  |
| ------------- | --------------------------------------------------------------------------- |
| Backend       | Laravel 13 · PHP 8.4 · MySQL 8                                              |
| Frontend      | Inertia 3 · Vue 3 + TypeScript · Tailwind 4 · shadcn-vue (reka-ui) · Vite   |
| Autenticación | Fortify (verificación de mail, dos pasos, llaves de acceso) · Google        |
| Rutas tipadas | Wayfinder                                                                   |
| Gráficos      | Chart.js                                                                    |
| Documentos    | pdf.js                                                                      |
| Correo        | IMAP con `webklex/laravel-imap` (sin `ext-imap`), SMTP para los avisos      |
| PWA           | Service worker propio, sin `vite-plugin-pwa`                                |
| Calidad       | Pest 4 · PHPStan nivel 7 (Larastan) · Pint · vue-tsc · ESLint/Prettier (vp) |

Sin Pinia ni Vue Router: el estado viaja en las props de Inertia y las rutas las define Laravel.
Todo el código de dominio —tablas, modelos, rutas, servicios— está en español.

### Decisiones que definen el código

- **Cifrado con índices ciegos.** El contenido clínico usa el cast `encrypted` de Laravel, así que
  sobre esas columnas no hay `WHERE`, `ORDER BY` ni `UNIQUE` posibles. Donde hace falta buscar o
  impedir un duplicado hay una columna paralela con un HMAC, y una guardia
  (`ConsultaVigilada`) rechaza en tiempo de ejecución cualquier consulta sobre una columna
  cifrada, que de otro modo devolvería cero filas sin ningún error.
- **La autorización pasa por un pivote** (`paciente_usuario`, con el rol de cada persona), nunca
  comparando dueños a mano: es lo que permite compartir una ficha sin reescribir ninguna regla.
- **Todo en UTC**, con la zona horaria de cada cuenta aplicada al mostrar.
- **Los recordatorios son dato derivado**: los generan observers idempotentes, no hay ninguna
  ruta que los cree.

El detalle de cada decisión, con su porqué y las trampas que se encontraron en el camino, está en
[`CLAUDE.md`](CLAUDE.md).

## Correrlo en local

**Requisitos:** PHP 8.4, Composer, Node 22, MySQL 8.

```bash
git clone https://github.com/pablomandile/misalud.git
cd misalud

composer setup          # dependencias, .env, APP_KEY, migraciones y build

# las semillas compartidas: medicamentos, vacunas y variables de uso común
php artisan db:seed --class="Database\Seeders\CatalogosSeeder"

composer dev            # el servidor y Vite, juntos
```

Antes de `composer setup`, completá en el `.env` los datos de tu base MySQL.

- **El correo sale al log** en local (`MAIL_MAILER=log`): los avisos, las invitaciones y los
  envíos quedan enteros en `storage/logs/laravel.log`.
- **El ingreso con Google es opcional.** Sin `GOOGLE_CLIENT_ID` y `GOOGLE_CLIENT_SECRET` el botón
  no aparece y la app funciona igual. Para encenderlo, ver [`docs/google-oauth.md`](docs/google-oauth.md).
- **La `APP_KEY` cifra todos los datos.** Si se pierde, no se recuperan: guardala aparte de
  cualquier copia de la base.

## Comandos

```bash
composer ci:check            # todo lo que corre el CI: formato, lint, tipos, PHPStan y tests
php artisan test             # los tests (Pest)
npm run check:fix            # formato y lint del frontend
npm run types:check          # vue-tsc

npm run revisar:mobile       # desborde y áreas táctiles en Chrome, a varios anchos y tamaños de letra
npm run revisar:pwa          # que Chrome la dé por instalable
npm run revisar:visor        # que pdf.js dibuje un PDF de verdad
npm run generar:iconos       # íconos de la PWA y favicons desde public/img/

php artisan misalud:enviar-recordatorios --seco   # qué avisos saldrían, sin mandar nada
php artisan misalud:sincronizar-recetas --seco    # qué recetas entrarían, sin guardar nada
php artisan misalud:vaciar-papelera --seco        # qué se borraría de la papelera
php artisan misalud:recifrar --seco               # rotar la APP_KEY (ensayo)
```

Los scripts `revisar:*` usan un Chrome instalado (`puppeteer-core`) y un usuario de prueba local.

## En producción

La app corre en un hosting compartido, sin Node en el servidor: el frontend se compila en local y
se sube `public/build`. Lo que necesita el servidor:

- PHP 8.4 y MySQL 8, y subidas de al menos 64 MB (las grabaciones de las consultas).
- Un cron que corra `php artisan schedule:run` cada minuto: manda los recordatorios, importa las
  recetas, cierra los tratamientos vencidos y vacía la papelera.
- Un mailer SMTP real, y HTTPS (la PWA no se instala sin él).
- Después de cada deploy: `migrate --force`, `config:cache` y `route:cache`.

No hace falta un worker de cola: nada se encola, a propósito.

## Privacidad

Los textos que ve quien usa la app están en [`resources/legales/`](resources/legales/): los
[términos y condiciones](resources/legales/terminos.md) y la
[política de privacidad](resources/legales/privacidad.md). Cada cosa que prometen está sostenida
por código y atada por un test.

## Licencia

MIT.
