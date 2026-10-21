
# Plataforma Legal V2

Instrucciones para instalar, configurar y ejecutar el proyecto en un entorno de desarrollo local.

## 1. Requisitos previos

Antes de comenzar, instalar:

- Git.
- Visual Studio Code.
- XAMPP con PHP 8.2.12 o una versión de PHP compatible.
- Composer 2.
- Node.js 24 y npm.
- MySQL Server 8.
- MySQL Workbench (recomendado).

### Tecnologías del proyecto

- Laravel 12
- PHP
- MySQL 8
- Blade
- Tailwind CSS 4
- JavaScript
- Vite

**Importante:** utilizamos MySQL Server 8 como base de datos. No es necesario iniciar MariaDB desde XAMPP.

XAMPP proporciona PHP, pero la aplicación se ejecuta mediante el servidor de desarrollo de Laravel.


# Plataforma Legal V2

Instrucciones para instalar, configurar y ejecutar el proyecto en un entorno de desarrollo local.

## 1. Requisitos previos

Antes de comenzar, instalar:

- Git y GitHub Desktop.
- Visual Studio Code.
- XAMPP con PHP 8.2.12 o una versión de PHP compatible.
- Composer 2.
- Node.js 24 y npm.
- MySQL Server 8.
- MySQL Workbench (recomendado).

### Tecnologías del proyecto

- Laravel 12
- PHP
- MySQL 8
- Blade
- Tailwind CSS 4
- JavaScript
- Vite

**Importante:** utilizamos MySQL Server 8 como base de datos. No es necesario iniciar MariaDB desde XAMPP.

XAMPP proporciona PHP, pero la aplicación se ejecuta mediante el servidor de desarrollo de Laravel.

## 2. Clonar el repositorio

### Opción recomendada: GitHub Desktop

1. Abrir GitHub Desktop.
2. Seleccionar **File > Clone repository**.
3. Elegir el repositorio del proyecto.
4. Seleccionar una ubicación local, por ejemplo:

   `D:\projects\plataforma-legal-V2`

5. Hacer clic en **Clone**.
6. Abrir la carpeta descargada en Visual Studio Code.

### Alternativa: terminal

Cuando el repositorio esté publicado, ejecutar:

```bash
git clone URL_DEL_REPOSITORIO
```

Entrar en la carpeta del proyecto:

```powershell
cd plataforma-legal-V2
```

Reemplazar `URL_DEL_REPOSITORIO` con la dirección real del repositorio una vez creado.

## 3. Instalar dependencias PHP

Desde una terminal ubicada en la raíz del proyecto:

```powershell
composer install
```

Este comando instalará las dependencias definidas en `composer.lock` dentro de la carpeta `vendor`.

No es necesario subir `vendor` a GitHub.

### Verificar Laravel

En Windows, utilizando PHP de XAMPP:

```powershell
& "C:\xampp\php\php.exe" artisan --version
```

Debe mostrarse la versión de Laravel instalada.

**Nota:** Composer debe disponer de una versión compatible de PHP y de las extensiones requeridas. El proyecto está configurado para resolver dependencias compatibles con PHP 8.2.12.

## 4. Instalar dependencias frontend

Ejecutar:

```powershell
npm ci
```

Esto instalará las dependencias exactas de `package-lock.json` dentro de `node_modules`.

Incluye Vite, Tailwind CSS y la configuración de Rollup WebAssembly utilizada en el proyecto.

No es necesario subir `node_modules` a GitHub.

## 5. Configurar variables de entorno

Crear el archivo `.env` utilizando la plantilla `.env.example`.

En PowerShell:

```powershell
Copy-Item .env.example .env
```

Abrir `.env` y configurar las variables correspondientes a la computadora.

Ejemplo:

```dotenv
APP_NAME="Plataforma Legal"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8090

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=plataforma_legal_v2
DB_USERNAME=root
DB_PASSWORD=
```

**Importante:** asignar a `DB_PASSWORD` la contraseña real de MySQL de esa computadora, si corresponde.

El archivo `.env` contiene configuraciones privadas y no debe subirse al repositorio.

### Generar clave de aplicación

Después de configurar `.env`, ejecutar:

```powershell
& "C:\xampp\php\php.exe" artisan key:generate
```

Este comando genera la clave de cifrado propia de la instalación.

## 6. Configurar MySQL

1. Abrir MySQL Workbench.
2. Conectarse a MySQL Server 8.
3. Crear la base de datos:

```sql
CREATE DATABASE IF NOT EXISTS plataforma_legal_v2
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;
```

4. Confirmar que los valores de `.env` coincidan con la configuración de MySQL.

Si se modifica `.env` y Laravel continúa utilizando una configuración anterior, ejecutar:

```powershell
& "C:\xampp\php\php.exe" artisan config:clear
```

### Ejecutar migraciones

```powershell
& "C:\xampp\php\php.exe" artisan migrate
```

Este comando creará las tablas definidas en `database/migrations`.

Para consultar su estado:

```powershell
& "C:\xampp\php\php.exe" artisan migrate:status
```

**Advertencia:** no utilizar `migrate:fresh` sobre una base de datos con información que deba conservarse, ya que elimina las tablas antes de recrearlas.

## 7. Ejecutar la aplicación

Durante el desarrollo se utilizan dos terminales.

### Terminal 1 — Laravel

En la raíz del proyecto:

```powershell
& "C:\xampp\php\php.exe" artisan serve --host=127.0.0.1 --port=8090 --no-reload
```

La aplicación estará disponible en:

http://127.0.0.1:8090

Para detener el servidor, presionar `Ctrl + C`.

### Terminal 2 — Vite y Tailwind CSS

Abrir otra terminal en la raíz del proyecto:

```powershell
npm run dev
```

Vite observará los cambios en los archivos CSS, JavaScript y las vistas Blade para actualizar los recursos durante el desarrollo.

Mantener ambas terminales abiertas mientras se trabaja.

**Importante:** cuando se utiliza `npm run dev`, debe mantenerse abierto el proceso de Vite. Para ejecutar la aplicación sin ese proceso, primero se deben compilar los recursos mediante `npm run build`.

## 8. Compilar recursos para producción

Ejecutar:

```powershell
npm run build
```

Vite generará los recursos optimizados dentro de:

```text
public/build/
```

Esta carpeta contiene archivos generados y no se incluye en GitHub.

## 9. Comandos útiles

| Comando | Función |
|---|---|
| `composer install` | Instalar dependencias PHP |
| `npm ci` | Instalar dependencias frontend desde el lockfile |
| `npm run dev` | Iniciar Vite en desarrollo |
| `npm run build` | Compilar CSS y JavaScript |
| `php artisan route:list` | Mostrar rutas registradas |
| `php artisan migrate` | Ejecutar migraciones pendientes |
| `php artisan migrate:status` | Consultar estado de migraciones |
| `php artisan config:clear` | Limpiar configuración almacenada |
| `php artisan optimize:clear` | Limpiar cachés de Laravel |
| `php artisan test` | Ejecutar pruebas automatizadas |

En Windows, cuando PHP no está disponible directamente en PATH o apunta a una versión incompatible, sustituir `php artisan` por:

```powershell
& "C:\xampp\php\php.exe" artisan
```

Seguido del comando correspondiente.

## 10. Archivos que no se descargan desde GitHub

Por seguridad y para evitar archivos innecesarios, el repositorio no incluye:

- `.env`
- `vendor/`
- `node_modules/`
- `public/build/`
- Archivos temporales y registros de Laravel.

Después de clonar el proyecto, seguir las secciones anteriores para regenerar los archivos necesarios.

## 11. Resolución de problemas comunes

### Laravel no encuentra las dependencias

Ejecutar:

```powershell
composer install
```

### No existe APP_KEY

Ejecutar:

```powershell
& "C:\xampp\php\php.exe" artisan key:generate
```

### Error de conexión con MySQL

Verificar:

- Que MySQL Server esté iniciado.
- Que exista la base de datos.
- Que el puerto sea correcto.
- Que el usuario y contraseña coincidan con `.env`.

Después ejecutar:

```powershell
& "C:\xampp\php\php.exe" artisan config:clear
```

### Los estilos de Tailwind no aparecen

Comprobar que Vite esté ejecutándose:

```powershell
npm run dev
```

O compilar los recursos:

```powershell
npm run build
```

Actualizar el navegador después de la compilación.

### Windows bloquea Rollup

En algunos equipos Windows, Smart App Control puede bloquear el módulo nativo de Rollup.

El proyecto utiliza una sustitución mediante npm para emplear Rollup WebAssembly.

Después de clonar, utilizar `npm ci` para conservar la resolución de dependencias del repositorio.

No es necesario desactivar Smart App Control.


## 3. Instalar dependencias PHP

Desde una terminal ubicada en la raíz del proyecto:

```powershell
composer install
```

Este comando instalará las dependencias definidas en `composer.lock` dentro de la carpeta `vendor`.

No es necesario subir `vendor` a GitHub.

### Verificar Laravel

En Windows, utilizando PHP de XAMPP:

```powershell
& "C:\xampp\php\php.exe" artisan --version
```

Debe mostrarse la versión de Laravel instalada.

**Nota:** Composer debe disponer de una versión compatible de PHP y de las extensiones requeridas. El proyecto está configurado para resolver dependencias compatibles con PHP 8.2.12.

## 4. Instalar dependencias frontend

Ejecutar:

```powershell
npm ci
```

Esto instalará las dependencias exactas de `package-lock.json` dentro de `node_modules`.

Incluye Vite, Tailwind CSS y la configuración de Rollup WebAssembly utilizada en el proyecto.

No es necesario subir `node_modules` a GitHub.

## 5. Configurar variables de entorno

Crear el archivo `.env` utilizando la plantilla `.env.example`.

En PowerShell:

```powershell
Copy-Item .env.example .env
```

Abrir `.env` y configurar las variables correspondientes a la computadora.

Ejemplo:

```dotenv
APP_NAME="Plataforma Legal"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8090

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=plataforma_legal_v2
DB_USERNAME=root
DB_PASSWORD=
```

**Importante:** asignar a `DB_PASSWORD` la contraseña real de MySQL de esa computadora, si corresponde.

El archivo `.env` contiene configuraciones privadas y no debe subirse al repositorio.

### Generar clave de aplicación

Después de configurar `.env`, ejecutar:

```powershell
& "C:\xampp\php\php.exe" artisan key:generate
```

Este comando genera la clave de cifrado propia de la instalación.

## 6. Configurar MySQL

1. Abrir MySQL Workbench.
2. Conectarse a MySQL Server 8.
3. Crear la base de datos:

```sql
CREATE DATABASE IF NOT EXISTS plataforma_legal_v2
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;
```

4. Confirmar que los valores de `.env` coincidan con la configuración de MySQL.

Si se modifica `.env` y Laravel continúa utilizando una configuración anterior, ejecutar:

```powershell
& "C:\xampp\php\php.exe" artisan config:clear
```

### Ejecutar migraciones

```powershell
& "C:\xampp\php\php.exe" artisan migrate
```

Este comando creará las tablas definidas en `database/migrations`.

Para consultar su estado:

```powershell
& "C:\xampp\php\php.exe" artisan migrate:status
```

**Advertencia:** no utilizar `migrate:fresh` sobre una base de datos con información que deba conservarse, ya que elimina las tablas antes de recrearlas.

## 7. Ejecutar la aplicación

Durante el desarrollo se utilizan dos terminales.

### Terminal 1 — Laravel

En la raíz del proyecto:

```powershell
& "C:\xampp\php\php.exe" artisan serve --host=127.0.0.1 --port=8090 --no-reload
```

La aplicación estará disponible en:

http://127.0.0.1:8090

Para detener el servidor, presionar `Ctrl + C`.

### Terminal 2 — Vite y Tailwind CSS

Abrir otra terminal en la raíz del proyecto:

```powershell
npm run dev
```

Vite observará los cambios en los archivos CSS, JavaScript y las vistas Blade para actualizar los recursos durante el desarrollo.

Mantener ambas terminales abiertas mientras se trabaja.

**Importante:** cuando se utiliza `npm run dev`, debe mantenerse abierto el proceso de Vite. Para ejecutar la aplicación sin ese proceso, primero se deben compilar los recursos mediante `npm run build`.

## 8. Compilar recursos para producción

Ejecutar:

```powershell
npm run build
```

Vite generará los recursos optimizados dentro de:

```text
public/build/
```

Esta carpeta contiene archivos generados y no se incluye en GitHub.

## 9. Comandos útiles

| Comando | Función |
|---|---|
| `composer install` | Instalar dependencias PHP |
| `npm ci` | Instalar dependencias frontend desde el lockfile |
| `npm run dev` | Iniciar Vite en desarrollo |
| `npm run build` | Compilar CSS y JavaScript |
| `php artisan route:list` | Mostrar rutas registradas |
| `php artisan migrate` | Ejecutar migraciones pendientes |
| `php artisan migrate:status` | Consultar estado de migraciones |
| `php artisan config:clear` | Limpiar configuración almacenada |
| `php artisan optimize:clear` | Limpiar cachés de Laravel |
| `php artisan test` | Ejecutar pruebas automatizadas |

En Windows, cuando PHP no está disponible directamente en PATH o apunta a una versión incompatible, sustituir `php artisan` por:

```powershell
& "C:\xampp\php\php.exe" artisan
```

Seguido del comando correspondiente.

## 10. Archivos que no se descargan desde GitHub

Por seguridad y para evitar archivos innecesarios, el repositorio no incluye:

- `.env`
- `vendor/`
- `node_modules/`
- `public/build/`
- Archivos temporales y registros de Laravel.

Después de clonar el proyecto, seguir las secciones anteriores para regenerar los archivos necesarios.

## 11. Resolución de problemas comunes

### Laravel no encuentra las dependencias

Ejecutar:

```powershell
composer install
```

### No existe APP_KEY

Ejecutar:

```powershell
& "C:\xampp\php\php.exe" artisan key:generate
```

### Error de conexión con MySQL

Verificar:

- Que MySQL Server esté iniciado.
- Que exista la base de datos.
- Que el puerto sea correcto.
- Que el usuario y contraseña coincidan con `.env`.

Después ejecutar:

```powershell
& "C:\xampp\php\php.exe" artisan config:clear
```

### Los estilos de Tailwind no aparecen

Comprobar que Vite esté ejecutándose:

```powershell
npm run dev
```

O compilar los recursos:

```powershell
npm run build
```

Actualizar el navegador después de la compilación.

### Windows bloquea Rollup

En algunos equipos Windows, Smart App Control puede bloquear el módulo nativo de Rollup.

El proyecto utiliza una sustitución mediante npm para emplear Rollup WebAssembly.

Después de clonar, utilizar `npm ci` para conservar la resolución de dependencias del repositorio.

No es necesario desactivar Smart App Control.
