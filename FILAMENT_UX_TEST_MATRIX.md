# Validación de correcciones de Filament

Ejecutada el 12 de noviembre de 2026 sobre una integración local de `dev` (`8098eb0`), los PR en revisión #105, #108, #109 y #110 y las tres ramas de pruebas y corrección del incremento 4. `dev` ya incluye los PR #103 y #104. El estado **Listo** registra una ejecución local satisfactoria del caso. La revisión y CI de cada PR se consultan en GitHub.

## E2E en Chromium

| Módulo / Sección | Caso de prueba | Resultado esperado | Estado |
| --- | --- | --- | --- |
| Dashboard / Inicio | Filtrar métricas por período | Al cambiar de Hoy a Últimos 7 días y a un rango personalizado, deben actualizarse los contadores y validarse que Desde y Hasta aparezcan solo al personalizar. | Listo |
| Flota / Acciones | Desactivar unidad desde el menú | Al confirmar la desactivación, debe actualizarse la unidad y validarse la notificación, el contador y su presencia en Inactivos. | Listo |
| Flota / Lista | Mostrar unidad, matrícula y capacidad de la flota | Al abrir el listado, deben aparecer las insignias de unidad y país en sus propias celdas y validarse la matrícula, la marca, el modelo con el año y la etiqueta de capacidad positiva y que el estado se consulte en las pestañas. | Listo |
| Flota / Formulario | Completar campos de Flota en pantallas pequeñas y medianas | Al abrir el formulario a 375, 768 y 1280 px, deben verse los campos de identificación y validarse que se puedan completar la unidad y la matrícula a 375 px. | Listo |
| Conductores / Lista | Filtrar conductores por estado | Al alternar Todos, Activos e Inactivos, deben cambiar las filas y validarse los contadores de cada pestaña. | Listo |
| Viajes / Filtros | Mostrar viajes de hoy y consultar otra fecha | Al abrir el listado y elegir una fecha personalizada, deben cambiar las filas y validarse los controles de fecha, el horario combinado, el contador de hoy y la visibilidad a 375, 768 y 1280 px. | Listo |
| Viajes / Acciones | Iniciar y completar un viaje | Al confirmar el inicio y la finalización, debe avanzar el estado del viaje y validarse el menú, el botón principal, los contadores y las horas reales. | Listo |
| Viajes / Navegación | Abrir edición o historial según el estado | Al pulsar un viaje programado editable y luego uno completado, deben abrirse la edición y el historial respectivamente, y validarse que el historial muestre la hora real de llegada. | Listo |
| Viajes / Acciones | Cancelar un viaje y preparar su reemplazo | Al confirmar la cancelación, debe guardarse el motivo y validarse el bloqueo de un envío vacío, el contador de Cancelados y la fecha, el autobús y el conductor precargados en el reemplazo. | Listo |

Títulos de Pest conservados en inglés para los tres casos existentes de Viajes, en el mismo orden de la tabla:

- `shows trips for today by default and lets users filter by another date`
- `starts and completes a scheduled trip`
- `opens replanning or trip history based on the trip status`

## Trazabilidad de la matriz

| Referencia | Módulo | Evidencia local |
| --- | --- | --- |
| CP-0010–CP-0013 | Dashboard | E2E del selector. `TripStatusOverviewTest` cubre las cuatro métricas, los períodos de 7 y 30 días, el rango invertido y la visibilidad por permiso. |
| CP-0065, CP-0067, CP-0073, CP-0074 | Flota | E2E de presentación y desactivación. `BusResourceManagementTest` cubre archivados, filtros de vehículo, reactivación y consulta para un usuario sin permiso de edición. |
| CP-0079, CP-0081, CP-0089, CP-0090 | Conductores | E2E de pestañas. `DriverResourceLifecycleTest` cubre correo y vencimiento en la misma celda que el nombre y la licencia respectivamente, contadores con archivados y ambas transiciones de estado. |
| CP-0095–CP-0101 | Viajes | E2E del rango de fecha. `TripTablePresentationTest` y `TripResourceFiltersTest` cubren presentación, orden, pestañas y la combinación de fecha, estado, conductor y autobús. |
| CP-0107, CP-0108 | Viajes | E2E de navegación. `TripResourcePlanningTest` verifica el cambio de autobús conservando las horas y `TripResourceAccessTest` verifica el rechazo al abrir directamente la edición de viajes en curso, completados o cancelados. |
| CP-0109–CP-0114 | Viajes | E2E de inicio, finalización y cancelación. `TripResourceLifecycleTest` cubre permisos y validación del motivo en servidor. `TripResourceReplacementTest` cubre la creación de un registro independiente, el formulario precargado y los recursos no disponibles. |

Los casos de la matriz se ajustaron al diseño especificado. Flota y Conductores exponen el estado mediante pestañas. Viajes mantiene el badge de estado y los contadores de hoy al consultar otras fechas.

La etiqueta «No aplica» para capacidad cero quedó **Pendiente** de verificación en navegador. La restricción PostgreSQL `buses_capacity_check` solo admite valores entre 1 y 32767.

Las pruebas Feature de formularios, asignaciones recurrentes, paradas, rutas, roles y usuarios cubrieron fechas invertidas, navegación por permisos, archivado, restauración y columnas configurables.

## Resultados

- Integración local `4fc6216` · 9 E2E de Chromium (115 aserciones) y 158 pruebas Feature enfocadas (1056 aserciones). El árbol de archivos coincide con el que se usó en la última ejecución E2E. Entre la ejecución Feature y la final solo se agregó la prueba de navegador de Flota.
- Corrección de replanificación `073f08b` · 37 pruebas Feature de Viajes y 328 aserciones en su rama independiente.
- La ejecución local de toda la suite Feature de Filament se interrumpió al no devolver un resultado. La rama `test/e2e-filament-browser-suite` pertenece a otra revisión y queda fuera de esta ejecución.
