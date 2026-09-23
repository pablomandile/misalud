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
   autorizados** van los dos:

    ```
    https://misalud.pablomandile.com.ar/auth/google/callback
    http://localhost:8001/auth/google/callback
    ```

    Tienen que coincidir **carácter por carácter** con lo que manda la app:
    esquema incluido, puerto incluido y sin barra al final.

### ⚠️ Por qué el de local es `localhost:8001` y no `misalud.test`

Google **no acepta dominios `.test`**: exige `https`, y la única excepción es
`http` contra `localhost` o `127.0.0.1`. Como `APP_URL` acá es
`http://misalud.test`, el redirect derivado de `APP_URL` no sirve, y por eso el
`.env` local fija `GOOGLE_REDIRECT_URI` a mano.

Eso obliga a levantar el servidor en ese mismo host y puerto:

```bash
php artisan serve --host=localhost --port=8001
```

`localhost` y `127.0.0.1` **no son intercambiables** para Google: manda el texto
exacto que está en `GOOGLE_REDIRECT_URI`.

## Qué poner en el `.env`

```env
GOOGLE_CLIENT_ID=...apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=...
# Solo en local. En producción se deriva solo de APP_URL.
GOOGLE_REDIRECT_URI=http://localhost:8001/auth/google/callback
```

En producción, lo mismo en el `.env` del server y después **`config:cache`**, o
la app sigue leyendo la configuración vieja.

## Cómo verificar que quedó bien

Sin abrir el navegador, y sin necesidad de una cuenta de Google:

```bash
# 1. ¿La app ve las credenciales?
curl -s http://localhost:8001/login | grep -o 'googleHabilitado":[a-z]*'
# esperado: googleHabilitado":true

# 2. ¿Google acepta el redirect URI?
URL=$(curl -s -o /dev/null -w "%{redirect_url}" http://localhost:8001/auth/google/redirect)
curl -sL "$URL" | grep -o "redirect_uri_mismatch"
# esperado: SIN salida. Si imprime redirect_uri_mismatch, falta cargar la URI
# en Google Cloud Console (o no coincide carácter por carácter).
```

El paso 2 es el que ahorra el viaje en falso: distingue «falta cargar la URI» de
«las credenciales están mal», que en el navegador se ven casi igual. Un
`invalid_client` en vez de `redirect_uri_mismatch` significa que el problema es
el `GOOGLE_CLIENT_ID`, no la URI.

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
