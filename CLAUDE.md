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
- Áreas táctiles de **44 px mínimo**. Teclado correcto: `inputmode="decimal"` para valores,
  `type="date"`, `type="tel"`.
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
npm run generar:iconos    # regenera el set de íconos desde resources/marca/

php artisan misalud:sonda-imap   # ¿sale el 993 desde acá?
php artisan misalud:recifrar     # rotar APP_KEY (--seco para ensayar)
```

**Antes de pushear, correr `composer ci:check`** — es exactamente lo que corre el CI.

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

Etapa 2 en marcha: el esquema de pacientes está completo (`pacientes` + `paciente_usuario`

- `PacientePolicy` + paciente activo por sesión). Falta el CRUD con pantallas (2.2) e
  ingreso con Google (2.3).

Pendiente, en este orden: capa de cifrado y sondas de riesgo · accesibilidad, layout y PWA ·
pacientes y Google · adjuntos y visor · cobertura médica · catálogos · seguimiento de
variables · enfermedades y alergias · tratamientos · órdenes, estudios y resultados · salud
ocular · turnos y recordatorios · casilla y recetas · contactos y envío · compartir la ficha ·
dashboard y deploy.
