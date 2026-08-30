<p align="center">
  <img src="public/assets/smartbus-logo.webp" width="300" alt="SmartBus Global Logo">
</p>

# SmartBus Global - API Gateway & Authentication

[![PHP Version](https://img.shields.io/badge/PHP-8.5%2B-777BB4?style=flat-square&logo=php)](https://php.net/)
[![Laravel Version](https://img.shields.io/badge/Laravel-13.x-FF2D20?style=flat-square&logo=laravel)](https://laravel.com/)
[![Testing](https://img.shields.io/badge/Tested_with-Pest-F16529?style=flat-square)](https://pestphp.com/)
[![License](https://img.shields.io/badge/License-MIT-blue.svg?style=flat-square)](LICENSE)

## Visión General y Arquitectura

**SmartBus Global** es una plataforma integral de transporte público (B2G enfocada en Costa Rica), diseñada para la visualización de autobuses en tiempo real, predicción de llegadas (ETA) y gestión eficiente de rutas.

Este repositorio corresponde al **Servicio de Autenticación** del proyecto, encargado de la gestión de usuarios, roles y permisos, así como de la seguridad y autenticación de las aplicaciones cliente.

*   **Clientes Frontend:** Aplicación móvil unificada en Flutter que adapta su interfaz, estado y funcionalidades de forma dinámica (Conductor vs. Pasajero), y un Panel Web Administrativo (Filament).
*   **Ecosistema de Microservicios:**
    *   **Panel Administrativo:** *Este repositorio (Laravel/Filament).*
    *   **Servicio de Autenticación:** Emisión e introspección de tokens Sanctum.
    *   **API Gateway:** Servicio de enrutamiento y control de acceso.
    *   **Rastreo GPS en tiempo real:** Comunicación bidireccional usando Laravel Reverb/WebSockets.
    *   **Motor Predictivo ETA:** Inteligencia Artificial implementada en Python/TensorFlow.

---

## Stack Tecnológico y Características Principales

### Bases de Datos y Control de Acceso
*   **Múltiples Bases de Datos:**
    *   `MySQL`: Optimizada para el manejo exclusivo de usuarios, escalabilidad y análisis demográfico.
    *   `PostgreSQL` + `PostGIS`: Base de datos transaccional con extensiones espaciales para operaciones logísticas complejas.
*   **Gestión de Roles (`spatie/laravel-permission`):**
    *   `super-admin`: Control gubernamental y acceso total.
    *   `company-admin`: Dueños de empresas transportistas.
    *   `driver`: Conductores operativos.
    *   `passenger`: Pasajeros y usuarios finales.
*   **Arquitectura Multi-inquilino (Multitenancy):** Aislamiento lógico gestionado por Filament para vincular cada `company-admin` estrictamente a su flotilla.

### Seguridad, Autenticación y API
*   **Gestión de Sesiones Seguras (`laravel/sanctum`):** Tokens con expiración dinámica jerárquica (Ej: Conductores 2h, Pasajeros 30 días).
*   **Defensa y Recuperación de Cuentas:**
    *   *Rate Limiting* estricto para mitigar ataques de fuerza bruta.
    *   Flujo seguro de recuperación mediante **OTP** (códigos de 6 dígitos enviados por correo, con validez de 15 minutos).
*   **Documentación Interactiva (`dedoc/scramble`):** Especificación OpenAPI generada dinámicamente y siempre actualizada.

### Calidad e Internacionalización
*   **Soporte Bilingüe (i18n):** Middleware personalizado que interpreta el header `Accept-Language` para adaptar los mensajes, validaciones y respuestas (Español / Inglés).
*   **Pruebas Exhaustivas (`pestphp/pest`):** Suite de testing que abarca verificación de rutas protegidas, mocks de envío de correos, aserciones avanzadas y manipulación temporal (`freezeTime`).
*   **Estandarización y Clean Code (`laravel/pint`):** Garantía de uniformidad y calidad en el código fuente del equipo de desarrollo.

---

## CI/CD y Despliegue

El ciclo de vida del software está automatizado para asegurar entregas rápidas y seguras:

1.  **Integración Continua (CI):** Flujos de trabajo en **GitHub Actions** ejecutan el linter (Laravel Pint) y la suite de pruebas (Pest PHP) con cada Pull Request, garantizando integridad en un modelo *GitFlow*.
2.  **Despliegue y Contenedores (CD):** El entorno de producción está completamente paquetizado mediante un `Dockerfile` optimizado (Multistage build), listo para aprovisionamiento directo en la infraestructura Cloud de **Render**.

---

## Instalación Local

```bash
# 1. Clonar el repositorio
git clone <url-del-repo>
cd smartbus-authentication

# 2. Instalar dependencias
composer install
npm install

# 3. Configurar entorno
cp .env.example .env
php artisan key:generate

# 4. Levantar entorno local
composer run dev
```