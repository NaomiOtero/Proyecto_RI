# 📽️ CinePelis — Sistema de Recomendación de Películas

Aplicación web de catálogo y recomendación de películas desarrollada en **PHP + MySQL**, con búsqueda booleana, corrección ortográfica por distancia de Levenshtein y recomendaciones personalizadas basadas en el comportamiento de visualización del usuario.

---

## 🗂️ Tabla de contenidos

1. [Descripción general](#descripción-general)
2. [Características principales](#características-principales)
3. [Tecnologías utilizadas](#tecnologías-utilizadas)
4. [Estructura del proyecto](#estructura-del-proyecto)
5. [Instalación y configuración](#instalación-y-configuración)
6. [Funcionalidades en detalle](#funcionalidades-en-detalle)
7. [Modelos de recuperación de información](#modelos-de-recuperación-de-información)
8. [Sistema de planes](#sistema-de-planes)

---

## Descripción general

**CinePelis** es un sistema de recuperación de información (RI) aplicado al dominio del cine. Permite a los usuarios explorar un catálogo de películas, buscar mediante expresiones booleanas, recibir sugerencias ante errores tipográficos y obtener recomendaciones personalizadas según su historial de visualización.

El sistema diferencia el contenido disponible mediante un esquema de **planes de suscripción** (Básico / Premium), restringiendo el acceso a películas exclusivas para usuarios de mayor nivel.

---

## Características principales

| Característica | Descripción |
|---|---|
| 🔐 Autenticación | Registro e inicio de sesión con contraseñas hasheadas (bcrypt) |
| 🔍 Búsqueda booleana | Operadores `AND`, `OR`, `NOT` por género o texto libre |
| 🧠 Corrección ortográfica | Distancia de Levenshtein para sugerencias ante errores de escritura |
| 🎯 Recomendaciones | Basadas en géneros de películas vistas ≥ 15 segundos |
| 📦 Planes de acceso | Básico (gratis) y Premium ($9.99/mes) con catálogos diferenciados |
| 🖼️ Imágenes en BD | Pósters almacenados como BLOB y servidos dinámicamente |
| ▶️ Reproductor integrado | Trailers de YouTube con contador de tiempo en tiempo real |
| ➕ Alta de películas | Formulario modal para agregar nuevas películas con imagen y géneros |

---

## Tecnologías utilizadas

- **Backend:** PHP 8+ (sin framework)
- **Base de datos:** MySQL / MariaDB
- **Frontend:** HTML5, Tailwind CSS (CDN), JavaScript vanilla
- **Reproductor:** YouTube iFrame API
- **Seguridad:** `password_hash` / `password_verify`, `prepared statements`, `htmlspecialchars`

---

## Estructura del proyecto

```
cinepelis/
├── index.php          # Controlador principal y vista del catálogo
├── login.php          # Registro e inicio de sesión
├── upgrade.php        # Página para mejorar plan a Premium
├── imagen.php         # Sirve BLOBs de imágenes desde la BD
├── db.php             # Conexión a MySQL
├── funciones.php      # Lógica central: búsqueda, sugerencias, recomendaciones
├── assets/
│   └── js/
│       └── main.js    # Contador de tiempo y lógica de modales
└── README.md
```

### Descripción de archivos

**`db.php`**
Establece la conexión con la base de datos `cine_pelis` usando `mysqli` con charset `utf8mb4`.

**`funciones.php`**
Contiene toda la lógica de negocio:
- `normalizar()` — elimina acentos, artículos y convierte a minúsculas.
- `obtenerSugerencia()` — aplica Levenshtein para corregir búsquedas sin resultados.
- `parsearConsultaBooleana()` — tokeniza y construye expresiones booleanas con `HAVING`.
- `buscarPeliculas()` — ejecuta la búsqueda principal filtrando por plan del usuario.
- `agregarPelicula()` — inserta una película con imagen BLOB y géneros asociados.
- `registrarTiempo()` — registra visualizaciones y genera recomendaciones si `segundos >= 15`.

**`index.php`**
Vista principal: muestra el catálogo, el buscador booleano por selects, el modal de alta de películas, el modal de reproducción de trailers y el panel de recomendaciones.

**`login.php`**
Maneja el registro de nuevos usuarios (con selección de plan) y el inicio de sesión. Valida email, longitud de contraseña y coincidencia. Usa sesiones PHP.

**`upgrade.php`**
Permite a un usuario con plan Básico (id_plan = 1) actualizar su cuenta a Premium (id_plan = 2) con un solo clic (simulación de pago).

**`imagen.php`**
Endpoint que recibe un `?id=N` por GET, consulta el BLOB de imagen de la película correspondiente y lo sirve con el `Content-Type` correcto y caché de 24 horas.

---


## Instalación y configuración

### Requisitos

- PHP 8.0 o superior con extensión `mysqli`
- MySQL 5.7+ o MariaDB 10.3+
- Servidor web: Apache / Nginx (o XAMPP / Laragon para desarrollo local)

### Pasos

1. **Clonar o copiar** los archivos en la carpeta raíz del servidor web (p. ej. `htdocs/cinepelis/`).

2. **Crear la base de datos** en MySQL:

3. **Configurar la conexión** en `db.php`:

```php
$conexion = new mysqli("localhost", "root", "", "cine_pelis");
// Para WAMP/XAMPP con puerto diferente:
// $conexion = new mysqli("127.0.0.1", "root", "", "cine_pelis", 3307);
```

4. **Insertar datos iniciales** de planes:


5. **Acceder** desde el navegador a `http://localhost/cinepelis/login.php`.

---

## Funcionalidades en detalle

### 🔍 Búsqueda booleana

El buscador admite dos modos:

**a) Búsqueda por texto libre**
El usuario escribe una consulta que puede contener operadores booleanos:
```
accion AND terror
comedia OR romance
NOT suspenso
```

**b) Búsqueda guiada por selects**
La interfaz ofrece tres controles:
- **Género 1** (select desplegable)
- **Operador** (`SOLO`, `AND`, `OR`, `NOT`)
- **Género 2** (se habilita solo para `AND` y `OR`)

Ambos modos construyen la misma expresión que se evalúa en SQL mediante una cláusula `HAVING` generada dinámicamente.

### 🧠 Corrección ortográfica (Levenshtein)

Cuando una búsqueda no retorna resultados, el sistema:

1. Normaliza el término buscado (quita acentos y artículos).
2. Calcula la **distancia de edición** entre el término y cada película visible para el plan del usuario.
3. Si la distancia mínima encontrada es **≤ 3**, clasifica el error como **"Error de sintaxis"** y muestra la película más cercana como sugerencia con su imagen.
4. Si la distancia es **> 3**, clasifica como **"Error semántico"** (el término no tiene relación con ningún título).

### 🎯 Sistema de recomendaciones

Al cerrar el modal de reproducción, el frontend envía por `POST` el `id_pelicula` y los `segundos` visualizados. El servidor ejecuta `registrarTiempo()`:

```
segundos >= 15  →  gusto = 1  →  buscar películas del mismo género  →  devolver hasta 4 recomendaciones
segundos < 15   →  gusto = 0  →  sin recomendaciones
```

Las recomendaciones se filtran por el plan del usuario y excluyen la película actual.

### 🖼️ Almacenamiento de imágenes

Los pósters se guardan como `LONGBLOB` directamente en la base de datos. El endpoint `imagen.php?id=N` los sirve con:
- `Content-Type` correcto (guardado junto al BLOB).
- `Cache-Control: max-age=86400` para evitar peticiones repetidas.

---

## Modelos de recuperación de información

| Modelo | Implementación |
|---|---|
| **Modelo Booleano** | Parser recursivo descendente que genera `HAVING` con `AND`, `OR`, `NOT` sobre nombre, autor, año y géneros |
| **Levenshtein (corrección)** | Comparación contra todos los títulos del catálogo accesible; umbral de distancia = 3 |
| **Filtrado colaborativo implícito** | Recomendaciones basadas en géneros de contenido visto ≥ 15 s (señal implícita de preferencia) |
| **Normalización léxica** | Eliminación de acentos, conversión a minúsculas y eliminación de artículos definidos (`el`, `la`, `los`, `las`) |

---

## Sistema de planes

| Plan | Precio | Acceso |
|---|---|---|
| 📦 **Básico** | Gratis | Películas con `id_plan = 1` |
| ⭐ **Premium** | $9.99/mes | Todo el catálogo (`id_plan` 1 y 2) |

- Los usuarios Básicos ven un banner de invitación a mejorar su plan.
- La página `upgrade.php` simula la activación Premium actualizando `id_plan = 2` en la sesión y en la BD.
- El filtro de plan se aplica en **todas** las consultas: búsqueda, sugerencias y recomendaciones.

---


## Autores

Proyecto desarrollado como práctica de **Recuperación de Información (RI)**.

