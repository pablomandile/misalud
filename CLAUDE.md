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
- ⚠️ **`cascadeOnDelete()` no dispara con un soft delete.** Es una restricción de MySQL, y
  solo actúa sobre un `DELETE` real. `destroy()` de un catálogo hace un soft delete —pone
  `deleted_at`—, así que la fila de `centros` sigue existiendo y el vínculo con ella
  también: es lo correcto, porque un soft delete es recuperable, y perder los médicos
  vinculados en el camino sería una pérdida de datos que nadie borró a propósito. La
  cascada real solo se ve con `forceDelete()`. Medido con un test: sin él, hubiera quedado
  como un "debería andar" sin comprobar.

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

Pendiente, en este orden: capa de cifrado y sondas de riesgo · accesibilidad, layout y PWA ·
pacientes y Google · adjuntos y visor · cobertura médica · catálogos · seguimiento de
variables · enfermedades y alergias · tratamientos · órdenes, estudios y resultados · salud
ocular · turnos y recordatorios · casilla y recetas · contactos y envío · compartir la ficha ·
dashboard y deploy.
