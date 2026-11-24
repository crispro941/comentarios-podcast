# Terror en Rapel — CMS v2.0
### Sistema de Podcast con Panel de Control Completo

---

## 📦 Estructura de archivos

```
terrorenrapel/
├── index.php          ← Página pública principal
├── admin.php          ← Panel de control
├── config.php         ← Constantes y defaults
├── functions.php      ← Lógica, seguridad, helpers
├── .htaccess          ← Seguridad Apache
├── data/              ← Datos JSON (auto-creado)
│   ├── config.json
│   ├── users.json
│   ├── stories.json
│   ├── subscribers.json
│   └── analytics.json
├── cache/             ← Caché del feed (auto-creado)
└── uploads/           ← Subidas (auto-creado)
```

---

## 🚀 Instalación

### Requisitos
- PHP 8.1 o superior
- Módulos PHP: `json`, `simplexml`, `openssl`
- Servidor web: Apache (con mod_rewrite) o Nginx
- Permisos de escritura en `/data`, `/cache`, `/uploads`

### Pasos

1. **Sube todos los archivos** a tu servidor vía FTP/SFTP.

2. **Dar permisos de escritura** (en Linux/cPanel):
   ```bash
   chmod 755 data/ cache/ uploads/
   chmod 644 *.php .htaccess
   ```

3. **Accede al panel** en `https://tusitio.com/admin.php`

4. **Credenciales por defecto:**
   - Usuario: `admin`
   - Contraseña: `admin123`
   - ⚠️ **CAMBIA LA CONTRASEÑA INMEDIATAMENTE**

5. **Configura tu feed RSS** en el panel → Feed & Episodios

---

## ⚙️ Configuración inicial

### 1. Cambiar contraseña de admin
Panel → Usuarios → Editar admin → Nueva contraseña

### 2. Configurar feed RSS
Panel → Feed & Episodios → Pegar la URL de tu feed

**URLs de feed compatibles:**
- **Spreaker:** `https://www.spreaker.com/show/XXXXX/episodes/feed`
- **Anchor/Spotify:** `https://anchor.fm/s/XXXXX/podcast/rss`
- **Buzzsprout:** `https://feeds.buzzsprout.com/XXXXX.rss`
- **Podbean:** `https://feed.podbean.com/XXXXX/feed.xml`
- **Libsyn:** `https://XXXXX.libsyn.com/rss`
- **Simplecast:** `https://feeds.simplecast.com/XXXXX`
- **Cualquier RSS estándar**

### 3. Configurar información del sitio
Panel → Configuración → General

### 4. Agregar redes sociales
Panel → Configuración → Redes Sociales

---

## 🔐 Seguridad

- Protección CSRF en todas las acciones del panel
- Escape XSS en todas las salidas (función `h()`)
- Rate limiting en formularios públicos
- Bloqueo por exceso de intentos de login (5 intentos → 15 min)
- Session timeout automático (2 horas)
- Sanitización de URLs para evitar SSRF
- Validación de todos los inputs
- Headers de seguridad via .htaccess

---

## 👥 Sistema de roles

| Rol         | Permisos |
|-------------|----------|
| Super Admin | Todo     |
| Admin       | Todo excepto crear admins |
| Editor      | Contenido, patrocinadores, analytics |
| Observador  | Solo lectura y analytics |
| Personalizado | Permisos manuales |

---

## 📻 Funciones del reproductor

- ▶️ Play/Pausa/Anterior/Siguiente
- ⏩ Skip adelante/atrás configurables
- 🔀 Modo aleatorio
- 🔁 Repetición de episodio
- 🔊 Control de volumen
- ⚡ Control de velocidad (0.75x → 2x)
- 😴 Temporizador de sueño
- 🕐 Guardado de posición entre sesiones
- ❤️ Favoritos (localStorage)
- 📋 Cola de reproducción
- ⌨️ Atajos de teclado (Espacio, ←→ skip, ↑↓ volumen, N/P next/prev)
- 📱 Media Session API (controles en notificación)

### Atajos de teclado
| Tecla     | Acción |
|-----------|--------|
| Espacio   | Play/Pausa |
| ← / →     | Retroceder/Adelantar |
| ↑ / ↓     | Subir/Bajar volumen |
| N         | Siguiente episodio |
| P         | Episodio anterior |
| M         | Silenciar |
| S         | Aleatorio |
| R         | Repetir |

---

## 📊 Panel de Control

- **Dashboard:** resumen de episodios, reproducciones, historias pendientes
- **Feed & Episodios:** preview del feed, detección automática de proveedor
- **Analíticas:** gráficos de vistas y reproducciones (30 días), top episodios
- **Historias:** aprobar/rechazar/eliminar historias de oyentes
- **Patrocinadores:** gestión CRUD visual
- **Newsletter:** lista de suscriptores con exportación
- **Configuración General:** título, perfil del host, secciones
- **Diseño:** colores, fuentes, layout, efectos
- **Reproductor:** opciones de audio, botones, funciones
- **Redes Sociales:** todas las plataformas de podcast + sociales
- **SEO:** meta tags, OG, Twitter Card, Analytics
- **Apoyo:** funding, Patreon, PayPal, Ko-fi, Mercado Pago
- **Avanzado:** CSS/JS personalizado, cookies
- **Usuarios:** gestión de roles y permisos
- **Mantenimiento:** modo mantenimiento, limpieza de caché, exportación

---

## 🌐 Plataformas de Podcast soportadas

Spotify · Apple Podcasts · Deezer · Google Podcasts · iHeart · Podcast Addict · Podchaser · Amazon Music · Pocket Casts · Spreaker · RSS directo

---

## 💰 Plataformas de Apoyo soportadas

Spreaker Supporters Club · Patreon · PayPal · Ko-fi · Buy Me a Coffee · Mercado Pago · URL personalizada

---

## 🔄 Nginx (alternativa a Apache)

Si usas Nginx en lugar de Apache, agrega esto a tu configuración:

```nginx
location ~* ^/(data|cache|uploads/stories) {
    deny all;
    return 403;
}
location ~* \.(json|log|sql|env|md|sh|bak)$ {
    deny all;
}
```

---

## 📝 Notas importantes

- Los datos se guardan en archivos JSON en `/data/`. Para sitios con alto tráfico, considera migrar a SQLite o MySQL.
- El caché del feed se almacena en `/cache/` y se renueva según el TTL configurado.
- Los archivos `config.php` y `functions.php` están protegidos por .htaccess para acceso web directo.

---

## 🆘 Soporte

Si el feed no carga:
1. Verifica que la URL sea correcta y accesible públicamente
2. Aumenta el timeout en Panel → Feed
3. Verifica que `allow_url_fopen` esté activo en PHP
4. Revisa que el servidor tenga acceso a Internet saliente

---

*Terror en Rapel CMS v2.0 — Desarrollado con ❤️ y mucho 👻*
