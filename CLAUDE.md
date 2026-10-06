# MiSalud — convenciones del proyecto

Historia clínica personal y familiar: médicos, centros, cobertura médica, enfermedades,
tratamientos, estudios con valores graficables, órdenes de estudio, salud ocular, vacunas,
alergias, turnos y seguimiento de variables (peso, presión, altura). Más dos módulos propios:
importar las recetas que llegan por mail e reenviar documentación a una farmacia u obra social.

**La usan personas mayores.** No es un detalle de estilo: manda sobre el tamaño de letra por
defecto, sobre poder rotar el celular y sobre el menú hamburguesa en vez de una barra de
íconos chicos.

El plan de trabajo por etapas vive fuera del repo. Ante una discrepancia entre ese plan y
este archivo, **manda este archivo**: recoge lo que efectivamente se decidió al implementar.

> **El repo es público.** Acá no van host, usuario ni puerto de SSH, ni credenciales, ni
> ejemplos con datos reales de nadie. Los datos de conexión al hosting viven en la skill
> `deploy-hostinger`, a nivel usuario.

## Stack

Laravel 13 · PHP 8.4 · MySQL 8 · Inertia 3 · Vue 3 + TypeScript · Tailwind 4 · Vite
Auth por **Fortify** (2FA y passkeys ya cableados por el starter kit).
Rutas tipadas con **Wayfinder**, no con Ziggy.
Tests con **Pest 4** (PHPUnit 12 — no subir a Pest 5, que exige PHPUnit 13 y rompe el kit).
PHPStan nivel 7 con Larastan.
IMAP con **`webklex/laravel-imap`**, con su `config/imap.php` **sin publicar** a propósito
(ver "Casilla de recetas"). No hace falta `ext-imap`: el paquete habla el protocolo por sockets.

Sin Pinia ni Vue Router: el estado viaja en props de Inertia y las rutas las define Laravel.

> El `laravel/vue-starter-kit` de Packagist está **desactualizado** (Laravel 12, Inertia 2,
> Ziggy, sin Fortify). Este proyecto se armó clonando la rama principal del repo de GitHub.
> Si hay que rearmar el andamiaje, no uses `composer create-project`.

## Idioma

- Todo el texto visible va en **español rioplatense, con voseo**. Nada de "usted".
- Tablas, columnas, modelos, rutas y servicios **en español**.
- **Excepción:** `users` y sus columnas base (`name`, `email`, `password`) quedan como las
  genera el starter kit. Las tablas de dominio usan `usuario_id` apuntando a `users.id`.
- No hay capa de i18n en el frontend y no conviene agregarla: con un solo idioma, hardcodear
  español es consistente con el resto del código de dominio.
- `laravel-lang` traduce en "usted". Si se regeneran los `lang/es`, hay que volver a pasar
  `auth.php`, `passwords.php` y los strings de `es.json` a voseo.

## Fechas y zona horaria

- Se persiste **siempre en UTC**. `config/app.php` tiene `timezone` fijo en `'UTC'`.
- Cada usuario tiene su `users.zona_horaria`; la conversión ocurre al mostrar y al evaluar
  recordatorios. **No cambiar el timezone global**: rompe ese diseño.
- La conversión vive en `User` (`zona()`, `aUtc()`, `enSuZona()`, `ahora()`, `hoy()`,
  `hoyCalendario()`), no desperdigada por controladores. Un `datetime-local` del navegador
  llega sin zona: es la del usuario, y `aUtc()` es lo que la aplica.
- **`hoy()` es para comparar contra `datetime`; `hoyCalendario()` contra `date`.** Un instante
  con zona contra una columna `date` (que Carbon lee a medianoche UTC) se corre tres horas, y
  "mañana" se lee como "hoy". Es el error más fácil de cometer y no da ningún síntoma hasta
  que alguien mira la fecha.
- `dayjs` en el front, Carbon en el back. Carbon sigue solo a `app()->getLocale()`: no hace
  falta `Carbon::setLocale()`.

> **Esta sección describió durante cinco etapas algo que no existía.** La columna y los seis
> métodos se escribieron recién en el paso 6.1, porque `mediciones.fecha` es el **primer
> `datetime` que carga una persona**: hasta ahí, todo lo que tenía fecha era una columna
> `date` (nacimiento, vigencia de una cobertura) o un `timestamp` del servidor, y ninguna de
> las dos tiene hora local que corregir. Vale como recordatorio de que lo escrito acá dice lo
> que se decidió, no siempre lo que ya está hecho: cuando una sección describa una pieza,
> conviene abrirla antes de apoyarse en ella.

- **`aUtc()` al guardar, `enSuZona()` al mostrar**, y las dos mitades en el mismo viaje. Lo
  que manda un `datetime-local` no trae zona: guardarlo tal cual corre el registro tantas
  horas como diga el huso, sin ningún síntoma hasta que alguien mira la hora.
- La conversión de una medición vive en `MedicionGuardarRequest::fechaEnUtc()` y no en el
  controlador: así es imposible olvidarla en una acción nueva.
- **Lo que precarga el formulario también sale del servidor** (`ahoraLocal`), no de
  `new Date()`. Si el celular está en otra zona que la cuenta, precargar con el reloj del
  navegador escribe una hora que el servidor después reinterpreta en la zona de la cuenta:
  la medición queda corrida y nada lo avisa.
- Una zona inválida cae al default en vez de tirar un 500: una columna editada a mano no
  puede dejar a alguien afuera de toda pantalla con fechas.
- **No hay pantalla para cambiar la zona horaria todavía.** La columna tiene default
  (`America/Argentina/Buenos_Aires`) y se respeta en todo el código; elegirla queda para
  cuando haga falta de verdad.

## Cifrado — la restricción que manda sobre el esquema

Se cifra **todo el contenido clínico** con el cast `encrypted` de Laravel. Eso trae una
consecuencia que define el esquema entero:

> **El encrypter usa un IV aleatorio: el mismo texto produce un ciphertext distinto cada
> vez.** No es que buscar sea lento — `WHERE`, `UNIQUE`, `ORDER BY`, `LIKE` e índices **no
> pueden existir** sobre esas columnas.

### Qué se cifra y qué no

| Se cifra (cast `encrypted`)                    | Queda en claro                                   |
| ---------------------------------------------- | ------------------------------------------------ |
| Nombres, notas, descripciones, diagnósticos    | FKs (`paciente_id`, `medico_id`, …)              |
| Valores de mediciones, estudios y graduaciones | Fechas y `timestamps`                            |
| Dosis, frecuencia, indicaciones                | Enums y estados (`activa`, `usada`, `pendiente`) |
| Remitente y asunto de las recetas              | Columnas `*_hash`                                |
| Credenciales de la casilla IMAP                | `deleted_at`, contadores                         |

Las fechas quedan en claro **a propósito**: son la columna por la que ordenan la línea de
tiempo, los gráficos de evolución y el paginado. Cifrarlas convertiría cada listado en "traer
todo a memoria y ordenar en PHP".

### Columnas de hash (índice ciego)

Donde hace falta unicidad o búsqueda exacta va una columna paralela determinística:

```php
hash_hmac('sha256', mb_strtolower(trim($valor)), config('app.key'))   // char(64), indexada
```

El trait `CifraCampos` los declara y los llena en el evento `saving`. **Un solo lugar**: no
calcular hashes a mano en un controlador, o el índice se desincroniza del dato y el UNIQUE
deja de proteger.

La clave del HMAC **no es `APP_KEY` directo**: se deriva con HKDF y una etiqueta propia.
Cifrar e indexar son dos propósitos y no comparten clave.

### Cómo se arma un modelo que cifra

```php
class Medico extends Model implements CifraDatos
{
    use CifraCampos;

    // Obligatorio. No puede vivir en el trait: PHP no deja que un trait pise una
    // propiedad heredada con otro valor. Lo exige GuardiaDeCifradoTest.
    protected static string $builder = ConsultaVigilada::class;

    public function indicesCiegos(): array
    {
        return ['nombre' => 'nombre_hash'];
    }

    protected function casts(): array
    {
        return ['nombre' => 'encrypted'];
    }
}
```

Las tres piezas y para qué está cada una:

| Pieza                                    | Qué hace                                                       |
| ---------------------------------------- | -------------------------------------------------------------- |
| `App\Contracts\CifraDatos`               | El contrato. Lo consultan el comando de recifrado y la guardia |
| `App\Concerns\CifraCampos`               | Calcula los hashes en `saving` y aporta `dondeIndiceCiego()`   |
| `App\Database\Eloquent\ConsultaVigilada` | **Rechaza** un `where` o un `orderBy` sobre columna cifrada    |

`ConsultaVigilada` es la pieza que más vale. Sin ella, `Medico::where('nombre', 'Pérez')`
compila, corre y devuelve **cero filas, siempre**, sin ningún error: el ciphertext guardado
nunca es igual al texto buscado. El síntoma se lee como "no hay datos" y no como "esta
consulta es imposible". La salida válida es `dondeIndiceCiego('nombre', 'Pérez')`.

### Rotar la APP_KEY

```bash
# 1. copiar la APP_KEY actual a APP_PREVIOUS_KEYS en el .env
# 2.
php artisan key:generate
# 3.
php artisan misalud:recifrar        # --seco para ver qué haría
# 4. recién ahora, sacar la clave vieja de APP_PREVIOUS_KEYS
```

Entre 2 y 3 la app sigue funcionando —el encrypter prueba las claves previas al descifrar—
pero **los índices ciegos no**: se calculan con la clave nueva y ya no coinciden con los
guardados. Por eso el comando recalcula las dos cosas, y por eso conviene correrlo enseguida.

El comando escribe por el query builder y no con `save()`: un `save()` dispararía los
observers por un cambio que no es del dominio, y además el dirty-check de Eloquent compara
los valores **descifrados**, así que un texto que no cambió no se marcaría sucio y no se
reescribiría nunca.

Hacen falta en `recetas.message_id_hash` (unique — es lo único que evita reimportar el mismo
mail), en el `nombre_hash` de cada catálogo (unique por `usuario_id`) y en
`contactos.email_hash`.

### Reglas que se siguen de esto

- **Las columnas cifradas son `text`**, nunca `varchar`. Medido sobre este proyecto: el
  payload es JSON+base64 y cuesta **~190 bytes fijos más ~1.8× el original**. Un campo de
  10 caracteres ocupa 200 bytes; uno de 200 ocupa 544. Un `TEXT` (64 KB) aguanta unos
  **36 KB de texto en claro**, que es el techo real de una nota larga.
- **Buscar y ordenar se hace en PHP**, no en SQL. Los catálogos de una persona son decenas de
  filas: se traen todas las del usuario y se filtran en memoria. No hay búsqueda por texto
  dentro de las notas.
- **Los gráficos se arman en PHP.** `chart.js` recibe los puntos ya desencriptados como props;
  min, max y promedio se calculan en la colección, no con `AVG()`.
- **`APP_KEY` es la llave de todo.** Si se pierde, los datos no se recuperan — y además
  desencripta el `two_factor_secret` de Fortify. Va al backup, guardada aparte de la base, y
  el `.env` con permisos restringidos. `misalud:recifrar` es lo que permite rotarla.
- `GuardiaDeCifradoTest` revisa el esquema real de cada modelo que implementa `CifraDatos`:
  que no haya índices sobre columnas cifradas, que esas columnas sean `text`, que la columna
  de hash exista y esté indexada, y que el modelo declare `$builder`. Es exactamente el tipo
  de cosa que alguien "simplifica" en seis meses.
- **Los archivos también se cifran en disco** (`ArchivoService`). Se paga con la pérdida de
  _range requests_ y con desencriptar el archivo entero en memoria al servirlo: para PDFs y
  fotos de consultorio es irrelevante, pero fija un límite de subida razonable.
- **Una sola `APP_KEY`, no una clave por usuario.** Es lo que permite compartir una ficha sin
  repartir claves.

## Backend

- Un **FormRequest por acción de escritura**. Nada de validación en el controlador.
- **Policy en todo modelo del dominio**; `$this->authorize()` siempre.
- La autorización de un paciente pasa **por el pivote `paciente_usuario`**, nunca comparando
  `usuario_id` a mano. Es lo que permite compartir la ficha sin reescribir Policies.
- `paciente_id` **no es fillable** en ningún registro clínico: es la FK de la que depende toda
  la autorización. Se crea por la relación (`$paciente->estudios()->create(...)`).
- Los **servicios** contienen la lógica de negocio; los controladores solo orquestan.
- Los **recordatorios los generan observers**, nunca un controlador. Idempotentes por
  `origen_type` + `origen_id` + `tipo`.
- Enums de PHP para todos los ENUM del esquema. **Al sumar un caso hay que ensanchar también
  el ENUM de MySQL**: los casos de PHP solos pasan los tests (sqlite no valida ENUM) y
  revientan en producción con un 500 al primer guardado.
- Todo listado con **eager loading explícito**.
- Soft deletes en las entidades principales.
- Adjuntos en el disco **privado**, servidos por controlador tras verificar propiedad. Nunca
  por URL pública.
- **`Rule::unique` sobre una columna `date` no es portable.** MySQL trunca a la fecha, pero
  SQLite —el de los tests— guarda `00:00:00` y la comparación por igualdad nunca encuentra el
  duplicado: la validación pasa y lo corta la base con un 500. Para esos casos va una regla de
  cierre con `whereDate`.
- ⚠️ **Un checkbox tildado manda el string `"on"`, y la regla `boolean` lo rechaza.** Laravel
  solo acepta `true/false/1/0/"1"/"0"`; `"on"` —lo que manda de verdad un
  `<input type="checkbox">` nativo cuando está tildado— la hace fallar. Encontrado a mano en
  el navegador con la cobertura activa (Etapa 4): la creación volvía con un 302 y ninguna fila
  nueva, **sin ningún cartel**, porque el campo no tenía su `<InputError>` en pantalla. Ni un
  solo test lo agarró: los de Pest mandaban `true` (bool de PHP) o nada, nunca el string real.
  Se normaliza en `prepareForValidation()` del FormRequest, con `$this->boolean('activa')`
  **antes** de que la regla `boolean` lo vea —no en el controlador, que ya es tarde—. Y **todo
  campo booleano lleva su `<InputError>`**, así el día que falle por otra razón, se vea.
- **Unicidad sobre una columna cifrada: `App\Rules\IndiceCiegoUnico`.** `Rule::unique` no
  detecta nada sobre un campo `encrypted` —compara contra el ciphertext, que cambia en cada
  guardado—; sin esta regla, dos registros iguales pasan la validación y el UNIQUE de la base
  los frena con un 500 en vez de un error de campo. Toma el modelo, el campo, un **ámbito** en
  claro por el que además acotar (`['paciente_id' => ...]`: la unicidad casi siempre es "para
  esta persona", no global) y el id a ignorar al editar. Arma la comparación con
  `indicesCiegos()` y `hashCiego()` de la interfaz `CifraDatos` **a mano**, sin pasar por el
  scope `dondeIndiceCiego()`: con un `class-string<Model&CifraDatos>` genérico, PHPStan no
  logra resolver un scope definido en un trait.

## Ingreso con Google

Sin `GOOGLE_CLIENT_ID` y `GOOGLE_CLIENT_SECRET` en el `.env`, **la opción no
existe**: el botón no se dibuja y las dos rutas dan 404. Es lo que permite tener
el código desplegado antes de que existan las credenciales, y lo que hace que
los tests no dependan de Google. El chequeo está en un solo lugar,
`IngresoConGoogleService::configurado()`, y lo consultan la ruta y el prop
compartido `googleHabilitado`.

| Pieza                                         | Qué hace                                                                  |
| --------------------------------------------- | ------------------------------------------------------------------------- |
| `App\Support\CuentaDeGoogle`                  | Traduce lo que devuelve Socialite. Aísla la rareza del flag de verificado |
| `App\Services\IngresoConGoogleService`        | Crea o vincula la cuenta. Toda la decisión vive acá                       |
| `App\Http\Controllers\Auth\GoogleController`  | Las dos rutas. No decide nada                                             |
| `App\Http\Middleware\ConfirmarClaveSiLaTiene` | `RequirePassword`, salteado para quien no tiene contraseña                |

Las reglas, y por qué cada una:

- **Una cuenta por email.** Si Google devuelve un email que ya existe, se vincula
  el `google_id` en vez de crear otra cuenta. Dos cuentas con el mismo email
  dejarían a alguien con dos juegos de pacientes separados, cada uno invisible
  desde el otro y sin forma de juntarlos.
- **El email tiene que venir verificado por Google.** El flag viaja en el payload
  crudo (`verified_email` o `email_verified` según el endpoint), **no** en la
  interfaz de Socialite. Si no viene, se asume que **no** está verificado: con
  uno sin verificar, cualquiera podría reclamar la cuenta de otro declarando su
  dirección.
- **Se reconoce por el `sub`, no por el email.** El email de una cuenta de Google
  se puede cambiar; el identificador no.
- **`users.password` es nullable y las cuentas de Google quedan sin contraseña.**
  Una al azar las haría figurar como que pueden entrar con email y clave.
  `Hash::check` contra un hash vacío devuelve `false`, así que no se abre ninguna
  puerta — **lo cuida un test**, porque es el punto donde un error se paga caro.
- **Donde se pide la contraseña actual, se pide solo si existe**: la pantalla de
  seguridad (`ConfirmarClaveSiLaTiene`), el cambio de contraseña y **también el
  borrado de la cuenta**. Con la regla fija, una cuenta de Google no podría
  definirse una contraseña ni eliminarse nunca: `current_password` se evalúa
  contra un hash vacío y falla siempre.
- **Cancelar en Google no es un error**: vuelve al login sin cartel rojo.
- **Los errores de Socialite van al log, no a la pantalla**: traen partes de la
  respuesta de Google.

`google_id` queda **en claro**, al revés que el contenido clínico: es la columna
por la que se busca al volver de Google, y sobre una columna cifrada ese `where`
devolvería cero filas siempre. No es un dato clínico.

### El ingreso con Google está apagado en local, a propósito

En Google Cloud Console está registrado **solo el redirect de producción**, que es
donde interesa la autenticación. Por eso el `.env` local tiene las credenciales
comentadas: con ellas puestas el botón aparece y al tocarlo Google contesta
`redirect_uri_mismatch`, que es peor que no tenerlo. Comentadas, la opción no existe
—prop en `false`, rutas en 404—, que es justo para lo que se diseñó esa degradación.

**Google no acepta el dominio `.test`**: exige `https`, salvo contra `localhost` o
`127.0.0.1`, que además no son intercambiables entre sí. Como `APP_URL` en local es
`http://misalud.test`, encenderlo acá obliga a registrar también
`http://localhost:8001/auth/google/callback` y a levantar el servidor en ese mismo
host y puerto.

⚠️ **Editar el `.env` no cambia nada en un `artisan serve` ya corriendo.** El
comando pasa las variables al proceso hijo, y phpdotenv no pisa una variable que ya
está en el entorno; con `--no-reload` tampoco se reinicia solo. El síntoma es que
parece que el cambio no se aplicó —y se busca en la caché de configuración, que no
tiene nada que ver—.

Trámite completo y cómo verificarlo sin abrir el navegador: `docs/google-oauth.md`.

## Frontend

- Páginas Inertia en **`resources/js/pages`, siempre en minúscula**. Linux distingue
  mayúsculas y un case equivocado rompe el build y los tests aunque en Windows ande.
- Reutilizar los componentes de `resources/js/components/ui/` (shadcn-vue sobre reka-ui). **No
  escribir uno nuevo sin mirar antes si existe.**
- Estado por props de Inertia + composables. Sin Pinia.
- Los valores de un formulario viajan en **inputs reales** dentro del `<Form>` de Inertia. Los
  componentes de reka-ui son botones, no inputs: los que envuelvan un valor llevan su
  `<input type="hidden">` espejo.
- **Todo formulario con archivo va por POST con `_method=put`**, nunca por PUT directo: PHP no
  parsea el cuerpo multipart de un PUT, `$request->file()` llega vacío y la imagen se pierde
  en silencio, sin error de validación ni ningún síntoma.

## Avisos al usuario: siempre en toast

**Todo mensaje —éxito, error, aviso— va en un toast** (`vue-sonner`). Nada de carteles verdes
y rojos incrustados arriba del formulario.

- El mensaje viaja en el **flash de Inertia** y se dispara **desde un solo lugar**, en el
  layout, mirando `page.props.flash`. Dispararlo desde el componente que hizo el submit no
  funciona cuando la acción termina en un redirect: el componente se desmonta antes.
- **Un `watch` sobre el flash, no un `onMounted`.** En una SPA el layout no se remonta entre
  navegaciones, así que con `onMounted` aparece el primer mensaje de la sesión y ninguno más.
- El flash necesita un **identificador que cambie en cada mensaje**. Sin eso, dos éxitos
  seguidos con el mismo texto disparan uno solo: el `watch` no ve cambio. Es el modo de falla
  que parece "a veces no avisa".
- **Los errores de validación NO van en toast**: van al lado del campo. El toast es para lo
  que no tiene un campo donde ponerse ("Se guardó el estudio", "No se pudo conectar a la
  casilla"). Un error de validación en un toast obliga a memorizar qué campo estaba mal antes
  de que se desvanezca.
- Posición: **abajo en mobile** (donde está el pulgar), arriba a la derecha en escritorio.
  Respetar `env(safe-area-inset-bottom)`.
- Los éxitos se auto-cierran a los ~4 s; **los errores no**, que son los que hay que leer dos
  veces.
- Lo implementa `useAvisos()`, llamado **una sola vez** desde `AppSidebarLayout`.
- La posición se resuelve con `useMediaQuery` y no con clases `md:`: sonner posiciona su
  lista con estilos en línea, así que una clase de Tailwind tendría que ganarle con
  `!important` y quedaría atada a la estructura interna de la librería.
- `closeButtonAriaLabel` va dentro de `toast-options`, **no** como prop del Toaster: como
  prop suelta se acepta sin error y no hace nada (queda en inglés).
- Detalle completo en la skill `overlays-al-navegar`.

## Accesibilidad — requisito, no pulido

### Tamaño de letra

Tres opciones, **con la mediana por defecto**: Normal `100%`, **Grande `112.5%`**, Muy grande
`125%`, aplicadas sobre el `font-size` de `:root`.

- **Porcentaje, nunca px.** `font-size: 20px` **pisa** el tamaño que la persona ya configuró
  en su navegador, que es justo lo que alguien mayor probablemente ya tocó. Con `%` las dos
  preferencias se multiplican en vez de pelearse.
- Tailwind mide en `rem`, así que mover la raíz escala tipografía **y** espaciado. Pero **los
  breakpoints `md:` no escalan** (las media queries van en px contra el viewport). Por eso el
  chequeo de desborde es una matriz de anchos × tamaños × orientación, no un chequeo suelto.
- **El servidor escribe `data-texto` en el `<html>`; no hace falta script inline.** Esto es
  mejor que el modo oscuro y por un motivo concreto: el modo oscuro tiene la opción "según
  el sistema", que solo el navegador sabe resolver, así que necesita JavaScript. Acá el
  servidor ya sabe la respuesta. Cero parpadeo y cero JavaScript en el arranque.
- Lo resuelve `HandleTamanioTexto`: **manda la cuenta, después la cookie**. La cookie cubre
  a quien no inició sesión y evita una consulta por request de un invitado; la columna
  `users.tamanio_texto` es la que hace que la preferencia siga a la persona a un dispositivo
  nuevo, que es el caso que la motiva.
- El cambio **sí** lo aplica el cliente (`useTamanioTexto`), porque Inertia no recarga el
  documento: sin eso, elegir un tamaño no se vería hasta la próxima navegación completa.
- Se puede cambiar **sin sesión**. Quien no llega a leer la pantalla de ingreso es justamente
  quien más necesita agrandar la letra, y ahí todavía no hay cuenta donde guardarlo.
- ⚠️ **Con sesión, poner la cookie no cambia nada.** Manda la cuenta, así que para mover
  el tamaño de un usuario logueado hay que escribir `users.tamanio_texto` —por la pantalla
  o por `PUT /tamanio-texto`—. Es una trampa para cualquier script de verificación:
  `revisar-mobile.mjs` seteaba solo la cookie y sus tres vueltas medían **exactamente lo
  mismo**, así que informaba 54 combinaciones cuando en realidad eran 18 repetidas tres
  veces, ciega a dos de los tres tamaños en toda pantalla con sesión. No daba ningún
  síntoma: informaba de más, y en verde.
- `TamanioTexto::PORDEFECTO` es la fuente única; `porDefecto()` deriva de ella. Un valor
  desconocido en la cookie cae al default en vez de romper la página.
- En Configuración va **con la muestra a tamaño real**: un selector que dice "Grande" en letra
  chica no le sirve a quien lo necesita.

### Rotar el celular

- **El manifest va con `"orientation": "any"`.** Con `"portrait"` la rotación queda bloqueada
  en la PWA instalada, y el síntoma solo aparece con la app ya instalada — nunca en el
  navegador.
- Alturas con **`100dvh`, no `100vh`**: en apaisado la barra del navegador se come una
  proporción mucho mayor y `100vh` deja contenido cortado.
- **`env(safe-area-inset-left)` y `-right`**, no solo `-bottom`. En apaisado el notch queda al
  costado. Es el que siempre falta.
- Los gráficos y los diagramas de ojos ganan mucho en apaisado: contenedor fluido y
  redibujado, no el ancho de antes.

### Navegación y tacto

- **Menú hamburguesa** en mobile, sidebar en `>= md`. Sin barra inferior de tabs: los íconos
  chicos son lo contrario de lo que esta app necesita. `lib/navegacion.ts` es la definición
  única de los dos layouts — cada etapa suma ahí sus destinos y aparecen en los dos.
- **El sheet se cierra al navegar**, con un `router.on('navigate')` en `AppSidebar.vue`. Tres
  decisiones, ninguna sobra: va en el router y no en cada `<Link>` (hay tres grupos de
  enlaces y con el séptimo alguien se olvida); **solo en mobile**, porque en escritorio la
  barra es fija y cerrarla dejaría sin menú a cada paso; y `navigate` y no `start`, porque
  con `start` una visita fallida deja sin menú y sin página, y además dispara en cualquier
  `router.reload()` de fondo.
- `NavFooter` distingue enlaces internos de externos. El starter kit lo usaba solo para
  enlaces a Laravel y mandaba todo a `target="_blank"`; un destino de la app abierto en
  pestaña nueva saca a la persona de la SPA.
- La **zona segura lateral** se aplica en `AppContent` (envuelve encabezado y páginas) y en
  el sheet del menú, que al ser fijo no hereda ese padding.
- Áreas táctiles de **44 px mínimo**, y el piso está **en las primitivas**, no en cada
  pantalla: `button`, `input`, `select`, `checkbox`, el ítem del menú lateral, el del
  desplegable y las celdas del código de 2FA. Todas con `min-h-11` (2.75rem), en `rem`
  para que crezcan con el tamaño de letra; con `px` fijos se quedarían chicas justo para
  quien agrandó la letra.
- **`sm` significa menos padding, nunca un objetivo más chico.** `sm` e `icon-sm`
  comparten el piso con los normales: un botón de 32 px en esta app es el problema, no
  una variante. Lo mismo `lg`, que llega a 48.
- El **checkbox** mantiene su caja chica y expande el área con un pseudo-elemento
  (`before:size-12`). Agrandar el cuadrito a 44 px se ve mal; lo que tiene que medir 44
  es lo que responde al toque, que no es lo mismo.
- **Un `class` en el sitio de uso le gana a la variante.** El botón del menú hamburguesa
  pedía `size="icon"` y después se lo pisaba con `h-7 w-7`: quedaba en 28 px, el control
  más chico de toda la app siendo el único camino a todas las secciones en el celular.
- Teclado correcto: `inputmode="decimal"` para valores, `type="date"`, `type="tel"`.
- **Nada que dependa solo de `hover`**: en el celular es invisible, y ahí es donde se usa.

## PWA

Service worker propio en `public/sw.js`. **No** usar `vite-plugin-pwa`: no sabe del rescate de
JSON crudo de Inertia ni del `beforeinstallprompt` capturado antes de que monte Vue.

- `beforeinstallprompt` se captura con un **script inline en el `<head>`**. Hacerlo en
  `onMounted` deja el botón sin aparecer, de forma intermitente. `usePwaInstall` solo lee lo
  que ese script guardó en `window.__pwaInstall`.
- **El botón no se esconde después de usar el prompt.** El prompt se consume una sola vez,
  incluso si la persona lo descarta; escondiéndolo, quien lo cerró sin querer no puede
  reintentar, y en una SPA una recarga completa casi no pasa. A partir del segundo toque
  muestra el instructivo del menú del navegador.
- **En iOS el botón se muestra igual**, aunque no haya prompt: Safari nunca dispara
  `beforeinstallprompt` y la instalación es manual. Condicionarlo a que exista el prompt lo
  haría desaparecer justo en el dispositivo donde más se usa la app. iPadOS se declara como
  Mac desde iOS 13: lo delata `navigator.maxTouchPoints > 1`.
- Los íconos **se generan** desde `resources/marca/*.svg` con `npm run generar:iconos`, nunca
  se editan a mano. El maskable llena el lienzo sin esquinas redondeadas —Android aplica su
  propia máscara y un ícono ya redondeado se recorta dos veces— y su cruz ocupa el 50%, bien
  adentro de la zona segura del 80%. El `apple-touch-icon` sale del maskable porque iOS
  pinta de negro cualquier transparencia.
- `cache-first` **solo** para `/build/` (tiene hash de contenido). Todo lo demás network-first.
  **Las respuestas de Inertia no se cachean**: son datos clínicos, y mostrar una dosis vieja es
  peor que mostrar un cartel de "sin conexión".
- Al cambiar un ícono hay que tocar **tres lugares**: el nombre de `CACHE` en `sw.js`, el `?v=`
  de los `<link rel="icon">` y el `?v=` del manifest.
- En producción, `sw.js` y `manifest.webmanifest` necesitan `no-cache` en `.htaccess`, o el CDN
  sirve un service worker viejo durante días y congela todo lo demás.

### La única excepción: la credencial, para verla sin señal

Pedido explícito del usuario, con la salvedad que el plan ya anticipaba: es una excepción
**acotada y explícita**, no una grieta en "los datos clínicos no se cachean".

- **La acota el servidor, no el service worker.** `GET /credenciales/{adjunto}`
  (`AdjuntoController::showCredencial`) es una ruta aparte de `/adjuntos/{adjunto}`, y
  **rechaza con 404 cualquier adjunto que no sea `TipoAdjunto::Credencial`** aunque la
  persona tenga permiso de verlo. El service worker reconoce la excepción por el
  `pathname` (`esCredencial()`), sin preguntarle nada a nadie — porque el servidor ya
  garantizó que ese camino nunca sirve otra cosa. Así, ni un bug del frontend ni una URL
  armada a mano pueden colar un documento clínico de verdad por el único camino cacheable.
- **Va en una caché aparte** (`misalud-credenciales-v1`, no `CACHE`): el `activate` que
  purga cachés viejas al subir de versión **no la toca**. Si viviera en `CACHE`, cada
  actualización de ícono borraría la foto de la credencial de alguien.
- **Network-first, no cache-first.** Un cambio de plan o una credencial nueva tiene que
  verse apenas haya señal; la copia guardada es solo el respaldo para cuando la red falla.
- **`Cache-Control: no-store` sigue en la respuesta HTTP**, igual que cualquier otro
  adjunto. Eso es la caché del NAVEGADOR (compartida por cualquier pestaña del origen, y
  la que le importa a un locutorio); lo que la guarda para verla sin señal es la Cache
  Storage API del service worker, un almacén aparte que solo esta app controla — las dos
  cosas conviven sin contradecirse.
- **Se olvida al borrarla.** Borrar una credencial no avisa sola al service worker —el
  servidor no sabe qué tiene cacheado cada navegador—, así que el cliente le manda
  `postMessage({ tipo: 'olvidar-credencial', url })` en el `@success` de cada borrado
  (`resources/js/lib/cacheCredencial.ts`), incluido el borrado en cascada de borrar la
  cobertura entera. Sin esto, una credencial vieja queda "disponible sin conexión" para
  siempre: la fila desaparece del servidor, pero una app instalada casi nunca hace la
  recarga completa que renovaría la caché sola.
- Verificado en Chrome real: se cachea al verla online, sirve desde el cache con
  `page.setOfflineMode(true)`, y desaparece del cache al borrar la cobertura.

## Adjuntos

Una sola tabla polimórfica para todos los archivos de la historia clínica. Cuelga de
todo lo que pueda tener uno —estudios, resultados, órdenes, recetas, prospectos,
prescripciones oculares, credenciales, el paciente mismo— y el `tipo` (`TipoAdjunto`)
dice para qué es. Por eso el módulo va temprano: seis etapas dependen de él.

| Pieza                                    | Qué hace                                                        |
| ---------------------------------------- | --------------------------------------------------------------- |
| `App\Services\ArchivoService`            | Guarda cifrado, descifra, borra. Lista blanca de tipos y techo  |
| `App\Models\Adjunto`                     | La fila. Resuelve su paciente subiendo por `adjuntable`         |
| `App\Concerns\TieneAdjuntos`             | La relación `morphMany`, igual en los nueve modelos que la usan |
| `App\Policies\RegistroClinicoPolicy`     | **Una sola Policy** para todo el dominio clínico                |
| `App\Http\Controllers\AdjuntoController` | Sirve y borra. Nunca hay URL pública                            |

### Qué va cifrado y qué no

El **contenido del archivo** se cifra en disco con la misma `APP_KEY`: un backup de
`storage/` sin la clave no sirve para nada. También se cifran `nombre_original` y
`descripcion`, que son contenido clínico —un `analisis-juan-perez.pdf` cuenta bastante—.

Quedan **en claro** `ruta`, `mime`, `tamanio_bytes` y `duracion_segundos`: la ruta es un
ULID aleatorio que no dice nada y es por donde hay que encontrar el archivo, y los otros
tres permiten listar y decidir cómo servir sin desencriptar nada. `tamanio_bytes` es el
del archivo **original**, no el del cifrado, que es el número que la persona reconoce.

### Lo que se paga por cifrar en disco

- **No hay _range requests_.** El archivo se sirve entero, siempre. Para un PDF de
  consultorio es irrelevante. Para **audio o video no**: no se puede adelantar, y iOS
  Safari exige `Range` para `<audio>` —sin él puede no reproducir y no avisar—. Si algún
  día entra audio, se resuelve ahí y no acá.
- **Se desencripta entero en memoria.** Entre leer, descifrar y responder se usan varias
  veces el tamaño del archivo, así que `ArchivoService::MAXIMO_BYTES` (12 MB) no es una
  formalidad: es lo que evita que un PDF grande tumbe el proceso en hosting compartido.

### Reglas que no son obvias

- **El mime sale de `getMimeType()`, nunca de `getClientMimeType()`.** El segundo lo
  manda el navegador y lo elige quien sube; el primero lo deduce del contenido con finfo.
  Confiar en el del cliente permite subir cualquier cosa diciendo que es un PDF.
- **Lista blanca de tipos, no lista negra.** Un formato nuevo nace prohibido. No hay
  `text/html` ni `image/svg+xml`: los dos ejecutan JavaScript, y estos archivos se sirven
  desde el propio dominio de la app.
- **El archivo en disco se llama `<ulid>.cif`.** Aleatorio porque el nombre original es
  contenido clínico y no tiene por qué quedar en claro en el disco; `.cif` porque el
  archivo **no** es un PDF y ponerle `.pdf` hace perder un rato a quien lo encuentre en
  un backup y no pueda abrirlo.
- La respuesta lleva `nosniff`, `Content-Security-Policy: sandbox` y `no-store`. El
  `Content-Disposition` usa el nombre ya limpio de comillas y saltos de línea: sin sanear,
  ese header permite inyectar otros.
- **Se borra primero el disco y después la fila.** Al revés, si la fila se va y el archivo
  no, queda un archivo cifrado que nada referencia: invisible y para siempre.
- `adjuntable_type` y `adjuntable_id` **no son fillable**, por lo mismo que `paciente_id`
  no lo es: de esa referencia cuelga toda la autorización. El adjunto se crea por la
  relación (`$estudio->adjuntos()->create(...)`).

### Una sola Policy para el dominio clínico

`RegistroClinicoPolicy` decide sobre cualquier modelo que implemente
`App\Contracts\PerteneceAPaciente`. Las reglas son idénticas en todos —las da el rol en
`paciente_usuario`— y lo único que cambia es cómo se llega al paciente, que lo resuelve
cada modelo. Una Policy por modelo sería la misma lógica copiada quince veces, y la
decimosexta sería la que filtre.

- El contrato pide **un método y no una relación**: hoy todos los que lo implementan
  llegan al paciente en un paso por `paciente_id`, pero dejarlo como método no cuesta
  nada y deja lugar para un registro que llegue por un camino más largo.
- **Un paciente nulo es "no", nunca "no hay nada que proteger".** Es la línea entre un
  registro huérfano inaccesible y uno abierto a cualquiera.
- `Lector` **puede ver** cualquier registro y no puede tocar ninguno. Borrar un registro
  clínico sí lo puede hacer un cuidador —tiene que poder corregir lo que cargó mal—; lo
  que queda para el propietario es dar de baja la ficha entera y el `forceDelete`.

### Los adjuntos NO usan esta Policy: delegan en su dueño

`AdjuntoPolicy` no decide nada por su cuenta. La regla entera es una línea:

> _Podés hacerle algo a un adjunto si podés hacerle lo mismo a la cosa de la que cuelga._

| El adjunto cuelga de… | Contesta…                              |
| --------------------- | -------------------------------------- |
| `Paciente`            | `PacientePolicy` (rol en el pivote)    |
| `Cobertura`           | `RegistroClinicoPolicy` (vía paciente) |
| un catálogo           | `CatalogoPolicy` (por `usuario_id`)    |

Antes esto lo resolvía `RegistroClinicoPolicy` subiendo por `adjuntable` hasta encontrar un
paciente. Andaba mientras **todo** colgara de un paciente, y se rompía con el primero que
no: el prospecto de un medicamento cuelga de un catálogo, que es del usuario, y esa cadena
devolvía `null` —o sea, lo negaba siempre—. Delegando, cada dueño contesta con su propia
Policy y esto no se vuelve a tocar al sumar un dueño nuevo.

- **Subir un archivo a X es editar X**: los controladores piden `update` sobre el dueño, no
  un permiso propio del adjunto —que todavía no existe cuando se sube—. Una sola regla para
  paciente, cobertura y catálogo.
- **Editar o borrar un archivo también pide `update` del dueño, no su `delete`**: sacarle
  una foto a una cobertura no es dar de baja la cobertura.
- **Sin dueño es "no".** Un adjunto huérfano queda inaccesible, nunca abierto.
- ⚠️ **`Gate::forUser($usuario)->allows(...)`, jamás `Gate::allows(...)` a secas.** La
  fachada sin `forUser` evalúa contra el usuario **autenticado**, que no tiene por qué ser
  el que recibió la Policy: preguntar por un tercero con una sesión abierta devolvería
  `true` —y en silencio—. Lo cuida un test.
- Sale gratis una regla correcta: a una **semilla compartida no se le puede colgar un
  archivo**, porque `CatalogoPolicy::update()` la niega. Hay que duplicarla primero.

## Catálogos

Médicos, centros, medicamentos, vacunas y `tipos_medicion`: **los cinco ya existen**. Lo que
los separa de todo el resto del dominio es una sola cosa, y de ahí sale el diseño entero:

> **Un catálogo cuelga del USUARIO, no del paciente.** El mismo médico atiende a toda la
> familia que uno administra. Duplicarlo por paciente sería cargar tres veces el mismo
> teléfono y que al cambiar de número queden dos desactualizados.

| Pieza                                         | Qué hace                                                               |
| --------------------------------------------- | ---------------------------------------------------------------------- |
| `App\Contracts\EsCatalogo`                    | El contrato. **Extiende `CifraDatos`**: el nombre siempre va cifrado   |
| `App\Concerns\DeCatalogo`                     | La implementación: `usuario()`, `esSemilla()`, `nombreVisible()`       |
| `App\Policies\CatalogoPolicy`                 | **Una sola Policy para los cinco.** Mira `usuario_id`, nunca el pivote |
| `App\Http\Controllers\CatalogoBaseController` | El `index()` completo, más los helpers de alta/edición/baja/duplicado  |

### Semillas compartidas

`usuario_id` **NULL** significa semilla compartida: la ve todo el mundo, no la edita nadie,
y quien la quiera distinta **la duplica** a su propio catálogo (regla 5). Es la única salida
frente a una semilla, y por eso `duplicar` es una acción de primera clase y no un extra.

- Dejar editar una semilla sería dejar que alguien le cambie el catálogo a desconocidos.
- ⚠️ **El UNIQUE no protege a las semillas.** `unique(usuario_id, nombre_hash)` con
  `usuario_id` NULL admite todos los NULL que quiera MySQL, así que dos semillas iguales
  entran sin chistar. El seeder de la Etapa 5.4 tiene que ser idempotente **por su cuenta**,
  sin apoyarse en la base.
- Duplicar dos veces la misma semilla **avisa** ("ya la tenés") en vez de estrellarse contra
  el UNIQUE con un 500.

### Cómo se copia el patrón (pasos 5.2 y 5.3)

Un catálogo nuevo son cinco archivos y ninguna decisión:

1. **Migración**: `usuario_id` nullable + `nombre` text + `nombre_hash` char(64) +
   `unique(usuario_id, nombre_hash)` + `index(usuario_id)`.
2. **Modelo**: `implements CifraDatos, EsCatalogo`, `use CifraCampos; use DeCatalogo;`,
   `$builder = ConsultaVigilada::class`, `indicesCiegos()`, y `usuario_id` **fuera de
   `$fillable`** —lo pone el controlador desde la sesión, igual que `paciente_id` en el
   dominio clínico—.
3. **FormRequest** con `new IndiceCiegoUnico(Modelo::class, 'nombre', ['usuario_id' => auth()->id()], $rutaModelo?->id)`.
4. **Controlador** que extiende `CatalogoBaseController` y define `modelo()`, `pagina()`,
   `serializar()` y los cuatro métodos finos.
5. **Página Vue** en `pages/catalogos/`, copiada de `Medicos.vue`.

**Si el nombre no se llama `nombre`** —`medicamentos` usa `nombre_comercial`— se pisa
`columnaNombre()` en el modelo. De eso dependen el orden del listado, el índice ciego y el
mensaje de "ya existe uno así", así que se declara y no se asume.

### Por qué el controlador base no hace más de lo que hace

`store()`, `update()` y `destroy()` **no pueden vivir en la clase base**, y no es por
prolijidad:

- `store`/`update` reciben un FormRequest distinto por catálogo, y el contenedor de Laravel
  inyecta **el tipo que dice la firma**, no una subclase.
- `destroy` necesita un type-hint concreto para que funcione el route-model binding.

Así que cada controlador escribe esos métodos, de tres líneas, llamando a los helpers
protegidos. Menos magia y más repetición, a cambio de que las firmas digan la verdad.

⚠️ **La clase base es genérica (`@template TCatalogo`)** y cada controlador la cierra con
`@extends CatalogoBaseController<Medico>`. Sin eso, las firmas nativas solo pueden decir
`Model&EsCatalogo` y desde ahí no se ven las columnas del catálogo concreto: PHPStan tira
trece errores de `property.notFound` y la tentación es tapar cada uno con un cast.

- En el `serializar()` de cada controlador, la firma nativa queda en `Model&EsCatalogo`
  —PHP **no deja angostar un parámetro** al implementar, es fatal— y el angostado se hace
  con `@param Medico $registro`. Es lo único que hace falta.
- Desde el genérico, `usuario_id` se escribe con `setAttribute()` y no con la propiedad
  mágica, que para el analizador no existe. Es el mismo camino que usa `CatalogoPolicy`
  para leerla.
- El listado NO usa scopes de Eloquent: un scope definido en un trait no se resuelve
  contra un `class-string` genérico. La condición vive en `visiblesPara()`, un helper del
  controlador base que devuelve una clausura para meter en un `where()`.
- **Ese paréntesis del `where(clausura)` no sobra**: sin agrupar, el `orWhereNull` se mezcla
  con cualquier otro filtro que se encadene y las semillas de todos se cuelan. Es el modo de
  falla clásico de un OR sin agrupar, y no da error: devuelve de más.

### Props extra: `centros` es el primer catálogo que necesita más que su listado

`CatalogoBaseController::index()` arma la respuesta con `propsExtra()`, un hook protegido
que devuelve `[]` por defecto y que `CentroController` pisa para mandar
`medicosDisponibles` —el catálogo de médicos, para armar el checklist de "quién atiende
acá"—. Sin este hook, sumar un pivote hubiera obligado a reescribir `index()` entero en
cada catálogo que lo necesite, en vez de agregar un método de tres líneas.

### El pivote `centro_medico`: la primera vez que un catálogo apunta a otro

- **No tiene `usuario_id` propio.** Las dos puntas ya son catálogos del usuario; la
  autorización de vincular pasa por poder **ver** los dos lados (`CatalogoPolicy::view()`
  en el médico, vía la regla de `exists` del FormRequest), no por un dueño del pivote.
- ⚠️ **La validación de `medicos.*` repite el mismo paréntesis que `visiblesPara()`**, pero
  esta vez adentro de un `Rule::exists()->where(clausura)`. Sin agruparlo, el `orWhereNull`
  se mezcla con la condición de `id` que ya agrega `exists` y la regla deja pasar
  **cualquier** médico que exista, sin importar de quién sea — el mismo error, disfrazado de
  regla de validación en vez de scope.
- **Un array ausente en el formulario significa "ninguno marcado", no "no toques nada".**
  `medicos()->sync($ids)` con `$ids = []` desvincula a todos: es el comportamiento correcto
  para un checklist —destildar todo y guardar tiene que vaciar la lista—, pero hay que
  tenerlo presente al leer el código: no es un `sync` defensivo que preserve lo que había.
- **`replicate()` no copia relaciones.** Duplicar una semilla de centro no arrastra sus
  médicos vinculados, y es lo correcto: esos médicos son del catálogo de quien publicó la
  semilla, no del catálogo de quien duplica.
- ⚠️ **`cascadeOnDelete()` no dispara con un soft delete**: es una restricción de MySQL y
  solo actúa sobre un `DELETE` real. Hoy eso ya no pesa acá, porque un catálogo se borra
  **de verdad** (ver la sección siguiente): al borrar un centro o un médico, sus filas de
  `centro_medico` se van con la cascada. Que un médico atienda en un centro **no frena** el
  borrado: es configuración del catálogo, no un registro de la historia de nadie.

### Borrar un catálogo: si algo lo usa no se borra; si nada lo usa, de verdad

Es la regla de los cinco catálogos, **decidida por el usuario**, y arregla un 500 medido: borrar
"Dr. Pérez" y volver a cargarlo reventaba, porque el borrado lo dejaba en la papelera ocupando su
`nombre_hash` en el UNIQUE, `IndiceCiegoUnico` (que consulta sin la papelera) no lo veía, y la base
lo rechazaba. Las dos mitades se sostienen entre sí:

- **Si algo lo usa, no se borra**, y el aviso dice dónde: "No se puede eliminar Dr. Pérez: lo
  usan 2 estudios y 1 turno". Un médico que figura en la historia es parte de ella. Es el freno
  que ya tenían medicamentos y variables, ahora para los cinco y en un solo lugar
  (`CatalogoBaseController::eliminar()`).
- **Si nada lo usa, se borra sin papelera** (`forceDelete`, declarado en `EsCatalogo`). No hay
  nada que recuperar, y el nombre queda libre para volver a cargarlo.

Dónde se usa cada catálogo lo declara su controlador en `usos()`, una lista de
`App\Support\UsoDeCatalogo` ("los estudios, por su `medico_id`"):

| Catálogo     | Lo frenan                                                                   |
| ------------ | --------------------------------------------------------------------------- |
| Médicos      | enfermedades, tratamientos, órdenes, estudios, recetas de anteojos y turnos |
| Centros      | estudios, recetas de anteojos y turnos                                      |
| Medicamentos | tratamientos                                                                |
| Variables    | mediciones                                                                  |
| Vacunas      | nada, todavía (ver abajo)                                                   |

⚠️ **La papelera cuenta.** Un estudio borrado se puede restaurar, y volvería sin su médico; y
donde la FK es `restrictOnDelete` (un tratamiento con su medicamento) la base rechazaría el borrado
por una fila que nadie ve: sin contarla, borrar daba un 500. Por eso `UsoDeCatalogo` cuenta sin el
scope de soft deletes, y el aviso lo explica ("se cuentan también registros que borraste, porque
todavía se pueden recuperar"): sin esa frase, alguien que ve cero estudios en pantalla lee "lo usa
1 estudio" y no entiende de dónde sale.

⚠️ **`ReferenciasACatalogosTest` lee las FK reales del esquema** y falla si alguna que apunte a un
catálogo no figura en sus `usos()` —o si un uso declarado apunta a una columna que no existe, que
contaría siempre cero y no frenaría nunca—. El caso anunciado es **`aplicaciones_vacuna`**: cuando
exista, su `vacuna_id` tiene que declararse en `VacunaController::usos()`, y el test lo va a exigir.

Borrar de verdad un catálogo con archivos —un medicamento con su prospecto— **se lleva los
archivos**: primero el disco, después las filas, incluidas las de prospectos que ya estaban en la
papelera. Es la primera vez que se resuelve el hueco de los adjuntos huérfanos (ver "Estado").

**Autoriza antes de mirar los usos**: al revés, la respuesta le contaría a un extraño —o a quien
intenta borrar una semilla— si ese registro tiene datos cargados.

⚠️ **Esto cambia la regla de la sección "Cuándo bloquear el borrado"** (Enfermedades): ahí las
FK que son metadato (`medico_id`, `centro_id`) se dejaban ir con `nullOnDelete`. Desde la pantalla
**ya no se dejan ir**: frenan el borrado. Las FK siguen con `nullOnDelete` en la base como última
red, pero la app no llega a usarla.

### Medicamentos y vacunas: el tercero y el cuarto, sin decisiones nuevas

Copiados del patrón sin tocar nada de lo anterior — es justo lo que el patrón prometía.

- **`medicamentos` pisa `columnaNombre()`** por `nombre_comercial`: es lo que trae la caja,
  no la droga, y de esa columna dependen el orden del listado, el índice ciego y el mensaje
  de "ya existe uno así" (ver más arriba, "si el nombre no se llama `nombre`").
- **`medicamentos` es el primer catálogo que suma `TieneAdjuntos`**: el prospecto es un
  adjunto tipo `Prospecto` colgado del medicamento. La autorización **no necesitó ni una
  línea nueva** — es exactamente lo que la refactorización de `AdjuntoPolicy` (ver sección
  Adjuntos) estaba anticipando: subir el prospecto pide `update` sobre el medicamento, y
  `CatalogoPolicy::update()` ya negaba una semilla desde antes de que existiera esta pantalla.
  Sale gratis, también, que una semilla no admita prospecto propio.
- Subirlo y borrarlo **no viven en `MedicamentoController`**: son `AdjuntoController`
  (`storeParaMedicamento` y el `destroy` genérico), el mismo camino que usa una cobertura
  para su credencial. El controlador del catálogo solo serializa el prospecto para el
  listado.
- ⚠️ **Sumé un hook nuevo a `CatalogoBaseController`: `conEager()`.** Mismo patrón que
  `propsExtra()` -vacío por defecto, lo pisa quien lo necesita-, pero para relaciones: sin
  él, `$registro->adjuntosDe(...)` dispara una consulta por cada medicamento del listado.
  "Todo listado con eager loading explícito" no es una regla que valga solo para el dominio
  clínico.
- **`vacunas` NO lleva `TieneAdjuntos`.** El comprobante de una dosis aplicada no cuelga de
  acá: cuelga de `aplicaciones_vacuna` (Etapa 11), que es el registro clínico real. La
  vacuna del catálogo es solo el nombre, compartido entre dosis y pacientes — colgarle un
  archivo sería mezclar el nombre genérico con el papel de una dosis puntual.
- Las claves que viajan al frontend para columnas que **coinciden con el nombre de la
  columna en la base** van en snake_case (`nombre_comercial`, `para_que_sirve`), igual que
  `fecha_nacimiento` o `grupo_sanguineo` en `pacientes`. Solo lo derivado va en camelCase
  (`esSemilla`, `tipoEtiqueta`). Mezclar los dos estilos en la misma respuesta por prolijidad
  visual rompe esa convención sin necesidad.

### `CatalogosSeeder`: la idempotencia la garantiza el código, no la base

Carga las semillas compartidas de medicamentos y vacunas, y corre **en producción**:

```bash
php artisan db:seed --class="Database\Seeders\CatalogosSeeder"
```

- **Solo medicamentos y vacunas.** Ni médicos ni centros llevan semilla: nadie publica una
  lista de médicos o de consultorios compartida entre usuarios (ver el comentario de la
  migración de `medicos`). Cuando `tipos_medicion` (Etapa 6) necesite las suyas, es un método
  más acá, no un seeder aparte.
- ⚠️ **El `UNIQUE(usuario_id, nombre_hash)` no protege entre semillas**, como ya avisaba la
  sección de arriba: MySQL admite cualquier cantidad de filas con `usuario_id` NULL en un
  índice único. Correr el seeder dos veces sin este cuidado duplicaría las quince entradas de
  medicamentos y las diecisiete de vacunas en la segunda pasada, sin que la base se queje.
  Por eso `CatalogosSeeder` chequea **antes de crear** —por el índice ciego, igual que
  `CatalogoBaseController::duplicarRegistro()`—, y no confía en un `firstOrCreate` ni en
  capturar la excepción del UNIQUE: sobre una columna cifrada, un `firstOrCreate` compararía
  ciphertexts distintos entre sí y jamás encontraría la fila existente.
- Verificado contra MySQL real, no solo con el test: correr el comando dos veces seguidas dejó
  la misma cantidad de filas las dos veces.
- Una semilla y un registro propio pueden compartir nombre sin problema: el UNIQUE está
  acotado por `usuario_id`, y NULL contra un id real nunca choca.

## Seguimiento de variables

Peso, presión, glucemia. Dos tablas: **`tipos_medicion` es un catálogo** (el quinto, del
usuario, con semillas) y **`mediciones` es dominio clínico** (del paciente, por el pivote).
La separación no es cosmética: el "qué se mide" lo define una persona para toda la familia,
y el "cuánto dio" pertenece a un paciente concreto.

### El caso que define el esquema: dos números

La presión son 120/80, así que hay `valor_secundario` en la medición y `*_secundario` en el
tipo. Tres decisiones que se siguen de eso:

- **Un tipo tiene dos valores si y solo si declara `etiqueta_secundaria`.** Lo decide la
  etiqueta y no la unidad, porque la etiqueta es lo que el formulario necesita para rotular
  el segundo campo —sin "Diastólica" escrito no hay forma honesta de pedirlo— y porque la
  unidad secundaria suele estar vacía: en presión las dos son mmHg. `unidad_secundaria` cae
  en la principal cuando no se declara.
- **`etiqueta_principal` no estaba en el plan y se sumó.** Con solo la del segundo, el
  formulario pide "Valor" y "Diastólica": la asimetría que confunde. Con las dos, pide
  "Sistólica" y "Diastólica".
- **El segundo valor es obligatorio o prohibido, nunca opcional.** Una presión sin
  diastólica no es media presión, es un dato ilegible; y un peso con un segundo número es un
  fantasma que nadie sabe después qué significaba. Lo valida `MedicionGuardarRequest` mirando
  el tipo elegido, y la pantalla muestra u oculta el campo con el mismo criterio.

### Los rangos de referencia no son un semáforo

`min_normal`/`max_normal` (y sus pares secundarios, que tampoco estaban en el plan: si hay
dos valores tiene que haber dos rangos, o el gráfico del 6.3 dibuja la banda equivocada justo
en el tipo que motivó todo).

Se muestran **al lado del valor, como en un análisis de laboratorio**. Ninguna pantalla pinta
un número de otro color ni dice si está "mal" — es la regla 1 (_el sistema registra, no
aconseja_). Quedan **en claro**: no son el dato clínico de nadie, son una propiedad del tipo,
que encima puede ser una semilla compartida por todos.

### ⚠️ El valor está cifrado, así que es un STRING

No existe un cast `encrypted:float`: lo que sale del modelo es el texto tal cual se guardó.
Eso convierte en trampa cualquier cuenta directa, y ninguna de las tres falla —devuelven algo,
y está mal—:

```php
$mediciones->sortBy('valor')   // ordena como texto: "100" < "9"
$mediciones->max('valor')      // el máximo alfabético
$a->valor > $b->valor          // comparación de strings
```

El número se pide siempre por `valorNumerico()` / `valorSecundarioNumerico()`. El listado
manda las dos formas: `valor` (float, para el gráfico) y `valorVisible` (ya formateado con
los decimales del tipo y coma decimal).

### ⚠️ La coma decimal: lo que escribe de verdad un teclado en español

Acá se escribe "72,5", y un teclado numérico de celular en español ofrece la coma. Sin
normalizar pasa una de dos, las dos malas: `numeric` rechaza el valor —y la persona ve "debe
ser un número" mirando un número válido para ella— o un `(float)` lo convierte en **72.0** y
el peso pierde los gramos sin avisar.

Lo corrige el trait `NormalizaDecimales` en `prepareForValidation()`, **antes** de que la
regla `numeric` mire el valor. Lo usan las mediciones y los rangos del catálogo, y va a
hacer falta otra vez en resultados de estudios (Etapa 9) y en graduaciones (Etapa 10).

Del lado de la pantalla, los valores van con **`inputmode="decimal"` y `type="text"`, nunca
`type="number"`**: un `type="number"` con coma en un navegador es-AR entrega un valor
**vacío** al enviar, porque considera el campo inválido y no expone lo tipeado.

### Una ficha compartida comparte su vocabulario

Un tipo se puede elegir si esta persona lo ve —propio o semilla— **o si esta ficha ya lo
viene usando**. La segunda mitad no es un agregado: los catálogos son del usuario, y una ficha
la escriben varios. Sin ella, un cuidador no podría sumar un peso a la serie que arrancó el
dueño —el tipo es del dueño, no suyo— y terminaría creando un "Peso" propio: la misma variable
partida en dos, que es justo lo que el catálogo por usuario venía a evitar.

- **No expone nada nuevo**: son los tipos que esa persona ya está viendo en el listado de esa
  misma ficha. El resto del catálogo del dueño sigue siendo suyo, y hay un test que lo fija.
- El formulario y la validación usan el **mismo** criterio. Si el `select` ofreciera algo que
  la validación rechaza, el error aparecería recién al guardar.
- Queda un hueco chico para la Etapa 14: si el dueño creó un tipo propio y **todavía no lo
  usó**, el cuidador no puede elegirlo. Se resuelve usándolo una vez, o con una semilla.

### Otras decisiones que no son obvias

- **`fecha` es `datetime`, no `date`**: en la mitad de las variables la hora _es_ el dato
  —una presión de la mañana y una de la noche no son comparables, una glucemia en ayunas
  tampoco—. Queda en claro, como todas las fechas: es por donde ordena el listado y va a
  paginar el gráfico.
- **No se puede cargar una medición futura**, con cinco minutos de gracia para el reloj del
  dispositivo: es el registro de algo que ya pasó, pero rechazar "ahora" por dos segundos de
  diferencia sería incomprensible.
- **`tipo_medicion_id` va con `restrictOnDelete`**, que es la última línea: `cascade` se
  llevaría doscientas mediciones por borrar un tipo, y `nullOnDelete` dejaría doscientos
  números sin unidad ni nombre —peor que borrarlos, porque parecen datos—. Antes que la base,
  lo frena `TipoMedicionController::destroy` con un mensaje que explica la salida real
  (editar el tipo).
- **`Medicion::tipo()` va con `withTrashed()`.** Un tipo cuyas mediciones están todas en la
  papelera sí se puede borrar; si después alguna se restaura, sin esto la relación devolvería
  `null` y la fila aparecería como un número sin nombre ni unidad.
- **Autorizar va antes de validar.** `MedicionGuardarRequest::authorize()` existe para eso: sin
  él, a alguien sin permiso le contesta primero la validación ("Elegí una variable de tu
  lista") en vez de un 403, y de paso le confirma que la ficha existe. Lo mismo en
  `TipoMedicionController::destroy`, que autoriza antes de mirar si el tipo tiene datos.
- **Las mediciones son pantalla propia y no un panel** del paciente: crecen con el tiempo y en
  el 6.3 suman su gráfico, que no entra en un sheet. Van por `/pacientes/{paciente}/mediciones`
  y no por "paciente activo": confundirse de ficha acá es cargarle el peso de un familiar a
  otro.

### `GraficoEvolucion.vue`: tres reglas que después se copian tres veces

Lo reusan los resultados de estudios (Etapa 9) y la graduación ocular (Etapa 10), así que
estas tres son del patrón y no de este componente:

1. **El eje Y NO arranca en cero.** Una presión que va de 12 a 14 se ve como una línea plana
   en una escala de 0 a 14, y esos dos puntos son justamente el dato. La escala se ajusta a
   los valores con un respiro del 12%; cuando todos son iguales —o hay uno solo— el recorrido
   es cero y hay que inventar uno, o la línea queda pegada al borde.
2. **El rango de referencia va como banda de fondo**, nunca como línea de corte ni como color
   de alarma sobre los puntos. Es información del mismo tipo que el rango impreso al costado
   de un análisis (regla 1).
3. **El gráfico nunca es la única fuente.** Debajo va siempre la lista con fechas y valores:
   un `<canvas>` no lo lee un lector de pantalla, y dos tomas del mismo día quedan una encima
   de la otra. El contenedor lleva `role="img"` con un resumen en `aria-label`.

Detalles que cuestan de encontrar después:

- **Con una sola medición no se dibuja nada**: una línea de un punto no es una evolución.
- **El eje X es `linear` con la fecha en milisegundos, no una escala de tiempo.** La de tiempo
  necesita un adaptador de fechas —otra dependencia— y acá alcanza con formatear la marca. Y
  respeta la separación real entre tomas, que una escala de categorías aplanaría: diez tomas
  de una semana y una de hace un año se verían igual de separadas.
- Las etiquetas del eje se formatean con `Intl` y la **zona de la cuenta**, la misma con la
  que el servidor armó las fechas de la lista. Con la del navegador, las dos no coincidirían.
- **Chart.js mide sus fuentes en px**, así que no hereda el `font-size` de la raíz como todo
  lo demás (Tailwind mide en rem). El componente lo lee y escala sus fuentes, o quien eligió
  "Muy grande" tendría la app entera escalada y los números del eje chiquitos.
- La banda es un **plugin de doce líneas** dibujando un rectángulo, no otra dependencia.
- Los colores salen de las variables CSS y se vuelven a leer si cambia el tema: la
  preferencia "según el sistema" puede cambiar sola mientras el gráfico está en pantalla.

### Las semillas de variables, y por qué el IMC necesita una columna

`CatalogosSeeder` siembra siete: peso, altura, presión, glucemia, temperatura, saturación y
pulso. Con eso la app sirve sin que nadie configure nada, y la pantalla de Variables queda
para el caso raro.

**El IMC se deriva, no se guarda** (regla 4): sale del último peso y la última altura en cada
request. Se muestra el número y de dónde salió, **sin ninguna categoría** —decir "sobrepeso"
sería interpretar, y eso es del médico—.

⚠️ Para eso hay que saber **cuál** de las variables es el peso, y por el nombre no se puede:
está cifrado —no hay `where nombre = 'Peso'`— y además lo puede editar la persona. Buscarlo
por su índice ciego andaría hasta que alguien renombre "Peso" a "Peso corporal", y ahí el IMC
desaparecería sin explicación. De ahí sale `tipos_medicion.clave`: una etiqueta del sistema,
en claro, que **solo escribe el seeder** (no es fillable, y por eso el seeder guarda con
`forceFill`). Una variable creada a mano queda en `null` y el código no la reconoce, que es lo
correcto; y al duplicar una semilla la copia se la lleva, así que el IMC sigue andando para
quien se armó su propia copia de "Peso".

### `CatalogoVisible`, o el mismo OR por tercera vez

La condición "lo mío más las semillas" ya hacía falta en tres lugares —el listado de un
catálogo, qué médicos puede vincular un centro, qué tipo puede elegir una medición—, y
escribirla mal **no da error, devuelve de más**. Vive en `App\Support\CatalogoVisible` y
devuelve una clausura que entra en un solo `where()`, así no hay forma de encadenarla suelta.
Sirve igual para un builder de Eloquent y para el crudo de `Rule::exists()`.

⚠️ En `Rule::exists()` hay que agregar **`whereNull('deleted_at')` a mano**: esa regla va
contra la tabla cruda y no filtra soft deletes como Eloquent.

## Enfermedades, bitácora y alergias

Tres tablas y **una sola pantalla** por paciente
(`/pacientes/{paciente}/enfermedades`): son pocas filas cada una, se consultan juntas, y a la
ficha del paciente ya se le colgaban cuatro botones. Ni la bitácora ni las alergias tienen
`index` propio —viajan como prop—, el mismo patrón que las coberturas.

**Las alergias van arriba**, aunque sean menos: es lo que alguien busca apurado.

### ⚠️ En la bitácora NO van números medibles

Tentaba darle `valor` y `unidad` a `registros_enfermedad` para poder anotar "hoy 140/90" sin
salir de la ficha, y sería un error: el mismo dato viviría en dos tablas y **la curva de
presión saldría partida según dónde se lo cargó ese día**.

Los números van a `mediciones`, que apunta a la enfermedad por `enfermedad_id`. Así la ficha
de la enfermedad muestra su propia curva sin duplicar nada, y el promedio es el mismo se lo
mire desde donde se lo mire —lo arma el mismo `SeriesDeMediciones` que la pantalla de
mediciones—.

- **`mediciones.enfermedad_id` tiene que ser del MISMO paciente**, y lo valida el FormRequest.
  Sin esa condición, un id de otra ficha vincularía una presión ajena a una enfermedad de acá:
  la curva mostraría valores de otra persona y nada lo delataría en pantalla.
- **Borrar la enfermedad no borra las mediciones** (`nullOnDelete`): el peso de ese día sigue
  siendo el peso de ese día. Lo que se pierde es el vínculo.
- **Borrar la enfermedad SÍ se lleva su bitácora** (`cascadeOnDelete`): una anotación no
  existe sin su enfermedad.
- La bitácora **no se edita**, solo se agrega y se borra. Corregir el pasado en silencio es
  justo lo que no quiere una historia clínica.

### Cuándo bloquear el borrado de un catálogo y cuándo dejarlo ir

> ⚠️ **Superado:** desde que se decidió que un catálogo usado no se borra (ver "Borrar un
> catálogo" en la sección Catálogos), **toda** referencia frena el borrado desde la pantalla,
> también las de metadato. Lo de abajo sigue valiendo para elegir el `onDelete` de una FK nueva
> en la migración —que es la red de la base—, no para decidir si la pantalla deja borrar.

Es la segunda FK del proyecto que apunta a un catálogo, y va distinto que la primera. La regla
que queda, para las que vengan:

> **Bloquear el borrado cuando la referencia es imprescindible para leer el registro; dejarla
> ir cuando es metadato.**

| FK                            | Qué pasa si falta                        | Decisión                                     |
| ----------------------------- | ---------------------------------------- | -------------------------------------------- |
| `mediciones.tipo_medicion_id` | Un número sin unidad ni nombre: ilegible | `restrictOnDelete` + el controlador lo frena |
| `enfermedades.medico_id`      | Una enfermedad sin médico: se lee igual  | `nullOnDelete`, sobrevive sin él             |

Para el caso normal —un **soft delete** del catálogo— ninguna de las dos alcanza: la fila
sigue existiendo y la FK no se entera. Eso lo cubre el `withTrashed()` de la relación, igual
que en `Medicion::tipo()`.

### El resto de las decisiones

- **`estado` tiene tres casos y no dos.** "Crónica" es algo que la gente dice y que no es ni
  activa-que-va-a-terminar ni resuelta: una diabetes no se cura ni se está esperando que se
  cure. Separarla deja que la pantalla muestre primero lo vigente sin que una hipertensión de
  hace diez años aparezca como un cuadro reciente. `estaVigente()` es lo que agrupa.
- **`alergias.sustancia` lleva índice ciego y UNIQUE por paciente**; `enfermedades.nombre`
  **no**. Dos neumonías en años distintos son dos enfermedades, no una cargada dos veces; dos
  "Penicilina" con severidades distintas no dejarían saber cuál vale.
- **`RegistroEnfermedad` es el primer registro clínico que llega a su paciente en DOS pasos**
  —sube por `enfermedad` y recién ahí lo encuentra—. Es exactamente para lo que
  `PerteneceAPaciente` pide un método y no una relación: con un `BelongsTo` obligatorio, esto
  no podría cumplirlo.
- **Anotar en la bitácora pide `update` sobre la enfermedad**, no un permiso propio: misma
  regla que los adjuntos con su dueño.
- La severidad de una alergia **se muestra como texto, nunca como un semáforo de colores**: es
  lo que cargó la persona, no un juicio del sistema (regla 1).

### `FechaNoFutura`: la trampa de `hoyCalendario()`, envuelta

Las dos fechas de esta etapa son de **calendario** (`date`), no instantes. Validar "no
futura" comparando contra `hoy()` —que es la medianoche local expresada en UTC— corre la
comparación tres horas y, **entre las 21:00 y la medianoche argentina, rechaza el día de hoy
por futuro**. No da ningún síntoma hasta que alguien carga algo de noche.

Por eso la comparación vive en una regla y no repetida en cada FormRequest. Para un
`datetime` la regla es otra —hace falta la zona y un margen para el reloj del dispositivo—: eso
sigue en `MedicionGuardarRequest`.

## Órdenes de estudio

El papel que da el médico **antes**; el estudio es el resultado de después (Etapa 9.2). Que
sean dos tablas y no dos estados de una es la decisión que sostiene la etapa entera:

> Una orden existe desde que el médico la firma y **puede no convertirse nunca** en un
> estudio: se vence, cambia la indicación, uno no va.

Modelarla como un `estudios` con los campos de resultado vacíos obligaría a que media tabla
fuera nullable y, peor, dejaría la pantalla de "pendientes de hacer" apoyada en _"estudios
donde falta casi todo"_ — una definición que se rompe sola en cuanto alguien cargue un
estudio incompleto por otro motivo.

La pantalla contesta **una sola pregunta: qué me falta hacerme.** Por eso lo pendiente va
arriba y separado, y por eso el orden es `estado` primero y `fecha` después: una orden hecha
la semana pasada no puede tapar una pendiente de hace un mes.

### ⚠️ `estado` y el estudio NO son la misma cosa

Cuando el paso 9.2 sume `estudio_id`, la regla es **de una sola dirección**:

> Vincular un estudio implica `Hecha`; estar `Hecha` **no** implica que haya un estudio
> cargado.

No es un detalle: uno se hace el análisis y tarda semanas en subir el PDF, o no lo sube
nunca. Si "hecha" se dedujera de `estudio_id`, todas esas órdenes seguirían apareciendo como
pendientes y la pantalla que justifica la tabla entera diría cualquier cosa.

`estudio_id` **no se creó en 9.1 a propósito**: `estudios` todavía no existe, así que la
columna quedaría sin FK, sin validación y sin nada que la escriba. Es el mismo criterio que
con `mediciones.enfermedad_id`, que llegó recién en la Etapa 7 y no costó ningún backfill.

### `TieneArchivos`: el cuarto dueño obligó a dejar de copiar

`ordenes_estudio` es el **primer registro clínico con archivos propios** —hasta acá los
adjuntos colgaban del paciente, de una cobertura o de un catálogo—, y con eso el cuerpo de la
subida pasaba a estar copiado cuatro veces en `AdjuntoController`.

- `App\Contracts\TieneArchivos` declara qué es "algo que tiene archivos"; la implementación
  sigue en el trait `TieneAdjuntos`. Mismo reparto que `EsCatalogo`/`DeCatalogo` y que
  `PerteneceAPaciente`.
- **Cada dueño conserva su método con su type-hint concreto** (`storeParaOrden`,
  `storeParaCobertura`…): hace falta para el route-model binding, igual que en los catálogos.
  Lo que quedó una sola vez es el cuerpo, en `guardarEn()`.
- **El prefijo del disco lo declara el modelo** (`carpetaDeArchivos()`, por defecto
  `<tabla>/<id>`) y no lo arma el controlador: era un string repetido cuatro veces, y dos
  dueños escribiendo en la misma carpeta por un copiar-pegar no da ningún error.
  ⚠️ Los tres prefijos que ya existían coinciden con ese default, así que el refactor **no
  movió ningún archivo guardado** — hay un test que lo fija.

## Tratamientos

Qué medicamento toma un paciente, con qué dosis y por qué. `medicamento_id` es la **tercera**
FK a un catálogo y sigue la misma regla que ya quedó escrita en la sección de Enfermedades:

> Bloquear el borrado cuando la referencia es imprescindible para leer el registro; dejarla
> ir cuando es metadato.

Un tratamiento sin su medicamento es "500mg cada 8 horas" de nada: imprescindible, así que
va con `restrictOnDelete`, y la pantalla lo frena antes con un mensaje (hoy con el freno común a
los cinco catálogos, `CatalogoBaseController::eliminar()`).
`medico_id` y `enfermedad_id` son metadato: `nullOnDelete` en la base, aunque desde la pantalla un
médico que figura en un tratamiento tampoco se puede borrar.

- **`activo` es un booleano, no un enum de estados.** Con dos estados reales -lo toma o no lo
  toma- un enum sería una capa sin necesidad. Sigue el mismo patrón del checkbox `"on"` que
  ya tiene `coberturas.activa`: se normaliza en `prepareForValidation()`.
- **`inicio` NO tiene `FechaNoFutura`.** Al revés que una fecha de diagnóstico, un tratamiento
  se puede cargar para empezar mañana -el médico lo indicó para después de terminar otro-.
  Solo se valida `fin >= inicio`.
- El medicamento, el médico y la enfermedad siguen el mismo criterio de "propio o semilla, o
  ya usado en esta ficha" que enfermedades y mediciones (ver `CatalogoVisible`).

### `misalud:cerrar-tratamientos-vencidos`: el primer comando del scheduler

Corre una vez al día (`bootstrap/app.php`, `->withSchedule()`) y marca `activo = false` en
todo tratamiento activo cuya `fin` ya pasó.

⚠️ **Compara contra el día de hoy en UTC, no contra el de cada usuario**, y es la única
excepción consciente a la regla de zona horaria del resto de la app. `User::hoyCalendario()`
existe justo para esto, pero acá no hay un usuario: es un proceso de fondo para toda la base,
y un paciente puede tener varios usuarios con distinta zona por el pivote. La imprecisión que
queda -hasta unas horas cerca de la medianoche de cada zona- no tiene el costo que tiene en
una validación en vivo: ahí un rechazo equivocado le arruina la carga a una persona; acá, en
el peor caso, un tratamiento queda "activo" un día de más y el comando lo corrige solo al
otro día. `activo` es informativo, no una condición de autorización ni un dato que se pierda.

- Nunca pone `activo` en `true`: reactivar un tratamiento sigue siendo una acción manual de
  quien lo edita.
- Escribe con un `update()` masivo, no cargando cada modelo: no hace falta recalcular ningún
  índice ciego -`activo` y `fin` no están cifrados- y son potencialmente muchas filas.
- En producción lo dispara el cron de hPanel con `schedule:run` cada minuto (ver
  `deploy-hostinger`); acá solo se declara qué correr y cuándo.

### Un bug de reactividad que ningún test de Pest podía ver

`tratamientos/Index.vue` separaba activos de inactivos con un `const` calculado una vez en el
`setup()`. Cargar un tratamiento redirige a la **misma URL**, así que Inertia reutiliza la
instancia del componente en vez de remontarla: `props.tratamientos` cambiaba, pero el `const`
quedaba congelado con el valor de la carga inicial. El tratamiento recién creado no aparecía
hasta un refresh manual de la página.

Apareció recién verificando en Chrome real -los 393 tests de Pest pasaban igual, porque
prueban la respuesta del servidor, no la reactividad del cliente-. Se arregla con
`computed()` en vez de `const`, el mismo patrón que ya usa `enfermedades/Index.vue` para
`vigentes`/`pasadas`. **Vale como regla general**: cualquier pantalla que derive listas de
`props` filtrando o agrupando, y que pueda recibir un `redirect` a su propia URL, tiene que
usar `computed()`. Un `const` es seguro solo si la pantalla nunca se revisita a sí misma.

## Estudios y resultados

Lo que se hizo, después de la orden (Etapa 9.2), con sus parámetros (9.3) anidados en la
misma pantalla —mismo patrón que la bitácora de una enfermedad, sin `index` propio— y la
evolución de cada parámetro a través de varios estudios (9.4), reusando `GraficoEvolucion.vue`.

### El circuito orden → estudio, cerrado

`ordenes_estudio.estudio_id` **no se creó en el paso 9.1 a propósito**: `estudios` todavía no
existía. Llega recién acá, con la regla de una sola dirección ya documentada en la Etapa 9.1
puesta en práctica:

- **Vincular una orden al crear un estudio la marca `Hecha`.** Es lo único que hace
  `EstudioController::store()` además de crear el registro: si viene `orden_estudio_id`,
  busca esa orden y la actualiza.
- **Solo al crear**, no al editar: re-vincular una orden después es un caso raro que no valía
  la complejidad de manejar (regla del proyecto: no diseñar para lo hipotético).
- ⚠️ **`estudio_id` en `OrdenEstudio` NO es fillable** —no lo escribe ningún formulario de la
  orden—, así que vincularla usa `setAttribute()` + `save()`, igual que `usuario_id` en
  `CatalogoBaseController`. La primera versión de este código usaba `update(['estudio_id' =>
...])`, que en silencio **ignora** los campos no fillable: la orden se hubiera quedado sin
  vincular sin que nada avisara. Lo encontró la lectura del propio código, no un test —pero
  hay un test que lo fija igual.
- **Borrar el estudio no revierte el estado de la orden.** Es la relación de una sola
  dirección funcionando en los dos sentidos: `nullOnDelete` en la FK deja la orden sin
  vínculo, pero sigue `Hecha` -esa persona **se hizo** el estudio, borrar el registro después
  no deshace el hecho-.

### El quinto dueño de archivos: nada que decidir de nuevo

`Estudio` implementa `TieneArchivos` igual que los otros cuatro (paciente, cobertura,
medicamento, orden). `AdjuntoController::guardarEn()` ya estaba armado para esto desde la
Etapa 9.1 — sumar `storeParaEstudio()` fue una función de tres líneas, sin tocar el cuerpo
compartido. Es la prueba de que la refactorización valió la pena: el sexto dueño, cuando
llegue, va a costar lo mismo.

### `resultados_estudio.valor` es texto, como `mediciones.valor`

Un resultado de laboratorio no siempre es un número: "Positivo", "No reactivo", "3+" son
resultados reales. `valor` es texto cifrado, y `ResultadoEstudio::valorNumerico()` devuelve
`null` en vez de forzar un `(float)` que convertiría cualquier texto en `0.0` sin avisar —es
lo que decide qué entra al gráfico de evolución, y ningún resultado no numérico entra.

**A diferencia de la bitácora de una enfermedad, un resultado SÍ se edita.** La bitácora es
una narrativa histórica -corregirla en silencio sería reescribir lo que pasó-; un resultado
es un dato estructurado que alguien puede haber tipeado mal ("900" en vez de "90"), y
corregirlo es exactamente lo que hace falta poder hacer. Por eso `ResultadoEstudioController`
tiene `update()` y `RegistroEnfermedadController` no.

### `parametro_hash` no protege un UNIQUE: agrupa sin descifrar

Es el primer índice ciego del proyecto que no existe por unicidad. Dos estudios pueden repetir
"Glucemia" sin problema -es lo esperable, un control se repite-, así que no hay ningún
`unique()` sobre él. Sirve para otra cosa: **agrupar resultados del mismo parámetro entre
distintos estudios sin descifrar cada fila solo para decidir a qué grupo pertenece**. Con
años de estudios acumulados, comparar un `char(64)` es gratis; descifrar cientos de filas no.
`SeriesDeResultados` agrupa por `parametro_hash` y descifra una sola fila representativa por
grupo, para el nombre visible.

No hay una columna de rango mín/máx estructurada como en `tipos_medicion` -acá solo existe
`rango_referencia`, texto libre ("70 a 110", "< 5", "Negativo")-, así que la banda de
referencia de `GraficoEvolucion` no se dibuja para esta pantalla: `minNormal`/`maxNormal`
viajan en `null`. Partir ese texto en dos números sería adivinar un formato que no está
garantizado.

### ⚠️ El bug que apareció ANTES de mandarlo: fecha de calendario en un gráfico

`estudio.fecha` es una fecha de calendario (`date`, medianoche UTC), no un instante como
`medicion.fecha`. El primer borrador de la evolución pasaba `zona-horaria="null"` al
`GraficoEvolucion` -mismo valor que usa la evolución de mediciones-, y eso es lo que **no**
correspondía acá: sin zona, el navegador formatea la fecha en **su propia zona horaria**, y
medianoche UTC vista desde Argentina (UTC-3) es las 21:00 del día anterior.

Medido en Chrome con la zona del navegador forzada a `America/Argentina/Buenos_Aires`:

```
Intl.DateTimeFormat('es-AR', {day:'2-digit', month:'2-digit'}).format(...)               → "14/1"
Intl.DateTimeFormat('es-AR', {day:'2-digit', month:'2-digit', timeZone:'UTC'}).format(...) → "15/1"
```

Un día completo de diferencia, para prácticamente cualquier usuario de esta app —está pensada
para Argentina—. Se corrigió pasando `zona-horaria="UTC"` **como string literal, no como
binding**: no hay ningún camino en tiempo de ejecución por el que ese valor pueda terminar
siendo otra cosa. Es la misma trampa de `hoy()` contra `hoyCalendario()` de la sección de
Fechas, esta vez del lado del cliente y con un gráfico en vez de una validación.

## Salud ocular

Una receta de anteojos son **dos tablas**: `prescripciones_oculares` es la cabecera —cuándo,
quién, qué tipo de lente— y `graduaciones_oculares` tiene **una fila por ojo**.

> **Una receta tiene SIEMPRE sus dos ojos**, aunque uno no necesite corrección.

No es prolijidad: una fila que falta no se puede interpretar —¿ese ojo está sano, o la carga
quedó a medias?—, y una fila en blanco sí, porque dice "sin datos" (regla 2). Lo sostienen
tres cosas juntas: el `unique(prescripcion_id, ojo)`, la transacción de
`PrescripcionOcularController::store()` y el `configure()` de `PrescripcionOcularFactory`
—que crea los dos ojos igual que el controlador, así ningún test prueba contra una receta que
en producción no puede existir—.

Con ocho columnas por ojo en la cabecera (`esfera_od`, `esfera_oi`, …) serían dieciséis
columnas cifradas y toda validación, pantalla y gráfico repetiría su lógica dos veces con
distinto sufijo. Con dos filas, "la graduación de un ojo" es un objeto: se valida una vez, se
dibuja una vez, y la evolución del paso 10.4 es un `groupBy('ojo')`.

### El único UNIQUE del proyecto que no necesitó índice ciego

`prescripcion_id` y `ojo` están **en claro** —una FK y un enum de dos casos—, así que el
UNIQUE es uno común y corriente. Es la excepción que muestra para qué existen los `*_hash`:
no hace falta ninguno cuando lo que hay que comparar no es contenido clínico.

`graduaciones_oculares` tampoco lleva soft deletes: una graduación no se borra sola, nace y
muere con su receta, y no hay ninguna acción de "borrar un ojo". ⚠️ Como la cascada de MySQL
**no dispara con un soft delete**, dar de baja una receta deja sus dos filas intactas —y es lo
correcto: restaurarla la devuelve completa en vez de con los dos ojos vacíos. Hay un test que
lo fija.

### La validación clínica: se rechaza lo que no puede existir

Es el criterio que ordena `PrescripcionOcularGuardarRequest` entero, y conviene tenerlo
presente antes de agregarle una regla más:

> **Se rechaza lo que no puede existir, nunca lo que es poco común.**

Un `-1,30` de esfera no es una graduación rara: **no se fabrica**, así que es un tipeo, y
atajarlo evita unos anteojos mal hechos. Una receta "para lejos" que además trae una adición
sí es rara, pero **existe** —es el papel que esa persona tiene en la mano— y rechazarla la
dejaría sin poder cargar su propia receta. Eso es opinar sobre el contenido, que es lo que la
regla 1 no hace. Hay un test que fija cada mitad.

| Campo           | Qué se valida                         | Por qué                                                |
| --------------- | ------------------------------------- | ------------------------------------------------------ |
| `esfera`        | ±25, pasos de 0,25                    | Atrapa el tipeo clásico: "125" por "1,25"              |
| `cilindro`      | ±12, pasos de 0,25                    | Ídem; el signo se guarda **tal cual el papel**         |
| `eje`           | entero 0–180, **atado al cilindro**   | Es un meridiano: 20° y 200° son la misma línea         |
| `adicion`       | 0,25 a 4, pasos de 0,25, **positiva** | Una adición es una suma; negativa es un error de signo |
| `dp_monocular`  | 20 a 45 mm                            | Atrapa un "320" por un "32"                            |
| `prisma`/`base` | van juntos o no van                   | Ninguno de los dos se fabrica solo                     |

El plan ponía ±20 para la esfera y ±6 para el cilindro. Se ampliaron a ±25 y ±12: una miopía
alta y un queratocono pasan de ahí **sin ser un error de carga**, y el rango está para atrapar
un tipeo, no para decidir hasta dónde puede ver alguien.

⚠️ **`App\Rules\PasoDeDioptria` cuenta en enteros, no con `fmod()`.** En binario, `0.75` y
`0.25` no son exactos: `fmod(0.75, 0.25)` puede dar `2.7E-17` en vez de `0`, y entonces la
regla rechazaría un valor **válido** —peor que no validar nada—. Pasar a centésimas con
`round()` primero deja la comparación donde sí es exacta. El tope de dos decimales lo pone
`decimal:0,2` al lado, no la regla: sin él, un `1,249999` redondearía a 125 centésimas y
pasaría.

⚠️ **El eje es obligatorio o prohibido, nunca opcional** —la misma forma que el segundo valor
de una medición—, y acá el motivo es más duro: un cilindro sin eje **no se puede fabricar**, y
un eje sin cilindro es un número que dentro de un año nadie sabrá qué significaba. Un cilindro
en `0` cuenta como "sin cilindro": un "0,00 x 180" es una costumbre de escritura, no una
corrección.

**No se cruza `dp_total` contra la suma de las dos monoculares**, a propósito: se miden por
separado y el redondeo a medio milímetro las hace diferir seguido. Cruzarlas rechazaría
recetas correctas.

### ⚠️ El trait `NormalizaDecimales` no sirve para campos anidados

Ese trait hace `merge(['valor' => ...])`, que para `od.esfera` crearía la clave **literal**
`'od.esfera'` —con el punto adentro del nombre— en vez de escribir dentro del array `od`. El
valor original quedaría intacto, la coma sin convertir, y `numeric` rechazaría un "-1,25"
perfectamente válido sin que nada explicara por qué. Por eso
`PrescripcionOcularGuardarRequest::prepareForValidation()` reconstruye entero el array de cada
ojo en vez de usar el trait.

### `DiagramaOjo.vue`: el diagrama orienta, los campos cargan

El SVG **no es una forma de cargar datos**: no recibe foco, no responde a ningún toque y va
con `aria-hidden`. Todo lo que se escribe va en un `<input>` rotulado. En una app para
personas mayores, un dibujo al que hay que acertarle a una zona es lo contrario de lo que
hace falta.

Lo que el dibujo sí hace, y ningún campo puede hacer, es mostrar **el eje como un ángulo**:
"x 90" no le dice nada a nadie, una línea vertical sobre un ojo se entiende sin explicación.
El número va igual al lado —el dibujo agrega, nunca reemplaza—.

- **OD va a la izquierda**, y se cumple por el **orden del DOM**: así vale igual cuando los
  dos ojos quedan uno arriba del otro en el celular. Cada uno lleva su rótulo completo
  —"OD · ojo derecho"—, que es lo que de verdad desambigua: la mayoría de la gente no sabe qué
  es OD, y confundirse acá termina en unos anteojos hechos al revés.
- **Un solo componente para cargar y para leer** (`modo`), porque el rótulo y el lado tienen
  que vivir una sola vez: con dos componentes, el formulario y el listado podrían terminar en
  desacuerdo sobre cuál ojo es cuál.
- **El ojo se dibuja simétrico**, no espejado. Un ojo espejado sugeriría que la escala del eje
  también se espeja, y **no se espeja**: el eje se mide igual para los dos ojos tal como los
  ve quien está enfrente, y de ahí sale que un astigmatismo simétrico se anote 20° en un ojo y
  160° en el otro. Verificado en Chrome: el mismo número da el mismo ángulo en los dos.

### ⚠️ Mezclar `:value` con `v-model` en un mismo componente borra lo tipeado

Es el bug más caro de esta etapa, y **ningún test de Pest podía verlo**.

El eje tiene que ser `v-model` para que el dibujo lo siga en vivo. Los otros siete campos
estaban con `:value`, como el resto de los formularios de la app. En cuanto **un solo** campo
del componente maneja estado propio, cada tecla que se escribe en él vuelve a renderizar el
componente entero y **pisa los `:value` de los hermanos con el valor del prop**.

Medido en Chrome: escribir la esfera, el cilindro y recién después el eje —el orden natural,
el del papel— dejaba los dos primeros **vacíos** al primer tecleo del eje.

```
1. despues de escribir esfera y cilindro : {"esfera":"-2,00","cilindro":"-0,75","eje":""}
2. despues de UNA tecla en el eje        : {"esfera":"","cilindro":"","eje":"9"}
```

Y como esfera y cilindro son opcionales, **la receta se guardaba con el ojo en blanco y un
"Se agregó la receta" en verde**: el peor modo de falla posible, sin ningún síntoma. La regla
que queda: **si un campo de un formulario necesita estado local, todos los de ese componente
lo necesitan.**

⚠️ El estado local se llama `escrito` y no `valores` **a propósito**: `valores` ya es el
nombre del prop, y una constante local con ese nombre lo taparía en el template —el modo
lectura pasaría a leer los campos del formulario en vez de lo guardado, y mostraría "sin
datos" en toda receta—.

### El sexto dueño de archivos, otra vez en tres líneas

`PrescripcionOcular` implementa `TieneArchivos` igual que los otros cinco, y sumarlo fue
`storeParaPrescripcionOcular()` llamando a `guardarEn()`. Es la segunda vez que la
refactorización de la Etapa 9.1 cobra lo que prometía. El papel se sube **aunque los valores
estén cargados**: es el documento que pide la óptica, y es lo que manda si alguna vez no
coinciden.

### La X de cerrar de `Sheet` y `Dialog` medía 20 px

Encontrado verificando esta etapa, pero **estaba en toda la app desde siempre** —lo tenían
todos los sheets y diálogos—. Arreglado en las dos primitivas con el mismo patrón que el
checkbox: la caja visual sigue midiendo 20 px y el área que responde al toque la expande un
pseudo-elemento (`before:size-12`), porque agrandar el ícono se vería mal. Medido después del
arreglo: **59 px efectivos**. `npm run revisar:mobile` sigue en verde en las 36 pantallas.

### ⚠️ Verificar un sheet sin esperar a que termine de entrar mide cualquier cosa

`waitForSelector({ visible: true })` **no espera a que el sheet termine de abrirse**: lo da
por bueno apenas el elemento tiene caja, y para entonces el panel todavía se está deslizando
desde la derecha (`slide-in-from-right`, 500 ms).

Costó un rato: el chequeo de áreas táctiles informaba "Cerrar: 20px" con el arreglo ya puesto
y funcionando. El botón estaba midiéndose en **x=651 con un viewport de 390** —fuera de
pantalla—, así que `elementFromPoint` no encontraba nada y toda área colapsaba a su caja.

El modo de falla es traicionero, y es el mismo de `revisar-mobile.mjs` con la cookie: **los
controles que ya miden 44 px de caja "pasan" igual**, así que el chequeo informa verde
midiendo lo que no quería medir. Hay que esperar a que la posición del panel **se estabilice**
entre dos frames, no a que el selector exista. Misma familia que el `canvas.width > 0` de
`revisar-visor.mjs`.

### La transposición del cilindro: aritmética, no una opinión

El cilindro se escribe en forma negativa o positiva según la óptica, y las dos son
equivalentes. Se guarda **siempre** la que trae el papel —para que la pantalla coincida con
lo que la persona tiene en la mano—, y un botón "Transponer" muestra la otra forma sin tocar
lo guardado:

```
esfera' = esfera + cilindro
cilindro' = -cilindro
eje' = eje + 90 (o -90 si eje ya pasa los 90, para quedar en 0-180)
```

- **Vive en `DiagramaOjo.vue`, no en el backend.** La transposición se calcula con los
  valores crudos que el componente ya recibe (`valores.esfera`, `.cilindro`, `.eje`) —los
  mismos que precargan el formulario de edición—, así que no hace falta ni una consulta más
  ni mandar una segunda copia de cada receta. Es puramente una forma distinta de MOSTRAR: el
  toggle vive en un `Set` local de `ocular/Index.vue` y se pierde al recargar la página, a
  propósito —es una lectura distinta del mismo papel, no una preferencia que alguien necesite
  que persista—.
- **El botón no aparece si no hay nada que transponer.** Sin cilindro, la esfera sola no
  tiene "otra forma", y un botón que no hace nada es peor que ningún botón. ⚠️ `cilindro`
  llega como string (`"0"`, `"-0.50"`, `null`): hay que comparar con `Number(...)`, porque
  `Boolean("0")` es `true` en JavaScript.
- **El diagrama también rota**, no solo el texto: `ejeDibujado` lee del eje transpuesto
  cuando el toggle está activo, así que ver el ángulo cambiar en pantalla es la confirmación
  visual de que las dos formas describen el mismo lente. Verificado en Chrome con un caso
  concreto: `-1,00 -2,00 x 60°` transpuesto da `-3,00 +2,00 x 150°`, con el dibujo girando de
  60° a 150°, y "Volver al papel" devuelve el original byte a byte.
- **Editar siempre muestra lo guardado**, nunca lo transpuesto: el toggle es del `<DiagramaOjo
modo="lectura">` del listado, y el sheet de alta/edición no recibe `transpuesto` —abre
  siempre con el valor tal cual está en la base, que es lo único que tiene sentido corregir.
- `GraduacionOcular::formatearDioptria()` se hizo **estática** para esto: la necesitan tanto
  la instancia (`dioptriaVisible()`) como `PrescripcionOcularController::evolucion()`, que
  arma un resumen sobre varios valores sin tener una fila de la que colgar el cálculo.

### La evolución de la esfera: dos series independientes, no una con secundario

Reusa `GraficoEvolucion.vue` (Etapa 6.3), con la misma decisión de dos series separadas que
ya tomó la evolución de un resultado de estudio —y por el mismo motivo—:

> Un ojo puede no tener corrección mientras el otro sí, y un punto de `GraficoEvolucion` no
> admite un `valor` nulo.

Si se usara el mecanismo de "principal/secundario" que sí usa una presión (sistólica y
diastólica **siempre llegan juntas**, del mismo evento), un ojo sin esfera en una receta
puntual rompería el punto entero. `PrescripcionOcularController::evolucion()` arma OD y OI
por separado, cada uno filtrando sus propios `null` y exigiendo dos o más puntos antes de
existir —misma regla de siempre: una línea de un punto no es una evolución—.

Se grafica **la esfera sola**, no un "poder equivalente" (esfera + cilindro/2). Aunque es una
convención oftalmológica real, es una cuenta que el papel no trae escrita, y el sistema
registra lo que se cargó, no una interpretación de eso (regla 1).

⚠️ **`prescripciones_oculares.fecha` es una fecha de calendario**, la misma trampa que ya
documentó la Etapa 9.4: `zona-horaria="UTC"` va como **string literal**, nunca un binding, o
el navegador corre el día en cualquier zona horaria negativa.

### Un descuido en la factory que hizo pasar un test con el caso equivocado

`PrescripcionOcularFactory::conOjos(od: [...])`, sin pasar `oi:`, deja el ojo izquierdo con
el **default de la factory** —que trae esfera y cilindro no nulos—, no en blanco. Un primer
test de "un ojo sin corrección no rompe la evolución del otro" pasaba con el caso equivocado:
el OI "sin corrección" en realidad tenía una esfera de sobra puesta por el default, y la
evolución devolvía dos series en vez de una. Hay que anular `esfera`/`cilindro`/`eje` a mano
cuando el test necesita un ojo realmente vacío.

## Turnos y recordatorios

Dos tablas con dos naturalezas muy distintas: `turnos` es dominio clínico que carga una
persona, y `recordatorios` es **dato derivado que nadie escribe a mano**.

### `turnos.fecha_hora` es el segundo `datetime` que carga una persona

El primero fue `mediciones.fecha` (paso 6.1), así que corre la misma regla: `aUtc()` al
guardar, `enSuZona()` al mostrar, la conversión vive en el FormRequest
(`TurnoGuardarRequest::fechaHoraEnUtc()`) para que no se pueda olvidar en una acción nueva, y
la precarga del formulario sale del servidor (`ahoraLocal`) y no de `new Date()`.

Lo que **cambia** respecto de una medición, y es la decisión del paso:

> Un turno en el FUTURO es el caso normal. No lleva ninguna validación de rango.

Una medición registra algo que ya pasó y por eso rechaza mañana; un turno se agenda
justamente para mañana, y también se carga hacia atrás para registrar que se fue. Poner una
`FechaNoFutura` por simetría con el resto de las fechas de la app haría imposible usar la
pantalla para lo que existe.

Las tres FK (`medico_id`, `centro_id`, `orden_estudio_id`) son metadato: `nullOnDelete`,
siguiendo la regla de la Etapa 7. `orden_estudio_id` cierra el circuito que la Etapa 9 había
dejado a medias —la orden dice qué hacerse, el turno cuándo, el estudio el resultado— y se
ofrecen **todas** las órdenes de la ficha, no solo las pendientes: un turno de control por un
estudio ya hecho es un caso real.

### La agenda ordena al revés que todo el resto de la app

Los demás listados van de lo más reciente a lo más viejo, porque muestran historia. Una
agenda muestra futuro: lo inminente es lo que importa, y un turno de mañana no puede quedar
debajo de uno de diciembre. Por eso `proximos` va de menor a mayor.

**La separación entre "lo que viene" y "ya pasaron" la hace el servidor**, no un `computed()`
en la pantalla: depende de la hora, y el reloj del navegador puede estar corrido —con él se
correría la mitad de la agenda—.

### `recordatorios`: la idempotencia es el diseño entero

> Los genera un observer. **No hay ninguna ruta que cree ni que borre un recordatorio.**

Lo único que hace una persona es marcarlo hecho (`RecordatorioController` tiene un solo
método, y esa ausencia _es_ la decisión: garantiza que la tabla no pueda entrar en un estado
que ningún origen justifique).

La clave de idempotencia es `origen_type` + `origen_id` + `tipo`, con **UNIQUE en la base**.
Un observer corre en cada guardado de su origen, así que generar el recordatorio tiene que
poder repetirse mil veces; el código lo resuelve buscando antes de crear, y el UNIQUE está
igual para convertir un bug de lógica en un error ruidoso en vez de una bandeja con el mismo
aviso repetido.

⚠️ **`paciente_id` NO entra en el UNIQUE**: el par (origen_type, origen_id) ya determina de
qué paciente es, y sumarlo permitiría dos filas que solo difieren en el paciente para el mismo
origen —justo el estado imposible que hay que prohibir—. Está igual en la tabla,
denormalizado, porque de él cuelga la autorización y porque "los pendientes de esta ficha" no
puede depender de resolver un polimórfico por fila.

**Sin soft deletes**: es dato derivado, se regenera con el próximo guardado del origen. Un
`deleted_at` acá solo lograría que el UNIQUE viera una fila fantasma y bloqueara la
regeneración.

### `GeneradorDeRecordatorios`: las cuatro filas de la tabla de verdad

| Lo que llega                | Lo que hace                          |
| --------------------------- | ------------------------------------ |
| instante `null`             | **Borra** el recordatorio si existía |
| no existe todavía           | Lo crea, `Pendiente`                 |
| existe y la fecha NO cambió | **Nada**, ni un `touch`              |
| existe y la fecha cambió    | La actualiza y lo **reabre**         |

⚠️ **Las dos del medio son el corazón del asunto, y son opuestas a propósito.**

Si la fecha **no** cambió no se toca nada, porque el observer corre también cuando alguien
edita el motivo del turno: resetear el estado ahí haría reaparecer como pendiente un aviso que
la persona ya resolvió, por haber corregido una falta de ortografía.

Si la fecha **sí** cambió se reabre —`estado` a `Pendiente`, `enviado_en` a `null`—, porque el
turno se movió de día: el "ya lo sé" que la persona dio antes era sobre otra fecha, y el mail
que ya salió decía un día que ya no es.

⚠️ La comparación de fechas va **formateada al segundo**, no con `equalTo()` sobre los
objetos: la base guarda segundos, y un microsegundo de diferencia entre el valor recién
calculado y el que volvió de una columna `datetime` haría ver como "cambió" algo que no
cambió, reabriendo el recordatorio de alguien en cada guardado.

### Los observers son finos porque no deciden casi nada

`TipoRecordatorio::horasDeAnticipacion()` declara _cuándo_ avisar (24 h para los dos tipos),
`GeneradorDeRecordatorios` sabe _cómo_ guardarlo, y el observer solo resuelve **cuál es el
instante del evento, o si ya no hay evento del que avisar**.

- **Cancelar un turno y borrarlo terminan en el mismo lugar** —pasar `null`—, que es lo que
  hace que haya una sola regla donde podría haber dos.
- `saved` cubre el alta, la edición **y restaurar** de la papelera (`restore()` llama a
  `save()`); agregar un método `restored` haría correr todo dos veces. Y con soft deletes,
  `deleted` dispara al mandar a la papelera sin pasar por `save()`, así que los dos caminos no
  se pisan.
- Un tratamiento avisa solo si está **activo y con fecha de fin**: sin `fin` no termina nunca
  (es un crónico), e inactivo ya se suspendió antes de llegar al final.

⚠️ **La anticipación es aritmética de instantes, sin zona horaria, y es deliberado.** "24
horas antes" da el mismo instante para todos; la alternativa —"el día anterior a las 9, hora
del usuario"— no tiene respuesta única cuando una ficha la comparten personas en husos
distintos, que es exactamente lo que habilita el pivote `paciente_usuario`. Mismo
razonamiento que `misalud:cerrar-tratamientos-vencidos`.

⚠️ Cuando el origen es una fecha de **calendario** (`tratamientos.fin` es `date`, que Carbon
lee a medianoche UTC), restarle 24 h da la medianoche UTC del día anterior: las 21:00 en
Argentina. Es una hora razonable para recibir "mañana termina tu tratamiento", pero **salió
así por la aritmética y no porque alguien la eligiera**.

⚠️ **`misalud:cerrar-tratamientos-vencidos` no dispara el observer**, porque escribe con un
`update()` masivo. Se dejó así: para cuando el comando cierra un tratamiento su `fin` ya pasó,
así que el recordatorio venció hace rato y el comando horario lo descarta solo. Cargar
cientos de modelos para producir un cambio que no se nota sería peor.

### `Recordatorio` es el primer modelo del dominio que NO cifra nada

Todas sus columnas son metadato del sistema: tipo, fecha, de qué fila salió, en qué estado
está. **El contenido no vive acá** —el motivo del turno, el medicamento del tratamiento están
cifrados en su propia tabla—. Por eso tampoco declara `$builder = ConsultaVigilada::class`: no
hay ninguna columna cifrada sobre la que un `where` pueda mentir. Si algún día necesitara un
texto propio, esa columna iría cifrada y el modelo pasaría a implementar `CifraDatos`.

⚠️ **Y por eso no tiene factory.** Una `RecordatorioFactory` tendría que inventarse un origen,
y el camino corto —crear un `Turno`— choca contra el UNIQUE: el observer de ese turno ya
generó su recordatorio antes de que la factory llegue a insertar el suyo. No es algo para
tapar con un `firstOrCreate`: es la tabla avisando que solo tiene un camino de escritura. En
un test se crea el turno y se deja que el observer trabaje; para forzar un estado que tardaría
en producirse (uno ya enviado) se usa `forceFill()`, que además queda explícito como "esto no
es un camino normal".

### Cuatro estados, porque hay dos historias conviviendo

`EstadoRecordatorio`: lo que hizo el **sistema** (`Pendiente` → `Enviado`, o → `Vencido` si
nadie pudo avisar en tiempo) y lo que hizo la **persona** (cualquiera de esos →
`Completado`). Un `Enviado` sigue abierto _para la persona_: la pantalla muestra los dos
juntos, y lo que `Enviado` evita es mandar el mismo aviso dos veces.

⚠️ **Reabrir un recordatorio ya enviado lo deja en `Enviado`, no en `Pendiente`.** El mail
salió y eso es un hecho del pasado: volverlo a pendiente haría que el comando horario lo
mandara de nuevo, que es justo lo que `enviado_en` existe para evitar.

`EstadoTurno` tiene tres casos y no cuatro: **no hay "ausente"**. Que alguien no haya ido es
información que el sistema no necesita distinguir de un turno cancelado —las dos cosas
significan "no pasó"— y separarlas invitaría a que la pantalla opine sobre por qué (regla 1).
Mismo criterio que `EstadoOrdenEstudio`.

### `misalud:enviar-recordatorios`: el segundo comando del scheduler

Corre **cada hora**, no una vez al día. Un recordatorio vence a cualquier hora —la de su turno
menos la anticipación—, así que un job diario lo mandaría con hasta 24 horas de atraso, que
para un aviso de 24 horas de anticipación es exactamente inútil.

Lleva `withoutOverlapping()`: la transición de estado (`Pendiente` → `Enviado`) ya evita mandar
dos veces, pero dos corridas simultáneas podrían leer la misma fila antes de que ninguna la
marque. Usa el lock de caché y la tabla `cache_locks` existe.

### ⚠️ No se avisa de algo que ya pasó, y el corte NO es una constante

Si el servidor estuvo caído una semana, mandar "tenés un turno" por un turno que ya fue es peor
que no mandar nada: no se puede actuar, y encima hace desconfiar del resto de los avisos.

La tentación era una "ventana de gracia" de N horas. No hace falta, y esto es lo lindo del
modelo: `Recordatorio::instanteDelEvento()` es `fecha + anticipación`, así que **el corte se
deduce** y la condición es literalmente _"¿el evento ya pasó?"_. Sin número mágico, y sin que
cambiar la anticipación de un tipo deje el corte desalineado.

Esos quedan en `Vencido`: no se mandan, pero **tampoco se pierden**, así la pantalla puede
distinguir "no te avisamos" de "te avisamos y no lo resolviste".

`instanteDelEvento()` sirve para otra cosa además: es la inversa exacta de lo que hizo el
generador, así que el mail sabe cuándo es el turno **sin abrir el polimórfico y sin descifrar
nada del origen**.

### Un fallo no se lleva la tanda

El `try`/`catch` va **por destinatario**: un mail rebotado no puede impedir que salgan los
demás ni que se procese el resto de los recordatorios.

⚠️ Un recordatorio se marca `Enviado` si salió **al menos uno** de sus mails, y es una decisión
con filo: si eran dos destinatarios y el segundo falló, esa persona se queda sin aviso. La
alternativa —no marcarlo y reintentar— le mandaría al primero el mismo mail cada hora hasta que
el evento pase, hasta veinticuatro veces. Entre perder un aviso y volverse insoportable gana lo
primero, y el fallo queda en el log con el id del usuario para ir a buscarlo.

⚠️ **Al log no va ningún dato clínico**, ni el nombre del paciente ni la dirección de mail: los
ids alcanzan para encontrar a quién le falló, y un log es un archivo de texto que termina en
cualquier lado. Misma regla que los errores de Socialite.

### A quién se le manda: dos filtros, cada uno con su motivo

- **Solo quien puede editar** (propietario y cuidador). Un `Lector` no puede hacer nada con el
  aviso —no agenda, no cancela, no marca hecho—, así que para él sería solo correo.
- **Solo mails verificados.** Un mail sin verificar es una dirección que nadie probó que sea
  suya, y puede ser un tipeo que apunta a un tercero. La app ya exige verificarlo para entrar;
  mandar un aviso con el nombre de un paciente a una dirección no probada sería el único lugar
  donde eso no se respeta. Si nadie verificó, el aviso queda `Pendiente` y se reintenta —y si
  nunca se verifica, vence solo—.

### El mail: uno por destinatario, sin `ShouldQueue`, y en texto plano

**Sin `ShouldQueue`, a propósito.** Lo dispara un comando del scheduler, que ya corre en
segundo plano: encolarlo no lo haría más asíncrono, solo agregaría un `queue:work` del que
depender. Y en hosting compartido un worker se cae en silencio, así que el modo de falla sería
**quedarse sin avisos sin que nadie se entere** —el peor posible para lo único que esta app
hace por su cuenta—.

**Uno por destinatario, nunca uno con varios `To`.** Dos razones que apuntan al mismo lado: la
hora (cada uno la lee en su propia zona, y un solo mail solo puede traer una) y la privacidad
(varios `To` o `Cc` le muestran a cada uno la dirección de los demás).

**Texto plano y no HTML.** Un aviso de tres líneas no necesita maquetado, se ve bien en
cualquier cliente y no depende de que carguen estilos. Y de paso evita el layout de markdown de
Laravel, que viene en inglés y habría que traducir entero para cumplir la regla de idioma.

### ⚠️ Qué dice el mail y qué no

Un mail sale del sistema **sin cifrar** y queda en el servidor de correo de quien lo reciba,
fuera de todo lo que esta app controla. Así que dice **cuándo**, no **qué**:

| Va en el mail                 | Se queda detrás del login  |
| ----------------------------- | -------------------------- |
| De qué clase es el aviso      | El motivo del turno        |
| El nombre del paciente        | Qué médico, en qué centro  |
| La fecha y la hora del evento | Qué medicamento, qué dosis |

El nombre del paciente sí va, y es la única concesión: sin él, un cuidador que administra tres
fichas recibe un aviso que no puede usar.

⚠️ **El asunto no lleva ningún nombre.** Es la parte más expuesta de un mail: aparece en la
pantalla bloqueada del teléfono, en la vista previa de la bandeja, y es lo que más termina en
los registros de cualquier servidor por el que pase. Por eso vive en
`TipoRecordatorio::asunto()` y no se arma con datos de nadie. Hay un test que lo fija.

Verificado corriendo el comando de verdad contra el mailer de log —no solo con `Mail::fake()`—
y leyendo el mail renderizado: `Content-Type: text/plain`, el motivo aparece **0 veces**, el
nombre del paciente 1, y la hora en la zona de la cuenta.

⚠️ El ensayo en seco (`--seco`) muestra la hora **etiquetada como UTC**: en un ensayo no hay un
destinatario del que tomar la zona. Sin la etiqueta, quien compare esa salida con el mail que
llega creería que se contradicen.

### ⚠️ Un perfil de Chrome propio para los scripts de verificación

`puppeteer.launch()` empezó a fallar con `Failed to launch the browser process: Code: 0` y
stderr vacío —nada que diagnosticar—. Se resolvió pasando un `userDataDir` temporal y único
por proceso. Vale como default para todo script nuevo: no pelea por el lock de un perfil que
otro proceso tenga abierto, y **no toca el Chrome del usuario**.

## Casilla de recetas (IMAP)

`cuentas_mail` es la casilla de la que se importan las recetas que llegan por mail. Cuelga
del **usuario** como un catálogo —es una sola y de ahí salen las recetas de toda la familia
que uno administra—, pero no es un catálogo: no tiene semillas y no puede tenerlas, porque una
semilla compartida sería la contraseña de alguien en el listado de otro. Por eso tiene su
`CuentaMailPolicy` propia en vez de colgarse de `CatalogoPolicy`, cuya mitad de reglas sobre
semillas no significaría nada acá.

### ⚠️ Sin soft deletes, y es una decisión de seguridad

Es la primera tabla que se aparta de "soft deletes en las entidades principales". Acá vive una
**contraseña**: con `deleted_at`, borrar la casilla la dejaría guardada para siempre, que es lo
contrario de lo que espera quien la da de baja. **Borrar borra.** Las recetas ya importadas no
se van con ella —son documentos de la persona— y por eso la FK del paso 12.2 va con
`nullOnDelete`.

### ⚠️ `direccion` y no `usuario`: un atributo tapa a una relación

El plan llamaba `usuario` a la columna del login. No se puede: el modelo ya tiene una relación
`usuario()` hacia su dueño —el nombre que usan los cinco catálogos para lo mismo— y en Eloquent
**un atributo con el nombre de una relación la tapa**. `$cuenta->usuario` devolvería el string
del login en vez del `User`, y sin ningún error. Renombrar la columna es más seguro que
renombrar una convención que ya se repite cinco veces.

`direccion` además es lo que la pantalla necesita decir, y se valida como mail: en Gmail,
Outlook y cualquier casilla de hosting el usuario de IMAP **es** la dirección, así que exigirlo
atrapa el tipeo en el campo en vez de en un login rechazado.

### ⚠️ La contraseña es de SOLO ESCRITURA, y de ahí sale el resto del diseño

No viaja al navegador: ni enmascarada, ni con su largo real. El único motivo para mandarla
sería precargar el formulario de edición, y eso la pone en un HTML que queda en la caché del
navegador. Tres consecuencias que si no, parecen arbitrarias:

- **Al editar, el campo vacío significa "dejá la que está"** —lo valida el FormRequest
  (`required` al crear, `nullable` al editar) y `update()` saca la clave del array—. Si fuera
  obligatoria siempre, cambiar la carpeta obligaría a volver a tipear la contraseña de
  aplicación.
- **La prueba de conexión se hace sobre lo GUARDADO**, no sobre el formulario. Probar el
  formulario sería más cómodo, pero el de edición no tiene la contraseña: probaría una casilla
  sin credenciales. Y probar lo guardado es lo que sirve dentro de seis meses, cuando la
  pregunta ya no es "¿lo escribí bien?" sino "¿sigue andando?".
- El modelo lleva `$hidden = ['password']` como red por si alguna respuesta futura serializa el
  modelo entero por descuido.

### La encriptación sale del puerto, así que los puertos son dos

993 es IMAP sobre TLS y 143 es STARTTLS: `CuentaMail::encriptacion()` lo deduce, y la
validación acepta **solo esos dos** justamente para que la deducción no tenga casos raros. Un
desplegable de "método de encriptación" es una pregunta que quien configura su casilla no puede
contestar. No hay forma de pedir "sin encriptación", ni de desactivar `validate_cert`: por esa
conexión viaja la contraseña.

### Configuración sí, estado no

La línea que separa el 12.1 del 12.2. `cuentas_mail` tiene los datos con los que **conectarse**;
`sincronizado_hasta` —que es lo que el comando de sincronización produce— no se creó todavía,
porque sería una columna que ninguna línea escribe y que la pantalla mostraría siempre en
"nunca". Mismo criterio que `ordenes_estudio.estudio_id` en el 9.1.

Lo mismo, por otro motivo, con el resultado de la prueba: **no se guarda en ninguna parte.** Un
"última prueba: anduvo" envejece solo —una contraseña de aplicación se revoca— y la pantalla lo
mostraría en verde justo cuando dejó de ser cierto.

### El paquete: `webklex/laravel-imap`, sin publicar su config

⚠️ **`ClientManager` NO se resuelve del contenedor.** El binding que registra el paquete hace
`new ClientManager(config('imap'))`, y como este proyecto **no publica** `config/imap.php` eso
pasa `null` a un parámetro que no lo acepta. `ProbadorDeCasilla` lo construye con un array
vacío —ahí el paquete carga sus propios defaults— y manda explícito en `make()` todo lo que
importa, al lado del comentario que explica cada elección. Es justo el tipo de cosa que alguien
"limpia" convirtiéndola en inyección por constructor.

`timeout` va en **10 segundos** y no en el default de 30: esto corre dentro de una petición web
con alguien mirando la pantalla, y dos esperas de 30 (socket y login) se pasan del tope de
ejecución de PHP — la persona vería un error del servidor en vez del resultado de la prueba.

### ⚠️ Los tres fallos son tres cosas distintas: por eso `EstadoDeConexion` no es un booleano

| Falló…     | Qué significa de verdad                   |
| ---------- | ----------------------------------------- |
| el socket  | el host, el puerto o la red del servidor  |
| el login   | la dirección o la contraseña              |
| la carpeta | todo anda, pero se va a importar **nada** |

El tercero es el que más vale y el que un booleano no puede decir: **una carpeta mal escrita
deja la sincronización en verde encontrando cero mensajes, para siempre y sin ningún síntoma.**
Y es fácil de escribir mal, porque el separador lo elige cada servidor: la misma carpeta es
`INBOX/Recetas` en uno y `INBOX.Recetas` en otro. Por eso ese caso lista **las carpetas que sí
hay**, que es lo que resuelve el problema en un intento en vez de en cinco.

Un "no se pudo conectar" genérico manda a revisar la contraseña cuando el problema es la
carpeta, y ahí la persona cambia lo único que estaba bien.

### ⚠️ La excepción de "contraseña equivocada" NO es `AuthFailedException`

Medido contra `imap.gmail.com` con credenciales inventadas: lo que llega es
**`ImapServerErrorException`** con el mensaje `NO [AUTHENTICATIONFAILED] Invalid credentials`.
`AuthFailedException` es la que dice el nombre y la que uno atrapa por reflejo, pero el paquete
solo la usa en un camino que un servidor real no toma. Atrapar únicamente la "obvia" dejaba un
login rechazado cayendo en "error inesperado" —el peor lugar, porque ese mensaje no nombra la
contraseña, que es justo lo que hay que arreglar—.

Esto se encontró **probando contra un servidor real antes de escribir la pantalla**, no leyendo
el `@throws` del paquete, que decía otra cosa.

⚠️ **Y por eso `conectarYRevisar()` tiene DOS `try` separados, que no es prolijidad.**
`ImapServerErrorException` la tira **cualquier** comando que el servidor conteste con `NO`. El
mapeo "un `NO` es un rechazo de credenciales" solo es correcto en el primer bloque, donde el
único comando que se manda después del saludo es el LOGIN. Con un solo `try` envolviendo todo,
un `NO` al abrir la carpeta se leería como "contraseña equivocada".

### ⚠️ La contraseña no puede terminar en un log ni en un toast

Los mensajes de error de IMAP vienen del servidor y pueden traer partes del comando que los
provocó —y el comando que nos interesa es `LOGIN`—. Todo texto que salga de una excepción pasa
por `queDijoElServidor()`, que borra la contraseña **antes de que el string exista para
cualquier otro uso**: la misma función alimenta el aviso en pantalla y el log, así que no hay un
camino donde uno esté saneado y el otro no. Al log van además solo ids, nunca la dirección —
misma regla que los errores de Socialite y de `misalud:enviar-recordatorios`.

El caso de credenciales rechazadas va **sin detalle a propósito**: lo que contesta el servidor
no agrega nada a "rechazó la dirección o la contraseña", viene en inglés, y es el mensaje con
más chances de traer el LOGIN adentro.

### Los filtros: una dirección o un dominio por línea

Vacío significa **todo lo que haya en la carpeta**, y es una opción válida: quien ya armó una
regla en su correo para que las recetas caigan en una carpeta propia filtró antes que nosotros.
Se acepta un dominio suelto además de una dirección entera porque una obra social manda desde
`noreply@` hoy y desde `avisos@` el mes que viene.

Llegan de un `<textarea>` —un campo de a una línea es lo que una persona puede leer y corregir;
un array de inputs con botoncitos de "+" y "−" es lo contrario de lo que esta app necesita— y el
FormRequest los parte **antes** de validar, igual que `NormalizaDecimales` con la coma: si la
regla `array` viera el string crudo, rechazaría algo perfectamente escrito. Se parte por líneas
**y por comas**, y se normaliza a minúsculas.

### La importación: `misalud:sincronizar-recetas`

Cada hora por el scheduler, más el botón **"Importar ahora"** en la pantalla de la casilla —que
no es un lujo: sin él, comprobar que una casilla recién configurada importa de verdad obliga a
esperar hasta una hora o a entrar por SSH—.

Una receta **es su archivo**. La fila de `recetas` es la ficha del mail del que salió y el PDF
cuelga como un `Adjunto` de tipo `Receta`: `Receta` es el **séptimo dueño de archivos** y
declaró `TieneArchivos` sin tocar nada de autorización, igual que los seis anteriores.

### ⚠️ La separación que hace testeable el paso entero

Es la decisión de arquitectura del 12.2, y sin ella no habría tests de nada:

| Pieza                                 | Qué hace                                                        |
| ------------------------------------- | --------------------------------------------------------------- |
| `LectorDeCasilla`                     | **Lo único que habla IMAP.** Traduce mensajes a DTOs y nada más |
| `MensajeDeCasilla`/`AdjuntoDeCasilla` | Los DTOs: no saben nada de IMAP                                 |
| `SincronizadorDeRecetas`              | **Todas las decisiones**: ventana, filtros, dedupe, qué adjunto |
| `SincronizarRecetas`                  | El comando: recorre casillas y aísla los fallos                 |

El lector es tonto **a propósito**, porque es la única pieza que no puede tener tests
automáticos —necesitaría un servidor de verdad—. Todo lo que decide algo está del otro lado de
los DTOs, y los 27 tests del sincronizador corren **sin red**, reemplazando el lector por un
doble. Sin esta costura, probar "una receta no se importa dos veces" habría necesitado una
casilla real con un mail puesto a mano, y nadie lo habría escrito.

Lo que se paga: el lector queda cubierto solo por verificación a mano. Por eso las dos reglas
sueltas que sí viven ahí —descartar adjuntos `inline` y el reemplazo del `Message-ID`— están una
documentada con su motivo y la otra **extraída a una función pura**
(`MensajeDeCasilla::identificadorPara()`) justamente para poder probarla.

### ⚠️ La ventana NO es "desde el día 1 del mes"

Era lo que decía el plan original, y tiene un agujero: una receta que llegó el 31 de enero
desaparece el 1 de febrero aunque nadie la haya usado. El contador del mes de la bandeja es una
**vista sobre lo importado**, no el criterio con el que se importa.

- **Primera corrida** (`sincronizado_hasta` en `null`): `misalud.recetas.dias_iniciales` hacia
  atrás, 60 por defecto y configurable por `RECETAS_DIAS_INICIALES`.
- **Después**: desde `sincronizado_hasta` **menos 7 días de solapamiento**.

El solapamiento no es paranoia: **el `SINCE` de IMAP compara fechas, no instantes** —es por día,
contra la fecha interna del servidor, y es `>=`—, y un mail puede entregarse tarde o aparecer
fuera de orden. Reimportar es gratis (lo frena el UNIQUE del índice ciego), así que de más no
cuesta nada y de menos sería un agujero que nadie nota.

### ⚠️ Hasta dónde avanza la marca, y por qué no siempre a `now()`

| Situación                           | La marca va a…                            |
| ----------------------------------- | ----------------------------------------- |
| se vio la ventana entera            | el instante en que **arrancó** la corrida |
| se truncó por el tope de la corrida | la fecha del **último mail mirado**       |

El primero es al inicio y no a `now()` porque un mail que llegó mientras corríamos no puede
quedar del lado ya revisado.

El segundo es lo que hace avanzar a una casilla con mucho correo acumulado: si la marca fuera al
inicio de la corrida, todo lo que quedó sin mirar caería fuera de la próxima ventana y **se
perdería**; y si no se moviera, la corrida siguiente traería los mismos cincuenta y la casilla no
avanzaría nunca. De ahí también que el lector devuelva los mensajes **en orden ascendente**: no
es cosmético, la marca depende de eso.

⚠️ **Y nunca va hacia atrás.** Cuando todos los mails de la ventana caen en la zona de
solapamiento, la fecha del último es anterior a la marca actual: moverla ahí agrandaría la
ventana en cada corrida hasta volver a mirar la casilla entera.

El tope existe porque cada mensaje se trae con su cuerpo y sus adjuntos **en memoria**, y una
casilla sin filtros apuntada a la bandeja de entrada puede tener miles de mails en la ventana
inicial: sin tope, esa primera corrida se muere por tiempo de ejecución y no termina nunca.

### ⚠️ Qué mail se convierte en receta

Tres cortes, y los tres descartan en silencio porque ninguno es un error:

1. **El remitente tiene que pasar el filtro.** Sin filtros pasa todo —quien ya armó una regla en
   su correo para que las recetas caigan en una carpeta propia filtró antes que nosotros—. Un
   filtro de dominio acepta subdominios (`osde.com.ar` vale para `avisos.osde.com.ar`), pero no
   un dominio que apenas termina parecido (`no-osde.com.ar` no entra).
2. **Tiene que traer al menos un archivo servible.** "Tu receta está lista, entrá a nuestro
   sitio" no trae ninguna, y guardar una fila por ese mail llenaría la bandeja de recetas que no
   se pueden mostrar.
3. **No tiene que estar ya importado.**

⚠️ Los adjuntos se descartan **de a uno, no de a mail entero**: que uno se pase de los 12 MB o
sea un `.docx` es un resultado normal. Si el mail trae la receta en PDF y además un instructivo
en Word, se importa la receta.

⚠️ **Se descartan los adjuntos `inline`, que son el logo de la firma.** Media empresa manda
mails con firma en HTML y cada imagen de esa firma viaja como adjunto: sin este filtro, una
casilla sin filtros de remitente importa el logotipo de la farmacia como si fuera una receta —un
JPG de 4 KB que pasa la lista blanca sin problema—. Lo que los distingue es el
`Content-Disposition`. Un adjunto **sin** disposición declarada se acepta: hay clientes que no
la mandan, y rechazarlo perdería recetas de verdad.

⚠️ **El mime se deduce del contenido con finfo, nunca del `Content-Type` del mail**, que lo
escribe alguien de afuera. Es la misma regla que `getMimeType()` vs `getClientMimeType()`, y acá
pesa más. Para eso `ArchivoService` ganó `mimeAceptado()` —que pregunta sin escribir nada, lo que
permite descartar el logo **antes** de abrir una transacción— y `guardarContenido()`, para
archivos que nunca fueron un `UploadedFile`.

### ⚠️ El UNIQUE de deduplicación va por USUARIO, no global

El plan pedía `message_id_hash UNIQUE` a secas, y eso tiene un bug concreto: el `Message-ID` es
único **del mensaje**, así que si la farmacia le manda el mismo mail a dos personas que las dos
usan MiSalud, la segunda no podría importarlo nunca —y en silencio, porque parece "ya estaba"—.

Por usuario además sobrevive a que alguien borre y vuelva a configurar la casilla, y deduplica
cuando dos casillas de la misma persona reciben el mismo mail por un alias.

⚠️ **La consulta de deduplicación va con `withTrashed()`.** El UNIQUE de la base no sabe de soft
deletes: una receta en la papelera sigue ocupando su hash, así que sin eso el `create()` se
estrellaría contra la base en vez de contarse como repetida. Y es el comportamiento correcto:
esa receta todavía existe y se puede restaurar.

⚠️ **Un mail sin `Message-ID` necesita un reemplazo determinístico.** Es raro pero existe, y sin
eso su hash queda nulo —y en MySQL un UNIQUE admite todos los nulos que quiera—: ese mail se
reimportaría **en cada corrida, para siempre**, escribiendo otra copia cifrada de su adjunto cada
hora. Es el peor tipo de bug: crece solo y nadie lo mira.

### Aislamiento de fallos, en dos niveles

- **Por casilla**, en el comando: una contraseña de aplicación revocada es el caso más probable
  de todos —se revocan solas cuando alguien cambia la clave de su cuenta de Google— y no tiene
  por qué dejar sin importar a las otras casillas. El comando devuelve `FAILURE` si alguna falló,
  pero las demás ya importaron.
- **Por mensaje**, en el sincronizador: un mail roto no se lleva la tanda de su propia casilla.

⚠️ **El mensaje de la excepción no se imprime ni se muestra en pantalla.** Puede venir del
servidor IMAP y traer partes del comando que lo provocó (ver `ProbadorDeCasilla`). Tanto el
comando como el toast mandan a **probar la conexión**, que es la pantalla que traduce el fallo a
algo que se entiende y se puede arreglar.

⚠️ **Si la transacción se cae hay que borrar del disco lo que ya se escribió.** Un rollback
deshace las filas pero no los archivos, y un archivo cifrado que nada referencia es invisible y
para siempre. Para eso existe `ArchivoService::borrarRuta()`.

### Lo que queda afuera del 12.2, y por qué

La regla de este paso: **entra la columna que la importación escribe.**

- **`recetas.paciente_id` NO existe.** El plan la ponía y no se puede escribir: un mail de la
  farmacia **no dice de quién es la receta**, y adivinarlo sería cargársela a un familiar
  equivocado. Peor: una `paciente_id` nullable rompería el invariante de `RegistroClinicoPolicy`
  —"un paciente nulo es NO"— y obligaría a inventar un camino de autorización para un estado que
  ninguna pantalla puede producir. Así que la receta pertenece a **quien administra la casilla**
  (`usuario_id`, no fillable), que es lo verdadero: el mail llegó a su correo.
  ⚠️ **El día que una receta se pueda asignar a un paciente hay que contestar si un cuidador de
  esa ficha puede verla.** Hoy la respuesta es que no.
- **`fecha_uso` tampoco.** La escribe la misma acción que pone `EstadoReceta::Usada`, que es la
  bandeja del 12.3.
- **"Vencida" no es un estado guardado**: se deriva de `fecha_recepcion + vigencia_dias`
  (`Receta::estaVencida()`), igual que la edad sale de la fecha de nacimiento (regla 4).
  Guardarla obligaría a un segundo comando del scheduler cuyo único trabajo sería corregir una
  cuenta que se hace sola, y entre que vence y que ese comando corre la pantalla mostraría como
  "disponible" una receta que ya no sirve.

El vencimiento se cuenta **desde que llegó el mail**, no desde que se importó: si el servidor
estuvo caído tres días, la receta no gana tres días de vida.

### Qué NO se le toca a la casilla

La pantalla promete "solo lee: no manda mails ni borra nada", y eso lo cumplen dos cosas
explícitas en `LectorDeCasilla`: **`FT_PEEK`** (traer el mensaje sin ponerle la marca `\Seen`) y
**`leaveUnread()`** en la consulta. `FT_PEEK` ya es el default del paquete y va escrito igual: si
algún día cambia, el síntoma sería que la app le marca como leídos los mails a alguien, y eso no
lo descubre ningún test.

### La bandeja de recetas (paso 12.3)

`/recetas` contesta **una sola pregunta: ¿qué receta puedo usar hoy?** Va arriba en el menú, al
lado de Pacientes —se abre en el mostrador de la farmacia—, y la casilla que la alimenta queda
abajo, con los ajustes.

- **Las disponibles van arriba, de la que vence primero a la que vence último.** Es una cuenta
  regresiva: una receta que vence pasado mañana no puede quedar debajo de una que vale todo el
  mes. Es el segundo listado que ordena al revés que el resto de la app, después de la agenda de
  turnos, y por el mismo motivo.
- **La separación entre disponibles e historial la hace el servidor**, no un `computed()`:
  "vencida" depende de la hora, y con el reloj del celular corrido una receta vencida aparecería
  como usable justo en el mostrador. La pantalla recibe las dos listas ya separadas.
- **El vencimiento se dice con palabras** ("vence mañana", "venció el 3/10") **y nunca con un
  color de alarma**: es un dato, no un juicio (regla 1).
- **"Vence mañana" cuenta días de calendario de la persona**, medianoche contra medianoche en su
  zona, no horas divididas por 24: faltando diecinueve horas, "mañana" tiene que querer decir
  mañana para quien lo lee.

⚠️ **El contador del mes cuenta en el mes de la persona, no en el de UTC.** `fecha_recepcion` es
un instante: una receta que llegó el 31 a las 22:00 en Argentina ya es día 1 en UTC, y contando
en UTC aparecería en el mes siguiente. Cuenta también las usadas —la pregunta es cuántas
**llegaron**, no cuántas quedan— y es una vista sobre lo importado, no el criterio con el que se
importa.

**Marcar usada es su propia ruta y su propio FormRequest** (`PUT /recetas/{receta}/uso`), no un
campo de la edición: es lo que se toca en el mostrador, muchas veces, y la vigencia casi nunca.
Mezclarlos obligaría a mandar la vigencia cada vez que se marca una receta. `fecha_uso` llegó recién
acá, con la única acción que la escribe, y **no es fillable**: solo la tocan `marcarUsada()` y
`volverADisponible()`, así que el estado y la fecha no pueden quedar desparejos.

- **Deshacer el uso no le devuelve la vigencia.** Lo que se deshace es la marca, no el paso del
  tiempo: si ya venció, sigue vencida.
- **Ampliar la vigencia sí revive una receta vencida**, y es el caso real que lo motiva: una
  receta de crónico que vale 90 días entra con el default de 30. Se valida de 1 a 365 días —se
  rechaza lo que no puede existir, el mismo criterio que la validación ocular—.
- Lo que **no** se edita es remitente, asunto y fecha de llegada: son lo que dice el mail, y
  cambiarlos sería reescribir de dónde salió el documento.

⚠️ **Borrar una receta impide que vuelva a entrar.** Va a la papelera, y como la papelera sigue
ocupando su `message_id_hash` (y la deduplicación la mira a propósito), la próxima importación la
cuenta como repetida. Es justo lo que se quiere al borrar algo que entró y no era una receta —el
PDF de una promoción—: si volviera en la próxima corrida, borrarlo no serviría de nada.
Verificado de punta a punta, no solo con un test: borrarla en la pantalla y volver a importar los
mismos tres mails dejó "3 repetidas" y la receta sin volver.

⚠️ **El recordatorio de "receta por vencer" NO se puede hacer todavía**, aunque `vence()` exista.
`recordatorios.paciente_id` es obligatorio —de él cuelga la autorización de los avisos— y una
receta no tiene paciente. Queda trabado por la misma decisión que la asignación de recetas a
pacientes.

### ⚠️ Tres trampas de los scripts de verificación, encontradas acá

Las tres hicieron que el script informara fallas que no existían, que es el modo de falla
espejo del de `revisar-mobile.mjs` con la cookie (informar verde midiendo nada):

- **Afirmar sobre "el toast" sin esperar a que el anterior DESAPAREZCA lee el mensaje viejo.**
  Tocar la X no alcanza: sonner anima la salida y el nodo sigue en el DOM. Hay que esperar a que
  no quede ninguno. Misma familia que esperar a que el sheet termine de entrar antes de medirlo.
- **Un canario de un solo caracter no sirve.** El chequeo de "¿se filtró la contraseña?" usaba
  `'x'` como clave y daba positivo… porque hay una `x` en el propio texto en español del enum.
  La clave de prueba tiene que ser larga y distintiva.
- **Un `fetch` con `X-Inertia` y una versión inventada no devuelve el JSON**: Inertia contesta
  409 con la página entera, y el chequeo pasa midiendo un cuerpo que no es el que creía. Tampoco
  sirve `#app[data-page]`, que se queda con la página inicial. Lo correcto es mirar el tráfico
  real (`page.on('response')` filtrando por el header `x-inertia`).

Y una cuarta, de método: **el script tiene que limpiar la base ANTES y no solo después.** Si una
corrida muere a mitad, la casilla que quedó hace fallar el alta de la siguiente por dirección
repetida, y de ahí en adelante todo mide cualquier cosa.

Dos más que aparecieron verificando el 12.2:

- ⚠️ **Registrar un doble en el contenedor DESPUÉS de resolver quien lo usa no hace nada.** El
  script de verificación hacía `app(SincronizadorDeRecetas::class)` y recién entonces registraba
  el lector falso: el sincronizador ya tenía inyectado el real, y la verificación salió a buscar
  un servidor IMAP de verdad. El doble se registra primero, o se vuelve a resolver el consumidor
  después.
- ⚠️ **`npm run build` después de tocar un `.vue`, antes de verificar en el navegador.** Un
  `artisan serve` sirve el bundle compilado: sin rebuild, el script mide la pantalla **anterior**.
  Acá informó que faltaba un botón que estaba escrito y andaba. Y ojo con el hijo `php -S`, que
  sobrevive a que se mate al `artisan serve` padre: hay que buscar quién escucha el puerto, no el
  comando.

## Contactos y envío de documentación

Mandarle la receta a la farmacia, la orden a la obra social para autorizar, la receta de
anteojos a la óptica. Es **el único lugar de la app que saca documentos clínicos hacia afuera**,
y eso ordena todas las decisiones de la etapa.

| Pieza                    | Qué hace                                                    |
| ------------------------ | ----------------------------------------------------------- |
| `Contacto`               | La libreta: a quién se le manda                             |
| `DocumentosEnviables`    | Qué se ofrece para sumar a un envío (el **ámbito**)         |
| `EnvioGuardarRequest`    | **Autoriza cada archivo** contra su dueño, antes de validar |
| `EnviadorDeDocumentos`   | Descifra, manda **síncrono** y registra el resultado        |
| `EnvioDeDocumentos`      | El mailable: `From` la app, `Reply-To` la persona           |
| `Envio`/`ArchivoEnviado` | El historial, con **fotos del momento** y no referencias    |

### ⚠️ El destino es SIEMPRE un contacto de la libreta

Nunca una dirección tipeada en el momento. Un tipeo en una dirección escrita apurada manda una
historia clínica a un desconocido, sin forma de deshacerlo. En la libreta la dirección se
escribió una vez, con calma, y en el envío se elige por nombre — con la dirección completa a la
vista, para revisarla. **El destinatario no viene elegido** ni siquiera cuando hay uno solo, y el
botón final dice a quién y cuántos ("Mandar 2 documentos a OSDE"), para que se lea antes de
tocarlo.

### Los contactos: del usuario, pero no un catálogo, y sin soft deletes

Se parecen a un catálogo —son del usuario, sirven para toda la familia— pero el patrón de
catálogos indexa el **nombre**, y la identidad de un contacto es su **dirección**: "Farmacia
Central" puede tener dos sucursales con dos mails. El UNIQUE va por `email_hash` y no hay
semillas. `ContactoPolicy` es la cuarta Policy con la forma "¿es tuyo?" (casilla, receta,
contacto, envío); se dejó repetida porque cada una tiene algo propio, y si aparece una quinta
sin nada propio, ese es el momento de juntarlas.

⚠️ **Sin soft deletes, por un bug medido en el resto del proyecto** (ver "Estado"): un registro
en la papelera sigue ocupando su hash en el UNIQUE, la validación no lo ve, y volver a cargarlo
da un 500. Nada necesita un contacto borrado —el historial guarda su propia foto de la
dirección—, así que borrar borra y la dirección queda libre.

"Pueden salir de una cobertura", como pide el plan: el panel de la cobertura tiene "Guardar como
contacto", que abre el alta con el nombre de la entidad y el tipo. **El mail lo pone la
persona**: una cobertura no lo tiene, y adivinarlo sería mandar documentos a cualquier lado. Una
cobertura que esa persona no puede ver se ignora **en silencio**, sin 403, para no confirmar que
el id existe en otra ficha.

### ⚠️ El envío arranca desde un documento, y ofrece solo los de la MISMA ficha

El botón "Enviar" está al lado de cada documento —receta, orden, estudio, receta de anteojos—, no
en una pantalla en blanco. Así se sabe de qué paciente se trata, y `DocumentosEnviables` ofrece
para sumar **solo lo de esa misma ficha** (la credencial junto con la orden, que es lo que pide
una obra social para autorizar). **Nunca los de otro familiar**: mezclar fichas en un mismo mail
—la receta de uno en la obra social de otro— es el error más caro de este módulo. Una receta
importada ofrece las otras recetas de la casilla.

Los candidatos salen de las **relaciones** del paciente y no de un `where paciente_id`, así lo que
está en la papelera queda afuera solo. Y **cada candidato pasa igual por `AdjuntoPolicy`**: la
consulta decide qué es pertinente, la Policy quién puede verlo.

### ⚠️ Cada archivo se autoriza AL MANDAR, contra su dueño

La lista que mostró el armado del envío es una comodidad, no una autorización. Un id ajeno puesto
a mano en el formulario —de la ficha de otra persona, mandado a la propia casilla— es la forma
de filtrar datos con este módulo, y por eso `EnvioGuardarRequest::authorize()` pasa **cada**
archivo por `AdjuntoPolicy`, igual que si se lo intentara abrir. Y lo hace **antes de validar**:
al revés, un id ajeno con otro campo inválido contestaría con un error de campo y confirmaría que
el archivo existe. Verificado en Chrome, no solo con un test: armar un envío con el archivo de
otra cuenta da 403, forzarlo en el formulario da 403, y no sale ningún mail.

⚠️ **Mandar pide lo mismo que ver**, también para un `Lector`. Pedir más no protegería nada:
quien puede abrir el PDF ya lo puede reenviar desde su propio correo. Es una decisión a revisar
en la **Etapa 14**, que es donde se diseñan los roles de quien comparte una ficha.

### ⚠️ Síncrono y sin `ShouldQueue`, y acá el motivo es de seguridad

En los avisos (11.2) el motivo era no depender de un worker que en hosting compartido se cae en
silencio. Acá hay otro más grave: **un mailable encolado se serializa entero en la tabla `jobs`,
y este lleva los archivos ya descifrados.** Encolarlo dejaría las recetas y los estudios de
alguien en claro en una tabla de la base, que es justo lo que todo el cifrado existe para evitar.
Así que el envío es síncrono, con la pantalla esperando ("Mandando…") — que además es lo que
necesita quien está por llamar a la obra social: saber **ahora** si salió.

### Lo que dice el mail, y desde dónde sale

- **`From` es la app, `Reply-To` es la persona.** No se puede mandar _desde_ el mail de la
  persona —el servidor de su dominio no nos autoriza (SPF, DMARC) y caería en spam—, pero si la
  farmacia contesta, le contesta a ella y no a una casilla que nadie lee.
- **Acá el contenido clínico SÍ va en el mail**, al revés que en los avisos. Un aviso sale solo y
  por eso dice "cuándo" y no "qué"; este lo arma una persona eligiendo a quién y qué mandar, y el
  contenido es el propósito. Lo único que agrega el sistema es de parte de quién viene.
- **El asunto no admite saltos de línea**: va a una cabecera, y un salto de línea en una cabecera
  es la forma clásica de inyectar otras (un `Bcc`). El mailer de Symfony ya lo frenaría, pero acá
  el error aparece al lado del campo en vez de como un envío fallido.

### ⚠️ El tope son 15 MB, no los 25 de Gmail

Un adjunto viaja en base64, que pesa un 37% más que el archivo: 15 MB de PDFs son ~20,5 MB de
mail, y con el cuerpo y las cabeceras todavía entran debajo de los 25 MB que cortan Gmail y
Outlook. Un tope de 25 "porque es lo que dice Gmail" dejaría pasar envíos que el destino rebota
horas después, a una casilla que nadie mira. Y como todo se descifra en memoria antes de mandar,
este número también cuida el `memory_limit` del hosting. La pantalla suma el peso en vivo y avisa
antes; la validación lo vuelve a revisar.

### El historial: fotos del momento, sin editar ni borrar

`envios` guarda la dirección y el nombre del destinatario **como estaban al mandar**, y
`adjunto_envio` el nombre y el tamaño de cada archivo. Si mañana se corrige el mail del contacto
o se borra el PDF (borrar un documento elimina su archivo del disco, aunque la fila quede en la
papelera), el historial tiene que seguir diciendo a dónde salió y qué. `contacto_id` y `adjunto_id` quedan como vínculos con `nullOnDelete`.

- **No hay rutas para editar ni borrar un envío.** El historial es lo que se consulta cuando la
  obra social dice "no nos llegó nada", y uno que se puede corregir no prueba nada.
- **Se registra también el que falló**: "lo intenté el martes y no salió" es una respuesta tan
  útil como "salió el martes".
- **Se registra DESPUÉS de intentar**, con el resultado ya sabido: una fila "pendiente" escrita
  antes podría quedar así para siempre si el proceso se cae en el medio.
- Al log de un fallo van ids y la clase de la excepción. El mensaje de un error de SMTP suele
  traer la dirección del destinatario —el mail de un tercero—.
- La fecha del envío es `created_at`: se escribe en el mismo instante en que se intenta, y una
  columna `fecha` aparte —como ponía el plan— solo podría diferir de ella por un error.
- El historial vive **en la pantalla de contactos**, debajo de la libreta: la pregunta que trae a
  alguien ahí ("¿qué le mandé a OSDE?") siempre arranca por el contacto.

El `POST` del envío lleva `throttle:10,1`: una cuenta tomada por otro no puede convertirse en un
cañón de mails con documentos ajenos adjuntos.

### El armado del envío en pantalla

- ⚠️ **Todos los campos con `v-model`**, no solo la lista de documentos que lo necesita para
  sumar el peso en vivo. Es la regla que dejó la Etapa 10: con un solo campo con estado local,
  cada cambio vuelve a renderizar y pisa los `:value` de los hermanos —el asunto o el mensaje ya
  tipeados volverían a lo que vino del servidor—.
- **Agregar un contacto sin salir del envío**, con `preserveState`: el alta vuelve a la misma
  URL, y sin eso la pantalla se remontaría perdiendo los documentos tildados y el mensaje. El
  contacto recién cargado queda elegido (se lo reconoce porque su id no estaba antes).
- El botón "Ver" de cada documento va **fuera** del `<label>` de su checkbox: adentro, tocarlo
  tildaría o destildaría el documento en vez de abrirlo.

Verificado en Chrome real **con el mail de verdad**: el mailer local es `log`, así que el mail
sale entero en MIME al `laravel.log`, y el script lo lee, decodifica los adjuntos del base64 y los
compara por SHA-256 con los PDF originales. Ver "Estado".

### ⚠️ Dos trampas más de los scripts de verificación

- **El triple clic + Backspace no vacía un input de forma confiable.** El paso que verificaba el
  error de "asunto vacío" mandó el envío igual, con el asunto sugerido: el campo nunca se había
  vaciado. Lo determinístico es fijar `value` y disparar el evento `input`, que es lo que escucha
  `v-model`.
- **Un `<label for="…">` no es un área táctil**: es el rótulo de texto de un campo, y el área es
  el campo. Medirlo da "18 px" en algo que está bien. Los que **envuelven** un radio o un
  checkbox sí son la fila que se toca, y esos se miden con `label:not([for])`.

## Cobertura médica

`coberturas` es tabla propia y no columnas en `pacientes`: mucha gente tiene obra social y
prepaga **a la vez**, los planes cambian y la credencial vieja sigue sirviendo para un
reintegro, y cada cobertura tiene su propio número de afiliado. Primer modelo del dominio
que combina `CifraDatos` y `PerteneceAPaciente` a la vez —las dos piezas conviven sin
fricción porque resuelven cosas distintas—.

- **La credencial cuelga de la COBERTURA, no del paciente**: son adjuntos tipo `credencial`
  (frente y dorso), por la misma relación `TieneAdjuntos` que usa `Paciente`. `Cobertura`
  también sube por `pacienteDelRegistro()` vía `belongsTo(Paciente::class)`, así que
  `RegistroClinicoPolicy` la cubre sin ningún caso especial.
- `entidad_hash` lleva **UNIQUE por `paciente_id`**: no puede haber dos coberturas de "OSDE"
  para el mismo paciente. Es el primer UNIQUE sobre un índice ciego que hizo falta validar
  en el FormRequest — ver `IndiceCiegoUnico` más arriba.
- **`activa` puede convivir varias a la vez** (obra social + prepaga): no es una bandera
  exclusiva. Es la que se ofrece por defecto al cargar un estudio o un turno, y la única que
  el dashboard ofrece como acceso rápido — una cobertura dada de baja no se muestra ahí:
  mostrarla invitaría a presentar en un mostrador una credencial que ya no sirve.
- El panel de UI vive en `pacientes/PanelCobertura.vue`, **componente propio y no adentro de
  `pacientes/Index.vue`**: ahí conviven tres formularios (alta, edición de una cobertura
  puntual, subida de credencial) y cada paciente puede tener varias coberturas. Es este
  componente el que abre y cierra su propio `Sheet` —mismo patrón que `VisorDocumento.vue`
  con su `Dialog`—, y le avisa al padre qué documento abrir por un evento (`verDocumento`) en
  vez de montar su propio visor: sigue habiendo **uno solo** para toda la pantalla.

## Panel principal (dashboard)

`DashboardController` resuelve el **paciente activo**: `session('paciente_activo_id')` si
hay uno válido y accesible, si no el primero por nombre —mismo orden que
`PacienteController::index`—. Por ahora el único contenido es el acceso rápido a la
credencial (paso 4.2); el resto (recetas, tratamientos, turnos, órdenes, mediciones) llega
en la Etapa 15, cuando esos módulos existan.

⚠️ **No hay todavía un selector de paciente activo en pantalla.** La ruta
`paciente-activo.update` y el prop `pacienteActivoId` están armados desde la Etapa 2.1, pero
ningún componente los usa: quien tiene más de un paciente no tiene cómo elegir cuál ver en
el dashboard más que por el fallback (el primero por nombre). Falta construir ese selector
—candidato natural: la Etapa 15, junto con el resto del dashboard—.

## Visor de documentos

Un solo `VisorDocumento.vue` a pantalla completa para imágenes **y PDFs**, con X propia.

- **`pdfjs-dist`, no `<iframe>` ni `<embed>`.** El visor nativo dentro de un iframe **no
  funciona en iOS Safari**: muestra la primera página o nada, sin dar error. Es el caso que más
  importa, porque la app se usa desde el celular.
- Entra por **`import()` dinámico**, solo al abrir un PDF: pesa cerca de un mega y no puede
  estar en el bundle inicial de una app que se abre en una sala de espera con mala señal.
- **X propia, grande y con fondo opaco.** La del `DialogContent` va con `opacity-70` y sin área
  táctil: sobre un PDF blanco o una radiografía oscura no se ve y en el celular no se acierta.
- **Uno solo por pantalla, fuera de cualquier `v-for`**: uno por archivo multiplicaría los
  overlays y los focus traps de reka-ui por la cantidad de documentos.
- **`object-contain`, nunca `cover`**: recortar algo que la persona abrió para leer es perder
  justo lo que fue a ver.
- **Se cierra antes de borrar** lo que está mostrando, o queda con un `src` que ya da 404.
- Se usan las primitivas de **reka-ui directo** y no el `DialogContent` de `ui/dialog`: ese
  trae `max-w-lg`, bordes redondeados y padding, que es lo contrario de una pantalla
  completa. De reka-ui interesan el foco atrapado, el Esc y el bloqueo del scroll.
- **El worker de pdf.js entra por `?worker` y se pasa por `workerPort`**, no por una URL en
  `workerSrc`. Las dos formas andan —está probado en Chrome con las dos—; esta no depende de
  que la URL se resuelva bien en tiempo de ejecución, que es una cosa menos que puede quedar
  mal detrás del CDN o con otro `base`.
- ⚠️ **Lo que se destruye al cerrar es la TAREA DE CARGA, no el documento.**
  `PDFDocumentProxy` tuvo un `destroy()` hasta pdf.js 5 y en 6 ya no lo tiene: llamarlo tira
  `destroy is not a function`, que no se ve en pantalla pero deja el worker y el documento
  vivos —un PDF filtrado por cada uno que se abra—. `loadingTask.destroy()` es la API
  documentada y la única que sobrevivió a las dos versiones. Apareció al subir a pdf.js 6 por
  un aviso de seguridad, y lo delató el `console.error` que mira `npm run revisar:visor`.
- **El canvas se dibuja a la densidad real del dispositivo y se baja por CSS.** Sin eso, en
  un celular con pantalla densa el texto del PDF se ve borroso justo en el aparato con el
  que más se lo mira.
- **`nextTick` antes de dibujar.** El `<canvas>` vive dentro del portal del diálogo, que Vue
  monta recién después de que el documento deje de ser `null`: sin esperar, la referencia
  todavía es `null`, el dibujo se va sin hacer nada y —como la página ya valía 1— su `watch`
  tampoco dispara. Queda además un `watch` sobre la referencia del canvas, por el otro lado.
- **Hay un tope de tiempo (45 s) para abrir.** Un visor que gira para siempre es peor que uno
  que dice que no pudo: sin el tope, cualquier cuelgue se lee como "se colgó la app" y no
  ofrece ninguna salida.

### Para subir: `SubirArchivo.vue`

- **Sin `capture`.** Tentaba ponerle `capture="environment"` para que abriera la cámara
  directo, pero `capture` **fuerza** la cámara y de paso **anula el `multiple`**. Sin él, el
  selector del celular ya ofrece cámara, galería y archivos, que es lo que hace falta para el
  caso más común: un PDF que llegó por mail.
- El botón es un `<label>`: el toque abre el selector sin JavaScript de por medio, y el
  `<input type="file">` real queda oculto pero presente dentro del `<Form>`.
- **Sacar un archivo de la lista obliga a reconstruir un `DataTransfer`** y reasignarle
  `files` al input: esa lista es de solo lectura, así que sacarlo del array de Vue no alcanza
  —el archivo se seguiría enviando igual—.
- El aviso de "pesa demasiado" va **al lado del campo y no en un toast**: es un error de
  campo, y un toast obligaría a memorizar cuál de los archivos estaba mal.

### Verificarlo

`npm run revisar:visor` sube un PDF de dos páginas y comprueba en un Chrome real que pdf.js
lo **dibuje** —mira los píxeles del canvas, no que el canvas exista—, que reconozca las dos
páginas, que la X llegue a 44 px y que cierre.

⚠️ **No alcanza con que el `<canvas>` tenga ancho: mide 300×150 por defecto.** Esperar por
`canvas.width > 0` es una espera que no espera nada y da todo por bueno al instante. Hay que
esperar a que desaparezca el cartel de "Abriendo el documento".

⚠️ **Nada de esto reemplaza probarlo en un iPhone real**, que es el caso que define al visor
y del que Chrome headless no puede decir nada.

## Marca

**Provisoria.** Una cruz de puntas redondeadas sobre `#0e7490`, genérica a propósito hasta que
exista la marca real. Vive en dos lugares y no hay que confundirlos:

| Archivo                              | Para qué                                       |
| ------------------------------------ | ---------------------------------------------- |
| `resources/marca/icono.svg`          | Fuente de los íconos de la PWA (con fondo)     |
| `resources/marca/icono-maskable.svg` | Variante para el recorte de Android y para iOS |
| `components/AppLogoIcon.vue`         | La cruz sola, sin fondo, para la interfaz      |

Cambiar la marca son tres pasos: reemplazar los dos SVG, correr `npm run generar:iconos`, y
actualizar `AppLogoIcon.vue`. Y después **subir los tres números de versión** —`CACHE` en
`sw.js`, el `?v=` del blade y el del manifest—, o se sigue viendo el ícono viejo: son tres
cachés distintas y ninguna se limpia sola.

## Caché de las respuestas de Inertia

Una misma URL contesta **dos cuerpos distintos** según el header `X-Inertia`: el HTML de
arranque, o el JSON de la página. Lo único que las separa para una caché es `Vary: X-Inertia`
— y el CDN de Hostinger lo **borra** al comprimir con brotli, que es lo que pide cualquier
navegador real. Sin ese header, y con el `Cache-Control: no-cache` que Symfony pone por
defecto, el navegador guarda el JSON bajo la URL de la página. Cuando Chrome descarta una
pestaña inactiva y después la restaura, esa navegación es de historial y reusa lo guardado
**sin revalidar**: aparece el JSON crudo en pantalla y la app no arranca.

- El arreglo vive en `HandleInertiaRequests::handle()`: `Cache-Control: no-store` cuando la
  petición trae `X-Inertia`, y `Vary: X-Inertia, Accept-Encoding` siempre.
- **`no-cache` no alcanza:** permite guardar y solo obliga a revalidar, y la navegación de
  historial es justamente la que saltea la revalidación.
- **Nunca poner `no-store` en el documento HTML.** Chrome desactiva el back/forward cache de
  las páginas que lo traen y cada "atrás" pasa a ser una ida completa a la red. No da ningún
  síntoma: lo cuida un test.
- El `sw.js` lleva además una red de seguridad para las entradas que ya quedaron guardadas en
  los navegadores. Detalle completo en la skill `inertia-json-crudo`.

## Reglas de negocio

1. **El sistema registra, no aconseja.** Ninguna recomendación clínica, ningún semáforo que
   diga si un valor "está mal". Se muestra el valor y su rango de referencia; interpretar es
   del médico.
2. Sin datos se dice **"sin datos"**, no se infiere nada.
3. Los cálculos de próxima fecha son sugerencias precargadas, **siempre editables**.
4. La **edad no se guarda**: se deriva de `pacientes.fecha_nacimiento`. Guardarla como
   medición la deja vieja al día siguiente. Lo mismo el IMC, que sale de peso y altura.
5. Los catálogos con `usuario_id` NULL son semilla compartida: no se editan, se duplican.

## Sondas: lo que se verificó contra el server real

Dos riesgos se midieron antes de construir encima, no después.

### IMAP saliente — despejado

`php artisan misalud:sonda-imap` abre la conexión y lee el saludo, **sin usar credenciales**.
Corrido en el hosting compartido (23/09/2026):

|                                   |                                                      |
| --------------------------------- | ---------------------------------------------------- |
| `imap.gmail.com:993`              | **abierto**, 120 ms, `* OK Gimap ready for requests` |
| `imap.gmail.com:143`              | bloqueado (timeout) — no importa, se usa TLS         |
| `smtp.gmail.com:587`              | abierto, 131 ms                                      |
| PHP del server                    | 8.4.19 en `/opt/alt/php84/usr/bin/php`               |
| Extensiones de `webklex/php-imap` | las siete, presentes                                 |

Era el riesgo que podía tumbar el módulo de recetas entero: muchos hostings compartidos
bloquean todo lo saliente menos 80/443/587. **No hace falta `ext-imap`** — PHP 8.4 la sacó del
core y el paquete habla el protocolo por sockets.

El comando queda como diagnóstico: cuando la sincronización deje de andar, separa "es la red"
de "son las credenciales o el código" sin tocar una casilla.

### Cifrado sobre MySQL — despejado

El grueso de la suite corre en sqlite en memoria, que es permisivo con cosas que MySQL
rechaza. `CifradoEnMysqlTest` va contra el motor real y verifica el round-trip con acentos y
eñes, que lo guardado sea ilegible, que el `UNIQUE` sobre `char(64)` frene el duplicado, que
una nota de 8 KB no se trunque y que el payload **no entre en un `varchar(255)`**.

Se saltea solo si no hay MySQL a mano. **Ojo con `phpunit.xml`**: fija `DB_DATABASE=:memory:`
y eso pisa también el nombre de base de la conexión MySQL, que queda apuntando a una base
llamada `:memory:`. El test lo corrige antes de conectar; sin eso se saltea siempre y parece
que no hay MySQL.

## Comandos

```bash
composer ci:check         # TODO lo que corre el CI: formato, lint, tipos, PHPStan y tests
php artisan test          # Pest
./vendor/bin/pint         # formato PHP
npm run check:fix         # formato + lint del front
npm run types:check       # vue-tsc
npm run dev               # Vite
npm run revisar:mobile    # desborde en 54 combinaciones + menú al navegar (Chrome real)
npm run revisar:pwa       # el veredicto de instalabilidad de Chrome, no "se ve el botón"
npm run revisar:visor     # sube un PDF y verifica que pdf.js lo dibuje de verdad
npm run generar:iconos    # regenera el set de íconos desde resources/marca/

php artisan misalud:enviar-recordatorios --seco   # qué avisos saldrían, sin mandar nada
php artisan misalud:sincronizar-recetas --seco    # qué recetas entrarían, sin guardar nada
php artisan misalud:sincronizar-recetas --casilla=3   # una sola casilla
php artisan misalud:sonda-imap   # ¿sale el 993 desde acá?
php artisan misalud:recifrar     # rotar APP_KEY (--seco para ensayar)
php artisan wayfinder:generate --with-form   # SIEMPRE con --with-form
```

**Antes de pushear, correr `composer ci:check`** — es exactamente lo que corre el CI.

⚠️ **`wayfinder:generate` sin `--with-form` rompe toda la app.** El plugin de Vite
lo corre con `formVariants: true`; a mano, el default es sin ellas, y regenera los
archivos **sin** los `.form()` que usa cada `<Form>` de Inertia. No falla al
generar: lo descubre `vue-tsc` con veinte errores de golpe en archivos que nadie
tocó. Normalmente no hace falta correrlo a mano: `npm run dev` y `npm run build`
lo hacen bien solos.

MySQL local lo levanta Laragon. Si no está corriendo, `artisan migrate` falla con
"Can't connect to MySQL server".

## Estado

Hecho: andamiaje (Laravel 13 + Inertia 3 + Fortify + Wayfinder, MySQL, Pest 4), todo el texto
visible en español rioplatense, y la capa de cifrado (`CifraDatos`, `CifraCampos`,
`ConsultaVigilada`, `misalud:recifrar` y su guardia), las dos sondas de riesgo despejadas y
el tamaño de letra completo con su pantalla, el layout con menú hamburguesa que se cierra
al navegar, y los avisos en toast.

La PWA está instalable —Chrome lo confirma— con `sw.js` propio, manifest con
`orientation: any`, set de íconos generado y botón de instalar. El parche de caché de
Inertia está aplicado y probado contra un servidor real: `no-store` en la respuesta XHR,
`no-cache` (cacheable) en el HTML, `Vary` en las dos, y la red de seguridad en el service
worker para las entradas que ya hubieran quedado mal guardadas antes del parche.

Falta de la Etapa 1: verificar a mano en un navegador real el caso "pestaña descartada y
restaurada" (chrome://discards) — la emulación offline de Puppeteer no es confiable para
este caso puntual, así que no quedó cubierto por script.

Etapa 2 completa: esquema de pacientes, autorización por rol y CRUD con pantallas
(sheets para crear/editar, dialog para borrar), e ingreso con Google, con sus reglas
de vinculación de cuentas y todo lo que se desprende de una cuenta sin contraseña.
El redirect URI de producción ya está cargado en Google Cloud Console y verificado
—Google lo acepta, sin necesidad de tener el sitio desplegado (ver `docs/google-oauth.md`
para el chequeo por consola)—. El ingreso con Google queda **apagado en local a propósito**:
solo está registrado el redirect de producción.

Las **áreas táctiles de 44 px** ya se cumplen en las nueve pantallas que hay, en los
tres tamaños de letra, y lo cuida `npm run revisar:mobile`. El chequeo mide el alto
**efectivo** con `elementFromPoint` y no la caja del elemento: un checkbox de 16 px
con el área expandida por un pseudo-elemento se toca bien y su caja igual mide 16,
así que medir cajas daría por malo lo que está bien y —peor— por bueno lo que un
`class` de más dejó tapado.

Nota pendiente: `ProfileController::update` usa `Inertia::flash('toast', ...)`, un
mecanismo de Inertia que no está conectado a nuestro sistema de toasts (que mira
`page.props.flash.exito`/`.error`, poblado por `session()->flash()`). Guardar el
perfil hoy no muestra ningún aviso. Es código heredado del starter kit, sin tocar.

Etapa 3 hecha: `ArchivoService` con el cifrado en disco, la tabla polimórfica de
adjuntos, `RegistroClinicoPolicy` sobre `PerteneceAPaciente`, `SubirArchivo.vue`,
`VisorDocumento.vue` con pdf.js, y todo eso ya conectado a la ficha del paciente
—que es lo que lo vuelve verificable en un navegador y no código sin usar—.

Etapa 4 hecha: `coberturas` con su CRUD en `PanelCobertura.vue`, credencial (frente y
dorso) como adjuntos colgados de la cobertura, y el acceso rápido a un toque desde el
panel principal. Encontrado a mano y no por ningún test —ver la regla del checkbox más
arriba, en Backend—: la creación fallaba en silencio por un `boolean` que rechazaba el
`"on"` de un checkbox real, sin ningún cartel en pantalla porque le faltaba su
`InputError`. Los 20 tests de Pest pasaban igual, porque ninguno mandaba el string `"on"`
—mandaban `true` de PHP o directamente omitían el campo—.

⚠️ **`artisan serve` puede dejar procesos zombis si se lo interrumpe a mano.** Durante la
verificación de esta etapa hubo hasta ocho procesos distintos escuchando en el mismo
puerto a la vez, sobrevivientes de arranques anteriores: las requests se repartían entre
ellos al azar, y el síntoma se leía como "a veces guarda, a veces no" —parecía el bug del
checkbox multiplicado—. `netstat -ano | grep ":8001"` lo delata; hay que matar todos los
PID antes de levantar uno limpio.

A pedido del usuario, después de cerrada la Etapa 4: la credencial se cachea en el service
worker para verse sin señal —la única excepción a "los datos clínicos no se cachean",
acotada por el servidor y no por el cliente (`GET /credenciales/{adjunto}`, ver PWA más
arriba)—. Verificado en Chrome real con `page.setOfflineMode(true)`: se cachea al verla
online, sirve sin red, y se olvida al borrar la credencial o la cobertura entera.

Etapa 5.1 hecha: el patrón de catálogos completo -`EsCatalogo`, `DeCatalogo`,
`CatalogoPolicy`, `CatalogoBaseController`- con **médicos** como primero, la regla
de las semillas (se ven, no se editan, se duplican) y la unicidad por índice ciego
acotada al usuario. Los pasos 5.2 y 5.3 copian esto sin decidir nada: la sección
**Catálogos** de más arriba tiene la receta de cinco archivos.

La autorización de los adjuntos pasó a **delegar en su dueño** (ver la sección de
adjuntos más arriba). Era la deuda que dejaba la Etapa 3 y había que saldarla antes
del paso 5.3: el prospecto de un medicamento cuelga de un catálogo, no de un
paciente, y la cadena vieja lo negaba siempre. Con la delegación, 5.3 no necesita
tocar nada de autorización.

⚠️ **`ComboboxCatalogo` quedó pendiente a propósito**, aunque el plan lo ponía en
el paso 5.1: hoy ninguna pantalla elige un médico -enfermedades es la Etapa 7 y
estudios la 9-, y un componente que nadie usa no se puede verificar en un
navegador. Es la misma lección de la Etapa 3. Va cuando exista el primer
consumidor real, que además es quien va a decir qué necesita de verdad.

Etapa 5.2 hecha: `centros`, copiado del patrón de médicos sin decidir nada nuevo,
más `centro_medico` -el primer pivote entre dos catálogos-. Resultó que el primer
consumidor real de "elegir de un catálogo" (anticipado en el párrafo de arriba)
no necesitaba `ComboboxCatalogo`: un checklist de checkboxes alcanza y sobra,
porque un catálogo personal son decenas de médicos, no cientos que pidan buscar
o desplazarse. Ese componente sigue esperando a quien de verdad lo necesite.

Etapa 5.3 hecha: `medicamentos` -con su prospecto en PDF- y `vacunas`, los dos
últimos catálogos que copian el patrón. Ninguno pidió tocar autorización: es la
prueba de que la delegación de `AdjuntoPolicy` (deuda saldada antes de este
paso) estaba bien resuelta. Lo único nuevo fue un hook de eager loading
(`conEager()`) en `CatalogoBaseController`, mismo patrón que `propsExtra()`.
Verificado en Chrome real: alta, subida del prospecto, apertura en
`VisorDocumento`, borrado del prospecto y del medicamento, y alta/edición/baja
de una vacuna.

**Etapa 5 completa** con el paso 5.4: `CatalogosSeeder` carga quince
medicamentos y diecisiete vacunas de uso común en Argentina, como semillas
compartidas. Verificado contra MySQL real -no solo con el test- que correrlo
dos veces no duplica nada, que una semilla se ve pero no se edita, y que
duplicarla arma una copia propia y editable en el catálogo de quien la copió.

Etapa 6.1 hecha: `tipos_medicion` (quinto catálogo) y `mediciones`, con el caso
de dos valores resuelto de punta a punta -esquema, validación y pantalla-.

Lo que apareció en el camino y no estaba previsto: **la capa de zona horaria no
existía**. `CLAUDE.md` la describía desde la Etapa 0, pero `users.zona_horaria`
y los seis métodos de `User` nunca se habían escrito, porque hasta acá ninguna
fecha la cargaba una persona con hora. Se construyeron en este paso, que es el
primero que los necesita.

También se centralizó en `CatalogoVisible` el "lo mío más las semillas", que ya
iba por su tercera copia de un OR que hay que agrupar bien.

Verificado en Chrome real, que es donde se ven las tres cosas que ningún test de
Pest podía mostrar: que un "72,5" tipeado con coma llega y se guarda con sus
decimales, que la hora cargada vuelve idéntica después del viaje a UTC, y que el
segundo número aparece y desaparece al cambiar de variable.

**Etapa 6 completa** con 6.2, 6.3 y 6.4: carga rápida (un toque por variable
abre el formulario ya elegido y con el cursor en el número), `GraficoEvolucion.vue`
con sus tres reglas, y las siete variables de siempre como semilla. El IMC se
deriva del último peso y la última altura, y por eso existe `tipos_medicion.clave`.

La pantalla pasó a estar **agrupada por variable** -gráfico arriba, lista
abajo- en vez de una lista cronológica única: un peso entre dos presiones no
dice nada, y es lo que el gráfico necesita.

Verificado en Chrome: el gráfico **dibuja** de verdad (se cuentan los píxeles
del canvas, no que el canvas exista), el resumen calcula mínimo, máximo y
promedio sobre valores cifrados, el acceso rápido deja el foco en el número, y
el IMC toma el peso más reciente. Y la matriz de desborde completa sobre la
pantalla de mediciones: 18 combinaciones de ancho × tamaño de letra ×
orientación, con los dos gráficos dibujados, sin desborde.

De paso apareció que **el enlace del breadcrumb medía 23 px**: es la primera
pantalla con breadcrumb de dos niveles, así que es la primera vez que ese
componente dibuja un enlace de verdad -con un solo nivel, el último tramo es
texto-. Arreglado en la primitiva, con el mismo criterio que el checkbox:
área de 44 px sin cambiar el tamaño del texto.

⚠️ **Las pantallas por paciente no entran en `npm run revisar:mobile`**:
mediciones y enfermedades necesitan el id de un paciente en la ruta y el
script recorre rutas fijas. Las dos se verificaron con un script aparte —18
combinaciones de ancho × tamaño de letra × orientación cada una, sin
desborde—; si se las toca, hay que repetirlo a mano.

**Etapa 7 hecha**: enfermedades con su bitácora, la curva de las mediciones
que las siguen, y alergias. Todo en una pantalla por paciente, con las
alergias arriba.

Lo que se decidió acá y vale para lo que viene: los números NO van en la
bitácora —van a `mediciones` y apuntan a la enfermedad, o la curva de presión
sale partida según dónde se cargó—, y la regla de cuándo bloquear el borrado
de un catálogo (imprescindible) y cuándo dejarlo ir (metadato). También quedó
`SeriesDeMediciones` como servicio, porque el armado de series pasó a tener
dos consumidores y dos copias serían dos promedios distintos.

`mediciones.enfermedad_id` llegó recién ahora, y es a propósito: en el paso
6.1 la tabla `enfermedades` no existía, así que la columna habría quedado sin
FK, sin validación y sin un test que la protegiera. Sumarla después no costó
ningún backfill.

Verificado en Chrome: la curva **dibuja** en la ficha de la enfermedad (60717
píxeles), el promedio coincide con el de la pantalla de mediciones, la
bitácora anota con la fecha de hoy y el selector de enfermedad aparece al
cargar una medición.

**Etapa 8 hecha**: tratamientos (8.1) y el cierre de vencidos por el scheduler más los
tratamientos activos en el dashboard (8.2). `medicamento_id` es la tercera FK a un catálogo
y confirma la regla que ya había quedado escrita en la Etapa 7 (bloquear cuando es
imprescindible, dejar ir cuando es metadato) — `MedicamentoController::destroy()` ahora se
frena igual que `TipoMedicionController::destroy()`.

`misalud:cerrar-tratamientos-vencidos` es el primer comando del scheduler del proyecto
(`bootstrap/app.php`, `->withSchedule()`), y la primera vez que un proceso de fondo compara
contra "hoy" sin tener un usuario de quien tomar la zona horaria -ver esa sección para la
decisión completa-.

Verificando en Chrome apareció un bug real de reactividad en `tratamientos/Index.vue`: un
`const` calculado una vez en vez de `computed()` dejaba el tratamiento recién cargado sin
aparecer hasta un refresh manual, porque Inertia reutiliza la instancia del componente al
redirigir a la misma URL. Los 393 tests de Pest pasaban igual -prueban al servidor, no la
reactividad del cliente-. Quedó como regla general en esa sección.

**Paso 9.1 hecho**: `ordenes_estudio` con su papel en PDF y la pantalla de
"pendientes de hacer". La decisión que sostiene la etapa es que una orden y
un estudio son dos tablas y no dos estados de una; y queda anotada la regla
de una sola dirección para cuando 9.2 sume `estudio_id` (vincular un estudio
implica Hecha, estar Hecha no implica que haya estudio cargado).

Es el primer registro clínico con archivos propios, y con eso el cuerpo de
la subida quedaba copiado cuatro veces: se extrajo a `guardarEn()` detrás del
contrato `TieneArchivos`, con el prefijo del disco declarado por cada modelo.
Un test fija que los tres prefijos que ya existían no se movieron.

Verificado en Chrome: la orden recién creada aparece sin refrescar -con
`computed()`, la lección de la Etapa 8-, lo pendiente va arriba de lo hecho,
el papel se sube y el visor lo **dibuja** (90000 píxeles), y marcarla como
hecha la saca de pendientes. Más la matriz de desborde de la pantalla nueva.

**Etapa 9 completa** con 9.2, 9.3 y 9.4: `estudios` con su informe, `resultados_estudio` con
sus parámetros anidados en la misma pantalla, y la evolución de cada parámetro entre estudios
reusando `GraficoEvolucion.vue`. El circuito orden → estudio quedó cerrado: vincular una
orden al crear el estudio la marca `Hecha`, y borrar el estudio después no revierte eso -la
persona se hizo el estudio igual, aunque el registro se borre-.

`Estudio` es el quinto dueño de archivos, y sumarlo costó tres líneas -`storeParaEstudio()`
llamando a `guardarEn()`-, que es exactamente lo que la refactorización de 9.1 prometía.

Dos hallazgos antes de mandarlo, ninguno atrapado por los 454 tests de Pest:

- Un `update(['estudio_id' => ...])` sobre la orden hubiera sido un no-op silencioso:
  `estudio_id` no es fillable. Se cambió a `setAttribute()` + `save()` -lo encontró leer el
  propio código, no un test, pero quedó uno que lo fija-.
- La evolución de un parámetro mostraba la fecha **un día antes** en cualquier navegador con
  zona horaria negativa -Argentina incluida, o sea prácticamente cualquier usuario real de
  esta app-: `estudio.fecha` es una fecha de calendario a medianoche UTC, y sin
  `zona-horaria="UTC"` explícito el navegador la formatea en su propia zona. Medido en
  Chrome: "14/1" sin la corrección, "15/1" con ella. Misma trampa de `hoy()` vs
  `hoyCalendario()`, esta vez del lado del cliente.

**Pasos 10.1 y 10.2 hechos**: el modelo clínico de salud ocular y `DiagramaOjo.vue`. Van
juntos a propósito —un componente que nadie usa no se puede verificar en un navegador, la
lección de la Etapa 3—, y de paso se adelantó el PDF de la receta que el plan ponía en el
10.3: declarar `TieneArchivos` sin una pantalla que lo ejercite habría sido el mismo error.

La decisión que sostiene la etapa es que **una receta tiene siempre sus dos ojos**, aunque uno
no necesite corrección: una fila que falta no se puede interpretar y una fila en blanco sí.
Lo sostienen el UNIQUE, la transacción del controlador y la factory, los tres a la vez.

La validación clínica sigue un solo criterio —se rechaza lo que **no puede existir**, nunca lo
que es poco común—, y por eso una receta "para lejos" con adición entra sin chistar mientras
un `-1,30` de esfera no: el primero es un papel raro pero real, el segundo es una lente que no
se fabrica.

Dos hallazgos en Chrome que ningún test de Pest podía ver:

- **Mezclar `:value` con `v-model` en el mismo componente borraba lo tipeado.** El eje necesita
  `v-model` para que el dibujo lo siga en vivo, y eso alcanzaba para que cada tecla escrita ahí
  pisara la esfera y el cilindro con el valor del prop. Medido: escribir en el orden natural
  —esfera, cilindro, eje— dejaba los dos primeros vacíos al primer tecleo del eje. Y como son
  opcionales, **la receta se guardaba con el ojo en blanco y un cartel verde de éxito**. Quedó
  como regla general en la sección de arriba.
- **La X de cerrar de `Sheet` y `Dialog` medía 20 px**, en toda la app desde siempre. Arreglada
  en las dos primitivas con el pseudo-elemento del checkbox: 59 px efectivos, con la caja
  visual igual que antes.

Verificado en Chrome: el diagrama **dibuja** el eje en el ángulo correcto (45° y 90° medidos
sobre el SVG), el mismo número da el mismo ángulo en los dos ojos —la escala no se espeja—, la
coma decimal sobrevive el viaje completo, el signo vuelve con el valor (`+2,00`), los errores
clínicos aparecen al lado del campo y no en un toast, y el visor dibuja el PDF de la receta.
Más la matriz de desborde de la pantalla nueva —18 combinaciones de ancho × tamaño de letra ×
orientación, con el formulario de dieciséis campos abierto— y `npm run revisar:mobile` entero
en verde después de tocar las primitivas.

⚠️ De paso apareció que **verificar un sheet sin esperar a que termine de entrar mide
cualquier cosa**: `waitForSelector({visible:true})` da el elemento por bueno mientras el panel
todavía se desliza, y ahí `elementFromPoint` no encuentra nada. El chequeo informaba "20px"
con el arreglo ya funcionando. Detalle y salida en la sección de arriba.

**Etapa 10 completa** con 10.3 y 10.4: transposición del cilindro y evolución de la esfera,
reusando `GraficoEvolucion.vue`. El PDF de la receta y el listado por fecha ya habían quedado
resueltos en 10.1/10.2 -declarar `TieneArchivos` sin pantalla que lo usara hubiera sido el
mismo error que ya evitó la Etapa 3-.

La transposición es aritmética pura sobre los valores que el componente ya tiene en la
mano -no pega al servidor, no persiste, se pierde al recargar a propósito- y el diagrama
**rota** cuando se activa, no solo cambia el texto: es la confirmación visual de que las dos
formas describen el mismo lente. La evolución necesitó dos series independientes por ojo, no
una con OD de principal y OI de secundario como hace una presión: acá un ojo puede no tener
corrección mientras el otro sí, y un punto de `GraficoEvolucion` no admite un `valor` nulo.

Verificado en Chrome, con un caso concreto y no solo "se ve bien": `-1,00 -2,00 x 60°`
transpuesto da `-3,00 +2,00 x 150°`, con el dibujo girando de 60° a 150° y "Volver al papel"
devolviendo el original exacto; el botón está ausente cuando ningún ojo tiene cilindro real;
el gráfico de evolución dibuja de verdad (177228 píxeles); y la matriz de desborde con el
botón nuevo, en los tres tamaños de letra.

Un hallazgo, esta vez en la propia suite de tests y no en Chrome: `conOjos(od: [...])` sin
pasar `oi:` deja el ojo izquierdo con el default de la factory -que trae esfera y cilindro,
no en blanco-, y un primer test de "un ojo sin corrección" pasaba probando el caso
equivocado. Quedó documentado en la sección de arriba, para quien escriba el próximo test que
necesite un ojo realmente vacío.

**Paso 11.1 hecho**: `turnos` con su agenda, y `recordatorios` con los observers que los
generan. Van juntos porque uno sin el otro no se puede verificar: la pantalla existe para que
el observer se vea trabajar.

La decisión que sostiene el paso es que **un recordatorio es dato derivado**: no hay ninguna
ruta que lo cree ni que lo borre, lo único que hace una persona es marcarlo hecho. Toda la
idempotencia vive en `GeneradorDeRecordatorios` -cuatro casos, dos de ellos opuestos a
propósito: si la fecha del origen no cambió NO se toca nada (para no reabrir un aviso que
alguien ya resolvió al corregir una falta de ortografía), y si cambió se reabre entero (porque
el mail que salió decía un día que ya no es)-, respaldada por un UNIQUE en la base.

El futuro pasó a ser un caso normal: `turnos.fecha_hora` es el segundo `datetime` que carga
una persona y el primero que **no** lleva validación de rango, al revés que una medición.

Verificado en Chrome: crear el turno hace aparecer el aviso sin refrescar y exactamente 24
horas antes en la zona de la cuenta (15:30 del 15/3 → aviso el 14/3 15:30), moverlo al 20/3
09:00 mueve el aviso al 19/3 09:00, cancelarlo se lo saca, y "Ya está" lo saca de la lista sin
borrar la fila. Más la matriz de desborde de la pantalla nueva -18 combinaciones- y las áreas
táctiles del formulario.

De paso: `puppeteer.launch()` empezó a fallar con un `Code: 0` y stderr vacío. Se resolvió con
un `userDataDir` temporal por proceso, que además no toca el Chrome del usuario -queda como
default para todo script de verificación-.

⚠️ **Hueco latente encontrado, NO arreglado todavía** (es de otra etapa): nada limpia los
`adjuntos` cuando se borra su dueño de verdad. Hoy no se nota porque ningún controlador llama
a `forceDelete()` -solo hay soft deletes, y ahí los adjuntos deben quedarse para que restaurar
funcione-, pero `adjuntos` es polimórfica y no tiene FK, así que un `forceDelete` del dueño
deja la fila **y su archivo cifrado en disco** para siempre. Se vuelve alcanzable cuando la
Etapa 14 exponga el `forceDelete` del propietario; hay que resolverlo ahí.
**Ya resuelto para los catálogos**, que desde la decisión de "si nada lo usa, se borra de
verdad" hacen `forceDelete`: `CatalogoBaseController::borrarDeVerdad()` se lleva antes los
archivos (disco y filas). Sigue abierto para el resto de los dueños.

**Paso 11.2 hecho**: `misalud:enviar-recordatorios`, cada hora por el scheduler, con su mail.

Dos decisiones que valen más que el código. La primera: **no se avisa de algo que ya pasó**, y
el corte no es una constante inventada -`instanteDelEvento()` es `fecha + anticipación`, así que
la condición es literalmente "¿el evento ya pasó?"-. Esos quedan en `Vencido`, no borrados, para
que la pantalla pueda distinguir "no te avisamos" de "te avisamos y no lo resolviste".

La segunda: **el mail dice cuándo, no qué**. Sale sin cifrar y queda en el servidor de correo de
quien lo reciba, así que lleva el tipo de aviso, el nombre del paciente y la fecha; el motivo,
el médico y el medicamento se quedan detrás del login. El asunto no lleva ningún nombre, porque
es lo que se ve en la pantalla bloqueada de un teléfono.

Sin `ShouldQueue`, uno por destinatario -cada uno lee la hora en su zona, y varios `To` se
filtran las direcciones entre sí- y en texto plano.

Verificado corriendo el comando de verdad contra el mailer de log y leyendo el mail renderizado,
no solo con `Mail::fake()`: `text/plain`, el motivo aparece **0 veces**, el nombre 1, y la hora
en la zona de la cuenta.

⚠️ **`MAIL_FROM_ADDRESS` sigue siendo el `hello@example.com` del starter kit.** Los avisos no
van a salir de producción hasta configurar el mailer real; queda para el deploy (Etapa 15.3).

**Etapa 11 completa.** El paso 11.3 del plan es "agenda y pendientes **en pantalla**", y eso
quedó cubierto por la pantalla de turnos del 11.1: la sección "Avisos" son los pendientes y
"Lo que viene"/"Ya pasaron" son la agenda. No hacía falta nada nuevo. Turnos y recordatorios **en
el dashboard** es otra cosa y es de la Etapa 15.1 —lo dicen el plan y el propio comentario de
`DashboardController`—.

**Paso 12.1 hecho**: `cuentas_mail` con las credenciales cifradas, su pantalla y la prueba de
conexión. Entra `webklex/laravel-imap`.

La decisión que ordena el paso es que **la contraseña es de solo escritura**: no viaja al
navegador, y de ahí se siguen el campo vacío que significa "dejá la que está" y que la prueba de
conexión se haga sobre lo guardado y no sobre el formulario. La otra es que los tres fallos
posibles son **tres cosas distintas** y no un booleano: la carpeta mal escrita es la que importa,
porque deja la sincronización en verde encontrando cero mensajes para siempre.

Dos hallazgos que salieron de probar contra servidores reales antes de escribir la pantalla, y
que ningún test de Pest podía dar:

- **La excepción de "contraseña equivocada" no es `AuthFailedException`** sino
  `ImapServerErrorException` —medido contra Gmail con credenciales inventadas—. Atrapar la que
  dice el nombre dejaba un login rechazado cayendo en "error inesperado", con un mensaje que no
  nombra la contraseña.
- **El binding de `ClientManager` del paquete está roto si no se publica su config**: hace
  `new ClientManager(config('imap'))` y eso pasa `null` a un parámetro que no lo acepta.

Verificado en Chrome real: la contraseña no aparece ni en el HTML ni en ninguna respuesta de
Inertia (mirando el tráfico de verdad), el formulario de edición abre con ese campo vacío, el
error de validación del host pegado con el puerto aparece **al lado del campo y no en un toast**,
la prueba contra un host inexistente dice "no se pudo llegar" y contra Gmail dice "rechazó la
dirección o la contraseña" nombrando la contraseña de aplicación, más las 18 combinaciones de
desborde y las áreas táctiles del formulario. Y contra **MySQL real** —no solo sqlite—: el
round-trip con eñes y acentos, que las tres columnas quedan ilegibles y el host no, que el
índice ciego encuentra la fila ignorando mayúsculas, que el `UNIQUE(usuario_id, direccion_hash)`
frena el duplicado y que el borrado no deja ninguna fila con la contraseña adentro.

De paso, tres trampas de los scripts de verificación que informaban fallas inexistentes (toast
viejo, canario de un caracter, `fetch` de Inertia con versión inventada) quedaron documentadas en
la sección de la casilla.

**Paso 12.2 hecho**: `recetas`, `misalud:sincronizar-recetas` cada hora, y el botón de importar a
mano en la pantalla de la casilla.

La decisión que ordena el paso es la **separación entre el lector de IMAP y las reglas**: el
lector es tonto a propósito —es la única pieza sin tests automáticos, porque necesitaría un
servidor— y todo lo que decide algo vive del otro lado de dos DTOs, probado sin red.

Tres correcciones al plan, cada una por un bug concreto:

- **La ventana no es "desde el día 1 del mes"**: una receta del 31 de enero desaparecía el 1 de
  febrero. Va desde la última sincronización menos 7 días de solapamiento.
- **El UNIQUE de deduplicación va por usuario, no global**: si la farmacia le manda el mismo mail
  a dos personas que usan MiSalud, con un UNIQUE global la segunda no podría importarlo nunca.
- **`recetas.paciente_id` no se creó**: un mail no dice de quién es la receta, y nada puede
  escribir esa columna todavía. ⚠️ Queda **una pregunta abierta** para cuando se pueda asignar:
  si un cuidador de esa ficha puede ver la receta. Hoy la ve quien tiene la casilla.

Verificado en Chrome real: la pantalla dice cuándo fue la última importación y cuántas recetas
entraron, el botón avisa "Importando…" mientras trabaja, y un fallo manda a probar la conexión
**sin mostrar lo que contestó el servidor IMAP ni la contraseña** —más las 18 combinaciones de
desborde y las áreas táctiles—. Y contra **MySQL y disco reales**, con el lector reemplazado por
un doble pero todo el resto siendo el código de producción: las columnas quedan ilegibles y la
fecha en claro, el archivo se escribe cifrado y **descifrado vuelve byte a byte igual al PDF
original**, el mime se dedujo del contenido, el nombre con eñe sobrevive, la segunda corrida no
reimporta ni deja una segunda copia del archivo, una receta en la papelera sigue ocupando su hash,
el `UNIQUE(usuario_id, message_id_hash)` frena el duplicado, y borrar la casilla **no** se lleva la
receta ni su archivo. Y el PDF importado se abre de verdad por `/adjuntos/{id}`: la cadena
completa, de los bytes del mail al navegador.

⚠️ **Lo que no está verificado: la lectura real de IMAP** (`LectorDeCasilla`) contra una casilla
con credenciales de verdad. Es la única pieza sin cobertura, y es justamente por eso que no decide
nada. Se prueba configurando una casilla real y tocando "Importar ahora".

**Etapa 12 completa** con el paso 12.3: la bandeja de recetas en `/recetas`, con las disponibles
arriba ordenadas por la que vence primero, el contador del mes en la zona de la cuenta, el visor,
marcar usada (y deshacerlo) y la vigencia editable.

Verificado en Chrome real con tres recetas importadas por el servicio de verdad, cada una con su
PDF: el orden es el de vencimiento, la vencida cae al historial sin que nadie la marque, el visor
**dibuja** el PDF importado (60000 píxeles), marcar usada mueve la tarjeta **sin refrescar** y
deshacerlo la devuelve a su lugar, el error de vigencia va al lado del campo y no en un toast,
ampliar la vigencia revive una vencida, y borrar una receta y volver a importar los mismos mails
**no la trae de vuelta**. Más las 18 combinaciones de desborde, las áreas táctiles y el estado
vacío sin casilla.

⚠️ **Corrección a algo que se había dado por resuelto:** el recordatorio de "receta por vencer"
no se puede hacer todavía, porque `recordatorios.paciente_id` es obligatorio y una receta no tiene
paciente. Queda atado a la decisión de asignar recetas a pacientes.

⚠️ **Hueco encontrado, sin etapa asignada: las vacunas APLICADAS no existen.** Hay catálogo de
vacunas, pero `aplicaciones_vacuna` —que está en el modelo de datos del plan, de la que según este
mismo archivo cuelga el comprobante de una dosis, y de la que sale el recordatorio de próxima
dosis— nunca se construyó: ningún paso del plan la tiene asignada. `TipoAdjunto::Vacuna` es un
caso que nada usa. Conviene hacerla antes de la Etapa 15.

**Etapa 13 completa**: la libreta de contactos, el envío de documentación por mail desde cada
documento (receta, orden, estudio, receta de anteojos) y el historial de lo que se mandó.

Las decisiones que ordenan la etapa, porque es el único lugar que saca documentos clínicos de la
app: el destino es **siempre un contacto de la libreta**, nunca una dirección tipeada; lo que se
ofrece para sumar es **solo de la misma ficha**, nunca de otro familiar; **cada archivo se
autoriza al mandar** contra su dueño, antes de validar; y el envío es **síncrono y sin
`ShouldQueue`**, porque encolado dejaría los archivos descifrados en la tabla `jobs`.

Verificado en Chrome real y **contra el mail de verdad** (el mailer `log` deja el MIME entero en
el log): la cobertura precarga el contacto; desde una orden, la credencial de la misma ficha se
ofrece y **la orden del otro familiar no**; el destinatario no viene elegido y el botón queda
deshabilitado hasta elegirlo; agregar un contacto sin salir del envío no pierde lo tildado ni lo
escrito y deja elegido al nuevo; un asunto vacío da el error al lado del campo sin borrar nada;
el mail sale con `To`, `Reply-To` a la persona y el asunto con tilde bien codificado, y **los dos
adjuntos, decodificados del base64, son byte a byte los PDF originales** (SHA-256). Y el archivo
de otra cuenta da **403** al armar el envío y al forzar el id en el formulario, sin que salga
ningún mail. Más 36 combinaciones de desborde, áreas táctiles, `revisar:mobile` y `revisar:visor`.

⚠️ **Bug encontrado en etapas anteriores: recrear algo borrado daba un 500.** Medido: crear
"Dr. Pérez", borrarlo y volver a crearlo con el mismo nombre devolvía un **500**. El registro
borrado quedaba en la papelera ocupando su hash en el UNIQUE, `IndiceCiegoUnico` consulta sin la
papelera y no lo veía, y la base lo rechazaba.

**Arreglado para los cinco catálogos**, con la regla que decidió el usuario: si algo lo usa no se
borra, y si nada lo usa se borra de verdad (ver "Borrar un catálogo"). Verificado que borrar y
volver a cargar un médico, un centro y una vacuna ya no revienta, y protegido por
`ReferenciasACatalogosTest`, que lee las FK reales del esquema.

**Sigue abierto en las coberturas y las alergias**, que tienen el mismo patrón (soft deletes y un
UNIQUE sobre un índice ciego). No entraron en la decisión porque son registros clínicos de un
paciente, no catálogos: borrarlos de verdad pierde historia, y conviene decidirlo junto con la
papelera de la Etapa 14. Ojo: **las recetas tienen el comportamiento opuesto a propósito** —ahí
la papelera _tiene_ que ocupar el hash, para que lo borrado no se reimporte—.

De paso apareció **un bug de la propia Etapa 13**, ya arreglado: `exists:adjuntos,id` consultaba
la tabla cruda, que incluye la papelera de los adjuntos, así que el id de un documento borrado
pasaba la validación y salía **un mail sin ningún adjunto**. Era exactamente la trampa de
`Rule::exists` ya documentada; ahora lleva su `whereNull('deleted_at')`. Y una afirmación falsa de
esa etapa, corregida: los adjuntos **sí** tienen soft deletes (borrar un documento elimina su
archivo del disco, pero la fila queda en la papelera).

⚠️ Para revisar en la **Etapa 14**: hoy **mandar un documento pide lo mismo que verlo**, también
para un `Lector`. Pedir más no protege nada (quien puede abrir el PDF lo puede reenviar desde su
correo), pero es una decisión de roles y va con el resto.

Pendiente, en este orden: compartir la ficha · dashboard y deploy. Queda también, sin fecha, la
Etapa 16 (consultas y grabaciones), que el plan deja adelantable. Y sin etapa asignada: las
vacunas aplicadas, y el 500 de la papelera en coberturas y alergias (los catálogos ya están
arreglados).
