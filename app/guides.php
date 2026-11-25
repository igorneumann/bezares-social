<?php
/** Instrucciones de Configuración («Cómo conseguir las claves»). Los nombres de menús van en inglés porque los portales cambian según el idioma. */
class Guides
{
    const PRIVACY = 'https://bezares.com/politica-de-privacidad/';

    private static function a($url, $text)
    {
        return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">' . $text . '</a>';
    }

    /** Dato para copiar con un botón. */
    private static function copy($id, $text, $rows = 1)
    {
        return '<div class="copy"><textarea id="' . $id . '" rows="' . (int) $rows . '" readonly>' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</textarea>'
            . '<button type="button" class="btn btn-secondary btn-sm" data-copy="' . $id . '">Copiar</button></div>';
    }

    private static function errors(array $rows)
    {
        $h = '<h3>Si algo falla</h3><table class="mini"><tbody>';
        foreach ($rows as $k => $v) {
            $h .= '<tr><td><b>' . $k . '</b></td><td>' . $v . '</td></tr>';
        }
        return $h . '</tbody></table>';
    }

    public static function html($svc)
    {
        switch ($svc) {
            case 'openai':
                return self::openai();
            case 'linkedin':
                return self::linkedin();
            case 'facebook':
                return self::facebook();
            case 'instagram':
                return self::instagram();
        }
        return '';
    }

    private static function openai()
    {
        return '<ol>'
            . '<li>Entra en ' . self::a('https://platform.openai.com', 'platform.openai.com') . ' con la cuenta de la empresa. Puedes usar la misma clave que Bezares Chat.</li>'
            . '<li>En <b>Settings → Billing</b>, comprueba que hay saldo o un método de pago. La API se factura aparte de la suscripción a ChatGPT Plus/Team.</li>'
            . '<li>Ve a ' . self::a('https://platform.openai.com/api-keys', 'API keys') . ' y pulsa <b>Create new secret key</b>. Ponle de nombre «Bezares Social» y deja los permisos en <i>All</i>.</li>'
            . '<li>Copia la clave (empieza por <code>sk-</code>). <b>Solo se muestra una vez.</b> Pégala en «API key de OpenAI» y pulsa <b>Guardar y probar conexión</b>.</li>'
            . '<li><b>Modelo</b>: <code>gpt-4o-mini</code> es económico y suficiente para posts. Puedes poner otro modelo de tu cuenta si quieres más calidad.</li>'
            . '<li><b>Imágenes</b>: el botón «Generar imagen» usa la misma clave. OpenAI puede pedir <b>verificar la organización</b> (Settings → Organization) para sus modelos de imagen. Cada imagen tiene un coste aparte.</li>'
            . '<li>Los <b>prompts</b> se pueden editar aquí mismo. El texto de la noticia se añade solo detrás del prompt.</li></ol>';
    }

    private static function linkedin()
    {
        return '<div class="info-box"><b>Antes de empezar, ten a mano:</b><ul>'
            . '<li>Un usuario de LinkedIn que sea <b>superadministrador</b> de la página de Bezares.</li>'
            . '<li>Un <b>correo de empresa</b> (@bezares.com). LinkedIn rechaza los personales (Gmail, Hotmail…).</li>'
            . '<li>El nombre legal y la dirección registrada de la empresa, su web y la política de privacidad.</li>'
            . '<li>El logo cuadrado.</li></ul>'
            . 'Son unos 20 minutos, más la espera de la aprobación de LinkedIn (paso 3).</div>'

            . '<h3>1. Crear la app</h3><ol>'
            . '<li>Entra en ' . self::a('https://www.linkedin.com/developers/apps/new', 'linkedin.com/developers/apps/new') . '. Crea una app <b>nueva solo para esto</b>: si ya tiene otros productos, puede que no deje solicitar el que hace falta.</li>'
            . '<li>Rellena <b>App name</b>: «Bezares Social»; <b>LinkedIn Page</b>: la página de empresa de Bezares. <b>Elige bien: la app queda unida a esa página y no se puede cambiar después.</b></li>'
            . '<li>En <b>Privacy policy URL</b>, pon:' . self::copy('g_li_priv', self::PRIVACY) . '</li>'
            . '<li>Sube el logo, acepta las condiciones y pulsa <b>Create app</b>.</li></ol>'

            . '<h3>2. Verificar la página</h3><ol>'
            . '<li>En la pestaña <b>Settings</b> de la app, junto a la página de empresa, pulsa <b>Verify</b> y genera el enlace.</li>'
            . '<li>Abre el enlace con el usuario <b>superadministrador</b> de la página y apruébalo. Hay <b>30 días</b> para hacerlo.</li>'
            . '<li>Vuelve a <b>Settings</b> y comprueba que la página aparece como verificada. Sin esto, no sigas.</li></ol>'

            . '<h3>3. Solicitar el permiso para publicar</h3><ol>'
            . '<li>En la pestaña <b>Products</b>, busca <b>Community Management API</b> y pulsa <b>Request access</b>. «Share on LinkedIn» no sirve: es para publicar como persona.</li>'
            . '<li>Rellena <b>todos</b> los campos del formulario con los datos legales de la empresa y verifica el correo de empresa.</li>'
            . '<li>En el caso de uso, puedes pegar este texto:'
            . self::copy('g_li_use', 'Internal tool used only by the marketing team of Bezares, S.A. to publish our own company news on our own LinkedIn Page and to review the performance of those posts (impressions, clicks, reactions, comments and shares) and to read and answer the comments they receive. '
                . 'Only administrators of our Page connect the app. We do not manage pages of third parties. The text of member comments is cached for at most 48 hours, member profile data is not stored, and data is never resold or used for advertising, sales or recruiting.', 6) . '</li>'
            . '<li>Revísalo bien antes de enviarlo: <b>si lo rechazan, no se puede repetir con la misma app</b> y hay que crear otra.</li>'
            . '<li>Primero conceden el nivel <b>Development</b>: 500 llamadas al día por app y 100 por usuario, con 12 meses para pasar al nivel <b>Standard</b>. El Standard se pide después en <b>My Apps → Products</b>, con una grabación de pantalla y credenciales de prueba.</li></ol>'

            . '<h3>4. Conectar con este panel</h3><ol>'
            . '<li>En la pestaña <b>Auth</b>, en <b>Authorized redirect URLs for your app</b>, añade esta dirección <b>exactamente igual</b>:' . self::copy('g_li_redir', LinkedInApi::redirect_uri()) . '</li>'
            . '<li>En esa misma pestaña, copia el <b>Client ID</b> y el <b>Client Secret</b>, pégalos en este panel y pulsa <b>Guardar</b>.</li>'
            . '<li>Pulsa <b>Conectar con LinkedIn</b>, entra con el superadministrador y acepta. El token y el ID de la página se rellenan solos.</li>'
            . '<li>Si administras <b>varias páginas</b>, comprueba que el ID es el de Bezares: es el número de <code>linkedin.com/company/<b>12345678</b>/admin</code>.</li>'
            . '<li>Pulsa <b>Guardar y probar conexión</b>.</li></ol>'

            . '<h3>Renovación</h3><p>El acceso dura <b>60 días</b>. Si LinkedIn concede renovación automática a la app, se renueva solo durante un año. Si no, el panel avisa 10 días antes en esta pantalla y basta con volver a pulsar «Conectar con LinkedIn».</p>'

            . self::errors(array(
                'The requested permission scope is not valid' => 'La app no tiene alguno de los permisos que pide el panel. Lo más habitual es que la <b>Community Management API</b> aún no esté concedida: mira su estado en <b>Products</b> y los permisos en <b>Auth → OAuth 2.0 scopes</b>. Si falta solo alguno, usa «Probar con menos permisos».',
                'redirect_uri does not match' => 'La dirección del paso 4 no es idéntica: revisa https, la barra final y los espacios.',
                '403 / ACCESS_DENIED' => 'El usuario conectado no es administrador de la página, o conectaste antes de que concedieran el permiso: vuelve a conectar.',
                'Error de versión (426)' => 'La versión de la API ha caducado. Pon una reciente en «Versión de la API» (formato AAAAMM; en noviembre de 2026 la última es <code>202611</code>). Cada una vale al menos un año.',
                'Respuestas da error 403' => 'Falta el permiso para los comentarios: pulsa <b>Verificar permisos</b>. Si la app lo tiene, vuelve a conectar.',
                '429' => 'Has llegado al límite diario del nivel Development. Espera a mañana o solicita el nivel Standard.',
            ));
    }

    private static function facebook()
    {
        return '<div class="info-box"><b>Antes de empezar, ten a mano:</b><ul>'
            . '<li>Un usuario de Facebook con <b>control total</b> de la página de Bezares y del portfolio comercial (' . self::a('https://business.facebook.com/settings', 'business.facebook.com/settings') . ').</li>'
            . '<li>Una cuenta de desarrollador: regístrate en ' . self::a('https://developers.facebook.com', 'developers.facebook.com') . ' si es la primera vez.</li>'
            . '<li>El logo cuadrado y la dirección de la política de privacidad.</li></ul>'
            . 'Esta guía sirve también para Instagram. Si Bezares Chat ya tiene una app de Meta en el mismo portfolio, puedes usarla y empezar en el paso 2.</div>'

            . '<h3>1. Crear la app</h3><ol>'
            . '<li>Entra en ' . self::a('https://developers.facebook.com/apps/creation/', 'developers.facebook.com/apps/creation') . '. Escribe el nombre («Bezares Social») y un correo de contacto, y pulsa <b>Next</b>.</li>'
            . '<li>En los casos de uso, elige <b>Manage everything on your Page</b> (Administrar todo en tu página). Para Instagram, marca también el de gestionar contenido de Instagram, si se puede combinar. Si sale en gris o no aparece, elige <b>Other → Business</b> y después añade los productos.</li>'
            . '<li>Elige el <b>portfolio comercial de Bezares</b>. No crees otro.</li>'
            . '<li>Pulsa <b>Next</b> en los requisitos, revisa el resumen y entra con <b>Go to dashboard</b>.</li></ol>'

            . '<h3>2. Añadir los permisos</h3><ol>'
            . '<li>En el menú <b>Use cases</b>, pulsa <b>Customize</b> y añade:<br><code>pages_show_list</code> <code>pages_read_engagement</code> <code>pages_manage_posts</code> <code>pages_manage_metadata</code> <code>business_management</code> <code>read_insights</code><br>Para Respuestas y Menciones:<br><code>pages_read_user_content</code> <code>pages_manage_engagement</code></li>'
            . '<li>Para Instagram, además:<br><code>instagram_basic</code> <code>instagram_content_publish</code> <code>instagram_manage_insights</code> <code>instagram_manage_comments</code><br>Si «Verificar permisos» falla al leer respuestas o menciones de Instagram, añade también <code>ads_read</code>: Meta lo pide cuando el acceso a la página viene del portfolio comercial.</li>'
            . '<li>Para publicar solo en páginas propias, con personas que tienen un rol en la app, basta el <b>Standard Access</b>: no hace falta App Review ni verificar la empresa. Si alguno pide «Advanced Access», sáltalo y prueba antes.</li></ol>'

            . '<h3>3. Publicar la app</h3><ol>'
            . '<li>En <b>App settings → Basic</b>, completa el icono, la categoría, la información de borrado de datos y la política de privacidad:' . self::copy('g_fb_priv', self::PRIVACY) . '</li>'
            . '<li>Ve a <b>Publish</b> y pulsa <b>Go live</b>. Las apps de tipo Business no tienen este paso. Si la dejas en desarrollo, es posible que los posts solo los vean quienes tienen un rol en la app.</li></ol>'

            . '<h3>4. Crear el usuario del sistema</h3><ol>'
            . '<li>En ' . self::a('https://business.facebook.com/settings/system-users', 'Configuración del negocio → Usuarios → Usuarios del sistema') . ', pulsa <b>Agregar</b> con el rol <b>Administrador</b>. Con el acceso estándar solo puede haber <b>uno</b>: si ya existe el de Bezares Chat, usa ese mismo.</li>'
            . '<li>Pulsa <b>Asignar activos</b> y dale control total sobre <b>la app</b>, <b>la página de Facebook</b> y <b>la cuenta de Instagram</b>. Si falta uno de los tres, fallará más adelante.</li>'
            . '<li>Pulsa <b>Generar token</b>, elige la app y marca los permisos del paso 2. En caducidad, elige <b>Nunca</b> si aparece. Algunas empresas solo pueden elegir <b>60 días</b>: en ese caso hay que repetir los pasos 4 y 5 antes de que caduque.</li>'
            . '<li>Copia el token. <b>Solo se muestra una vez.</b></li></ol>'

            . '<h3>5. Conseguir el token de la página</h3><ol>'
            . '<li>Abre el ' . self::a('https://developers.facebook.com/tools/explorer/', 'Explorador de la API Graph') . ' y pega el token en <b>Access Token</b>. No lo pongas nunca en la barra de direcciones.</li>'
            . '<li>Pon esta consulta y pulsa <b>Submit</b>:' . self::copy('g_fb_q', 'me/accounts?fields=id,name,access_token') . '</li>'
            . '<li>Busca la página de Bezares. Copia su <b>id</b> en «ID de la página» y su <b>access_token</b> en «Page access token». Son los de la página, <b>no</b> el token del usuario del sistema.</li>'
            . '<li>Comprueba la caducidad: pega ese token en el ' . self::a('https://developers.facebook.com/tools/debug/accesstoken/', 'depurador de tokens') . '. Debe indicar tipo <b>Page</b>, los permisos del paso 2 y, si es permanente, <b>Expires: Never</b>.</li>'
            . '<li>Pulsa <b>Guardar y probar conexión</b>. La prueba lee la página, pero no publica: haz después una publicación real.</li></ol>'

            . self::errors(array(
                'Respuestas da error 10, 200 o 283' => 'El token es anterior a la pestaña Respuestas y no tiene sus permisos. Añádelos (paso 2) y repite los pasos 4 y 5.',
                'Menciones da error de permiso' => 'Comprueba <code>pages_read_user_content</code>. Meta puede pedir además la función <b>Page Public Content Access</b>, que necesita su revisión.',
                'me/accounts sale vacío' => 'La página no está asignada al usuario del sistema (paso 4), o falta <code>pages_show_list</code>.',
                'Error 190' => 'El token ha caducado o se ha anulado. Repite los pasos 4 y 5.',
                'Error 200 o 10' => 'Falta un permiso o no es el token de la página. Compruébalo en el depurador.',
                'El post no lo ve el público' => 'La app sigue en desarrollo: paso 3.',
                'Versión retirada' => 'Cambia «Versión de Graph API». En noviembre de 2026 la última es <code>v27.0</code>; la <code>v23.0</code> vale hasta octubre de 2027.',
            ));
    }

    private static function instagram()
    {
        return '<div class="info-box"><b>Antes de empezar:</b> sigue la guía de <b>Facebook</b> con los cuatro permisos de Instagram. Se usa la misma app y el mismo token.</div>'

            . '<h3>1. Preparar la cuenta</h3><ol>'
            . '<li>La cuenta debe ser <b>profesional</b>: en la app de Instagram, <b>Configuración → Tipo de cuenta y herramientas → Cambiar a cuenta profesional → Empresa</b>.</li>'
            . '<li>Debe estar <b>vinculada a la página de Facebook</b> de Bezares: en ' . self::a('https://business.facebook.com/settings', 'Configuración del negocio') . ', en <b>Cuentas → Cuentas de Instagram</b>, o desde la configuración de la página.</li>'
            . '<li>Si la página pide la <b>autorización de publicación</b> (Page Publishing Authorization), complétala: hasta entonces no se puede publicar.</li>'
            . '<li>Asigna la cuenta de Instagram al usuario del sistema (paso 4 de Facebook).</li></ol>'

            . '<h3>2. Conseguir el ID</h3><ol>'
            . '<li>En el ' . self::a('https://developers.facebook.com/tools/explorer/', 'Explorador de la API Graph') . ', pega el <b>token de la página</b> y pon esta consulta:' . self::copy('g_ig_q', (Settings::get('fb_page_id') ?: 'ID_PAGINA') . '?fields=instagram_business_account{id,username}') . '</li>'
            . '<li>Copia el <b>id</b> de <code>instagram_business_account</code> (suele empezar por 1784…) en «ID de la cuenta de Instagram profesional». No es el nombre de usuario ni el ID de la página.</li>'
            . '<li>Deja vacío «Access token»: se usa el de la página. Pulsa <b>Guardar y probar conexión</b>.</li></ol>'

            . '<h3>3. @Menciones (opcional)</h3><p>Instagram solo las avisa por <b>webhook</b>. Sin él, las respuestas y las etiquetas en fotos funcionan igual.</p><ol>'
            . '<li>En ' . self::a('https://developers.facebook.com/apps', 'la app de Meta') . ', en <b>App settings → Basic</b>, junto a <b>App Secret</b>, pulsa <b>Show</b> y cópialo. <b>No pulses Reset</b>: cambiaría la clave y dejaría sin avisos a Bezares Chat si usa la misma app. Guárdalo en este panel. Debe ser la misma app con la que se generó el token.</li>'
            . '<li>En la app, entra en <b>Dashboard</b> y en el caso de uso <b>Manage messaging and content on Instagram</b>. Si no está, añádelo.</li>'
            . '<li>Elige <b>API setup with Facebook login</b> (con Facebook, no con Instagram). Meta solo permite una configuración por app: si ya tiene la de Instagram, no la cambies sin comprobar si la usa Bezares Chat.</li>'
            . '<li>Pulsa <b>Add more to this use case</b> y después <b>Get real-time notifications with Webhooks</b>. Los webhooks no aparecen en el menú hasta que se añaden aquí.</li>'
            . '<li>En el desplegable <b>Select product</b>, elige <b>Instagram</b>. No cambies nada en <b>Page</b> ni en WhatsApp: pueden ser los de Bezares Chat.</li>'
            . '<li>En <b>Configure webhooks</b>, pon esta <b>Callback URL</b> y el <b>Verify token</b> que aparece en esta pantalla, y pulsa <b>Verify and save</b>:' . self::copy('g_ig_hook', Mentions::webhook_url()) . '</li>'
            . '<li>Suscríbete al campo <code>mentions</code>.</li>'
            . '<li>En este panel, pulsa <b>Suscribir la página a la app</b>. Si ya lo estaba, no se cambia nada.</li>'
            . '<li>Comprueba que la app está <b>publicada</b> (Go live): si no, Meta no envía avisos. La cuenta que menciona debe ser pública.</li>'
            . '<li>Pulsa <b>Verificar permisos</b>: «página suscrita» y «avisos recibidos» deben salir correctos.</li></ol>'
            . '<p class="muted">En las apps antiguas de tipo Business, sin casos de uso, busca Webhooks entre los productos del Dashboard. Las fotos en las que solo se etiqueta a la cuenta no se pueden comentar desde el panel.</p>'

            . '<h3>Límites de Instagram</h3><ul>'
            . '<li>Cada publicación necesita una foto en <b>JPG</b>. <b>PNG y WebP no valen</b>: si la imagen destacada de la noticia es PNG, pega el enlace de una versión JPG.</li>'
            . '<li>La foto debe ser pública en internet. Se recomienda menos de 8 MB y proporción entre 4:5 y 1,91:1.</li>'
            . '<li>Hay un límite de publicaciones por API cada 24 horas (Meta indica 100; 50 en el apartado de carruseles). Los enlaces del texto no se pueden pulsar.</li></ul>'

            . self::errors(array(
                'No sale instagram_business_account' => 'La cuenta no es profesional, no está vinculada a esa página o falta <code>instagram_basic</code>.',
                'Error 9004 o 36003' => 'La foto no es accesible, no es JPG o su proporción no vale.',
                'Error 10 o 200' => 'Falta <code>instagram_content_publish</code> en el token o la cuenta no está asignada al usuario del sistema.',
            ));
    }
}
