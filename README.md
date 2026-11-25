# Bezares Social MKT

Panel web para compartir las noticias de Bezares en **LinkedIn, Facebook e Instagram** con textos generados por **ChatGPT**, y ver las **estadísticas** de cada publicación. Colores de Bezares Expo y misma tecnología que el panel de Bezares Chat (PHP + MySQL, sin dependencias).

```
 Enlace WordPress / texto ──► lectura de la noticia ──► ChatGPT (2 prompts editables)
                                                          │
                              revisar y editar textos ◄───┘
                                       │
                                       ▼  Publicar
                 LinkedIn · Facebook · Instagram ──► Estadísticas        Tweet para copiar en X
```

## Pestañas

- **✍️ Publicar**
  1. *Noticia*: pega el enlace de una entrada de WordPress (se lee su texto por la API REST de WordPress y su imagen destacada) o pega el texto directamente.
  2. *Información adicional* (opcional): el enlace de la noticia en el otro idioma, otro enlace u otro texto.
  3. *Enlace de la foto* (opcional): si se deja vacío se usa la imagen destacada de la noticia.
  - A la derecha, una **vista previa con el aspecto de LinkedIn** que se actualiza mientras se edita el texto o la foto.
  - **✨ Generar textos**: crea con ChatGPT el post (LinkedIn, Facebook e Instagram) y el tweet (X), que se copia a mano: el panel no publica en X porque su plan gratuito no da acceso a la API. Los textos se pueden editar antes de publicar.
  - El post se entrega siempre con una línea en blanco entre párrafos: se le pide a ChatGPT y además se corrige al recibirlo (separador y hashtags aparte, bloques largos divididos por frases). Lo que edites a mano no se modifica.
  - El separador entre idiomas es el que aparece entre guiones en el prompt del post (`--- Bezares s.a. ---`): si ChatGPT lo reduce a `---`, lo cambia o lo olvida, se restaura.
  - Debajo del texto de cada idioma va el enlace de ese idioma, y el tweet lleva el del suyo. Mandan los enlaces pegados, en cualquiera de los dos campos; el que falte se toma de la web (menú de idiomas de WPML o `hreflang`).
  - **🖼 Generar imagen**: ChatGPT crea una imagen cuadrada (JPG) con el logo de Bezares para ilustrar la noticia (prompt, modelo y logo editables) y la pone como foto. Se guarda en `public/generadas`, que necesita permiso de escritura; las que no usa ninguna publicación se borran a los 30 días.
  - **💬 Pedir cambios a ChatGPT**: una vez generados, se escribe qué cambiar y ChatGPT lo aplica al post, al tweet o a los dos, partiendo de los textos actuales (con las ediciones a mano).
  - **🚀 Publicar ahora**: publica en las redes marcadas y muestra el enlace de cada publicación o el error.
  - **📅 Programar**: elige fecha y hora (zona horaria de `config.php`) y la publicación sale sola en ese momento.
- **📰 Publicaciones**: todas las publicaciones anteriores, con buscador, filtro por red, textos, resultado en cada red y estadísticas.
- **🗓 Programadas**: publicaciones pendientes con su fecha, redes y textos; se puede **cambiar la fecha**, **publicar ahora** o **cancelar**. Debajo, el historial con el resultado en cada red. Avisa si el cron no se está ejecutando.
- **💬 Respuestas**: comentarios que recibe lo publicado desde el panel en los últimos 30 días, con aviso de los nuevos (menú, pestaña y notificación del navegador), filtros y contestación, con propuesta de ChatGPT (prompt editable) que siempre se revisa antes de enviar. El cron los busca cada 30 minutos (LinkedIn, cada hora). Necesita los permisos nuevos de cada red (ver las guías de Configuración). Por las normas de LinkedIn, el texto de sus comentarios se borra a las 48 horas, no se guarda el nombre de sus autores y la IA está desactivada para ellos hasta que se active en Configuración.
- **@ Menciones**: publicaciones de otros que mencionan a Bezares (LinkedIn y Facebook), fotos de Instagram con etiqueta y @menciones de Instagram (por webhook), con aviso, filtros, comentario y propuesta de ChatGPT. Instagram no permite comentar las fotos en las que solo se etiqueta, y LinkedIn puede no dejar leer o comentar publicaciones de sus miembros. X no está incluida.
- **📊 Estadísticas**: impresiones, reacciones, comentarios, compartidos y clics por publicación y por red, con totales y tasa de interacción. Botón «Actualizar estadísticas» (o `cron.php`). Seguidores, seguidores nuevos y variación neta por red: el total se guarda cada día desde la instalación. Se compara con otro periodo: por defecto el **periodo anterior** de la misma duración, o el mismo periodo del año anterior, o fechas personalizadas.
- **⚙️ Configuración**: claves de ChatGPT, LinkedIn, Facebook e Instagram, con un botón **❔ Cómo conseguir las claves** (ventana con instrucciones paso a paso) otro para **probar la conexión** y otro para **verificar los permisos** de la API uno a uno (solo lectura: no publica nada). Prompts editables. En **General** se elige cuántos meses se guardan las publicaciones (12 por defecto; 0 = siempre).
- **👥 Usuarios** (administradores): personas con acceso y su rol. Los editores ven **👤 Mi cuenta** para cambiar su contraseña.

### Roles

| Rol | Publicar | Estadísticas | Configuración (claves y redes) | Usuarios |
|---|---|---|---|---|
| Administrador | ✓ | ✓ | ✓ | ✓ |
| Editor | ✓ | ✓ | — | solo su contraseña |

Los permisos se comprueban en el servidor en cada operación. El primer usuario es administrador y siempre debe quedar al menos uno.

### Prompts por defecto (editables)

- Post: `Cree un post para linkedin con emojis y hashtags, para Bezares SA, en ingles y español separados por "--- Bezares s.a. ---"`
- Tweet: `Cree un tweet, en ingles, para compartir esa noticia de Bezares SA`

## Requisitos

- **PHP 7.4 o superior** con `pdo_mysql`, `curl`, `mbstring`, `json`, `dom` y `libxml`.
- **MySQL 5.7+ / MariaDB 10.3+**.
- Apache con `mod_rewrite` (o Nginx, ver abajo) y **HTTPS**.

## Instalación

0. Genera el paquete limpio (sin el historial `.git`, sin `config.php` ni archivos de desarrollo):
   ```powershell
   powershell -ExecutionPolicy Bypass -File build.ps1
   ```
   y sube el contenido de `distezares-social-<versión>.zip`. **No subas la carpeta del proyecto tal cual** ni un ZIP hecho a mano: incluiría `.git`.
1. Crea un subdominio (p. ej. `social.bezares.com`) y apunta su carpeta raíz a **`public/`**. Si el hosting no lo permite, sube todo a la raíz: el `.htaccess` incluido redirige a `public/` y bloquea el resto.
2. Crea una base de datos MySQL vacía y un usuario con permisos sobre ella.
3. Copia `config.sample.php` como **`config.php`** y rellena la base de datos, `base_url`, una `setup_key` larga y aleatoria y una **`secret_key`** distinta, aleatoria y de al menos 32 caracteres (cifra las claves de las redes; sin ella no se pueden guardar).
4. Abre la web: se crean las tablas y se pide la `setup_key` para crear el **primer usuario**.
5. En **Configuración**, rellena cada servicio siguiendo el botón **❔ Cómo conseguir las claves** y pulsa **Guardar y probar conexión**.
6. Programa el cron **cada minuto** (publica las programadas a su hora y, cada 6 horas, actualiza las estadísticas):
   ```
   * * * * * php /ruta/a/bezares-social/cron.php
   ```
   Sin cron, las programadas solo salen cuando alguien abre el panel (con retraso), y la pestaña Programadas lo avisa.

### Nginx

```nginx
root /ruta/a/bezares-social/public;
location / { try_files $uri /index.php$is_args$args; }
location ~ \.php$ { include fastcgi_params; fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; fastcgi_pass unix:/run/php/php-fpm.sock; }
```

## Notas por red

| Red | Publicación | Estadísticas | Observaciones |
|---|---|---|---|
| LinkedIn | Post de la página de empresa con foto, o con tarjeta del enlace si no hay foto | Impresiones, alcance, reacciones, comentarios, compartidos, clics | Requiere la *Community Management API* aprobada. El token dura 60 días: el panel avisa y basta con volver a pulsar «Conectar con LinkedIn». |
| Facebook | Foto con texto, o post con enlace | Reacciones, comentarios, compartidos y las métricas de insights disponibles | Token de página permanente (usuario del sistema). La app debe estar en modo *Live*. |
| Instagram | Foto con texto (la foto es obligatoria) | Me gusta, comentarios, alcance, guardados, compartidos, visualizaciones | Cuenta profesional vinculada a la página de Facebook. JPG público < 8 MB. |

Las estadísticas de LinkedIn se piden de 10 en 10 posts por llamada, para no agotar su límite diario. Si un envío se interrumpe, queda como error en las redes que faltan (o «No publicada» si no salió en ninguna), nunca como publicado.

Los textos de LinkedIn se escapan automáticamente (formato *little text*) para que los paréntesis no corten el post y los hashtags funcionen.

## Seguridad

- Acceso con usuario y contraseña (límite de intentos fallidos), opción «No cerrar sesión» (30 días; se revoca al cerrar sesión o cambiar la contraseña), sesiones con caducidad, CSRF en todos los formularios y CSP estricta (solo scripts propios).
- La clave de instalación (`setup_key`) también tiene límite de intentos.
- Las claves de ChatGPT y de las redes se guardan **cifradas** (AES-256-GCM) con la `secret_key` de `config.php`, y nunca se vuelven a mostrar en el panel. Si cambias `secret_key`, vuelve a introducirlas.
- Los enlaces pegados (noticia y foto) solo se descargan de direcciones públicas: se bloquean redes privadas, locales, CGNAT, IPv4 dentro de IPv6 y el propio servidor (protección SSRF), con tamaño máximo y redirecciones validadas. Las llamadas a las APIs solo van por HTTPS.
- Los enlaces que se muestran en el panel solo pueden ser http/https, y los ID y versiones de Configuración se validan antes de usarse en las direcciones de las APIs.
- Roles **administrador** y **editor** comprobados en el servidor; solo los administradores ven o cambian claves y usuarios.
- Si no se puede cifrar (falta OpenSSL o `secret_key` no es válida), las claves **no se guardan** y el panel lo indica: nunca se almacenan sin cifrar. Las claves antiguas sin cifrar se cifran solas en cuanto hay `secret_key`.
- El token de Meta viaja en la cabecera `Authorization`, no en la URL. «Conectar con LinkedIn» se inicia por POST con token CSRF.
- La raíz web debe ser `public/`; el paquete de `build.ps1` no incluye `.git`.

## Estructura

```
public/            index.php (entrada), app.css, app.js, iconos
app/bootstrap.php  configuración y utilidades
app/admin.php      panel: Publicar, Estadísticas, Configuración (+ instrucciones)
app/news.php       lectura de noticias (WordPress REST / HTML)
app/ai.php         ChatGPT
app/posttext.php   párrafos, separador y enlace de cada idioma
app/social.php     LinkedIn, Facebook e Instagram (publicar, estadísticas, prueba)
app/comments.php   respuestas: lectura, avisos y contestación
app/apicheck.php   verificación de los permisos de cada API
app/mentions.php   menciones: lectura, webhook de Instagram, avisos y comentario
app/imagegen.php   imagen de la noticia con IA
app/followers.php  seguidores de cada red
app/guides.php     instrucciones para conseguir las claves
app/publisher.php  publicación en varias redes y actualización de estadísticas
app/settings.php   ajustes y prompts
app/auth.php       usuarios
app/db.php         base de datos y esquema
cron.php           actualización periódica de estadísticas
```
