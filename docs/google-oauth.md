# Ingreso con Google

El código está completo. Mientras `GOOGLE_CLIENT_ID` y `GOOGLE_CLIENT_SECRET`
estén vacías, **la opción no existe**: el botón no se muestra, las rutas dan 404
y la app funciona exactamente como antes.

> **Las credenciales no van en este repo**, que es público. Viven en el `.env`
> local y en el `.env` del server, y el `.env` está en `.gitignore`.

## Qué hacer en Google Cloud

1. [console.cloud.google.com](https://console.cloud.google.com/) → crear un
   proyecto (nombre sugerido: **MiSalud**).

2. **APIs y servicios → Pantalla de consentimiento de OAuth**:
    - Tipo de usuario: **Externo**.
    - Nombre de la app: `MiSalud`. Email de asistencia: el tuyo.
    - Dominio autorizado: `pablomandile.com.ar`.
    - Permisos: alcanzan los tres básicos —`userinfo.email`, `userinfo.profile` y
      `openid`—. **No pidas nada más**: cualquier permiso extra dispara una
      revisión de Google que tarda semanas, y esta app no necesita leer nada de
      la cuenta más allá de quién es.
    - En modo **Prueba** solo entran los emails que agregues como usuarios de
      prueba. Con los tres permisos básicos se puede publicar sin verificación.

3. **APIs y servicios → Credenciales → Crear credenciales → ID de cliente de
   OAuth**, tipo **Aplicación web**. En **URI de redireccionamiento
   autorizados** va el de producción:

    ```
    https://misalud.pablomandile.com.ar/auth/google/callback
    ```

    Tiene que coincidir **carácter por carácter** con lo que manda la app:
    esquema incluido y sin barra al final.

### El ingreso con Google está apagado en local, a propósito

Solo está registrado el redirect de producción, que es donde interesa la
autenticación. Por eso el `.env` local tiene las credenciales **comentadas**: con
ellas puestas, el botón «Continuar con Google» aparece y al tocarlo Google
contesta `redirect_uri_mismatch` — peor que no tenerlo. Comentadas, la opción
sencillamente no existe: el botón no se dibuja y las rutas dan 404.

Para encenderlo en local hay que hacer **las dos cosas**:

1. Registrar también `http://localhost:8001/auth/google/callback` en Google Cloud
   Console. **No sirve `misalud.test`**: Google exige `https`, y la única
   excepción es `localhost` o `127.0.0.1` — que además no son intercambiables
   entre sí.
2. Descomentar las tres variables del `.env` y levantar el servidor en ese mismo
   host y puerto:

    ```bash
    php artisan serve --host=localhost --port=8001
    ```

⚠️ Si el servidor ya estaba corriendo con `--no-reload`, **hay que reiniciarlo**:
`artisan serve` pasa las variables del `.env` al proceso hijo, y phpdotenv no
pisa una variable que ya está en el entorno. Editar el `.env` no cambia nada
hasta el reinicio, y el síntoma es que parece que el cambio no se aplicó.

## Qué poner en el `.env`

En **producción**, y solo ahí:

```env
GOOGLE_CLIENT_ID=...apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=...
```

`GOOGLE_REDIRECT_URI` no hace falta: se deriva de `APP_URL`.

En producción, lo mismo en el `.env` del server y después **`config:cache`**, o
la app sigue leyendo la configuración vieja.

## Cómo verificar que quedó bien

**Se puede comprobar que Google acepta el redirect URI sin tener el sitio
desplegado y sin una cuenta de Google.** Google valida la URI contra su lista
antes de contactar nuestro servidor, así que alcanza con armar la URL de
autorización y mirar qué contesta:

```bash
CID=<el client id>
RU=https://misalud.pablomandile.com.ar/auth/google/callback
curl -sL -A "Mozilla/5.0 Chrome/140"   "https://accounts.google.com/o/oauth2/auth?client_id=$CID&redirect_uri=$(python -c    "import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1],safe=''))" "$RU")&scope=openid+profile+email&response_type=code&state=chequeo"   | grep -oiE "redirect_uri_mismatch|invalid_client|Sign in"
```

- `Sign in` → **está bien**: llegó a la pantalla de cuenta.
- `redirect_uri_mismatch` → falta cargar esa URI, o no coincide carácter por
  carácter.
- `invalid_client` → el problema es el `GOOGLE_CLIENT_ID`, no la URI.

Esa diferencia es la que ahorra el viaje en falso: en el navegador los tres
casos se ven casi iguales.

Ya desplegado, además:

```bash
curl -s https://misalud.pablomandile.com.ar/login | grep -o 'googleHabilitado":[a-z]*'
# esperado: googleHabilitado":true
```

Si dice `false` con las variables puestas, falta `config:cache` en el server.

## Decisiones que ya están tomadas en el código

- **Una cuenta por email.** Si Google devuelve un email que ya existe, se vincula
  el `google_id` a esa cuenta en vez de crear otra. Dos cuentas con el mismo
  email dejarían a alguien con dos juegos de pacientes separados —la ficha de su
  madre en una y la propia en la otra—, cada uno invisible desde el otro.
- **El email tiene que venir verificado por Google**, o se rechaza. El flag viaja
  en el payload crudo y no en la interfaz de Socialite; si no viene, se asume que
  **no** está verificado. Con un email sin verificar, cualquiera podría reclamar
  la cuenta de otro declarando su dirección.
- **Se reconoce por el `sub` de Google, no por el email.** El email de una cuenta
  de Google se puede cambiar; el identificador no. Si cambió, se actualiza.
- **La cuenta queda sin contraseña.** Nunca eligió una, y ponerle una al azar la
  haría figurar como que puede entrar con email y clave cuando no puede. Desde
  _Configuración → Seguridad_ puede definirse una, y ahí no se le pide la
  anterior porque no existe.
- **La pantalla de seguridad no le pide confirmar la contraseña**
  (`ConfirmarClaveSiLaTiene`). El `RequirePassword` de Laravel sería una puerta
  sin llave posible para estas cuentas, y las dejaría afuera del 2FA y de las
  llaves de acceso. Para quien sí tiene contraseña, se le sigue pidiendo igual.
- **Eliminar la cuenta tampoco pide contraseña si no hay ninguna.** Con la regla
  fija, una cuenta de Google no podría borrarse nunca.
- **Cancelar en la pantalla de Google no es un error**: vuelve al login sin
  ningún cartel rojo.
- **Los errores de Socialite no se muestran.** Traen partes de la respuesta de
  Google; van al log y la persona ve un mensaje propio.

Todo esto está cubierto por `tests/Feature/Auth/IngresoConGoogleTest.php`,
incluido el caso que más importa: que a una cuenta **sin** contraseña no se
pueda entrar mandando una contraseña vacía.
