# SISTEMA DAVI RAPI — Especificación Funcional

> Documento funcional de negocio para la próxima versión del sistema. Describe **qué hace** cada módulo y **cómo se conecta** con los demás. No contiene referencias a código ni recomendaciones técnicas.

---

## 1. Propósito del sistema

DAVI RAPI es una plataforma integral para **negocios de comida rápida** con operación mixta (punto físico + domicilios). En una sola herramienta cubre:

- **Operación diaria**: registro y seguimiento de pedidos, asignación a repartidores, impresión de tickets.
- **Inventario**: control de insumos por receta, descuento automático del stock al vender, ajustes manuales.
- **Finanzas**: gastos del día, cuentas por cobrar (fiados), abonos parciales, cierre de caja diario con cuadre.
- **Administración**: usuarios y roles del negocio.
- **Análisis**: dashboard con métricas en tiempo real y rangos de fecha.

El sistema está pensado para que un negocio pueda llevar todas sus operaciones, dinero y métricas desde un único lugar.

---

## 2. Vista general del sistema

```mermaid
flowchart TB
    subgraph ADMIN[ADMINISTRACIÓN]
        USR[Usuarios]
        ROL[Roles + Permisos]
    end

    subgraph OP[OPERACIÓN]
        PED[Pedidos]
        PRD[Productos]
        CLI[Clientes]
        REP[Repartidores]
        LOG[Auditoría de Pedidos]
    end

    subgraph INV[INVENTARIO]
        ING[Ingredientes]
        REC[Recetas]
        AJU[Ajustes de Inventario]
    end

    subgraph FIN[FINANZAS]
        GAS[Gastos]
        CXC[Cuentas por Cobrar]
        ABO[Abonos]
        CIE[Cierre Diario]
    end

    subgraph ANA[ANÁLISIS]
        DASH[Dashboard]
    end

    PRD --> REC --> ING
    PED -- descuenta stock --> ING
    AJU -- ajusta stock --> ING
    PED -- si es crédito --> CXC
    CXC --> ABO
    PED --> LOG
    PED --> CIE
    GAS --> CIE
    ABO --> CIE
    PED --> DASH
    GAS --> DASH
    ABO --> DASH
    ING --> DASH
    ROL -. asignado a .-> USR
    USR -. controla acceso .-> OP
    USR -. controla acceso .-> INV
    USR -. controla acceso .-> FIN
```

**Lectura del diagrama:** la operación gira alrededor de los pedidos, que tocan inventario (vía recetas) y finanzas (vía crédito). El cierre diario consolida lo que entró (ventas + abonos) y lo que salió (gastos). El dashboard observa todo. Los roles, asignados a los usuarios, definen quién puede hacer qué.

---

## 3. Módulo: Roles y Permisos (RBAC)

**Objetivo:** controlar el acceso al sistema mediante un esquema **RBAC (Role-Based Access Control)** en el que el Administrador puede crear roles personalizados y definir, para cada uno, qué puede hacer en cada módulo.

### 3.1 Concepto

- Un **rol** es un conjunto de permisos identificado por un **nombre libre** (ej. "Cajero", "Encargado de turno", "Inventarios", "Repartidor", "Contador", lo que el negocio necesite).
- Cada usuario tiene **un rol** asignado.
- El rol determina qué módulos ve y qué puede hacer en cada uno.

### 3.2 Permisos por módulo

Para cada módulo del sistema, un rol puede tener cuatro acciones independientes:

| Acción | Significa |
|---|---|
| **Ver** | El módulo aparece en el menú; el usuario puede consultar registros. |
| **Crear** | Puede registrar nuevos elementos en el módulo. |
| **Editar** | Puede modificar registros existentes. |
| **Eliminar** | Puede borrar registros (queda auditado cuando aplica). |

Las acciones son independientes entre sí, pero hay una jerarquía implícita: **sin "Ver" no se puede hacer nada más** en el módulo. Un rol puede, por ejemplo, ver y crear pedidos pero no editarlos ni eliminarlos.

### 3.3 Matriz de permisos (ejemplo)

```
                  | Ver | Crear | Editar | Eliminar
Pedidos           |  ✔  |   ✔   |   ✔    |    ✘
Productos         |  ✔  |   ✘   |   ✘    |    ✘
Ingredientes      |  ✔  |   ✔   |   ✔    |    ✘
Gastos            |  ✔  |   ✔   |   ✘    |    ✘
Cuentas por Cobrar|  ✔  |   ✘   |   ✘    |    ✘
Cierre Diario     |  ✘  |   ✘   |   ✘    |    ✘
Usuarios          |  ✘  |   ✘   |   ✘    |    ✘
Auditoría         |  ✘  |   ✘   |   ✘    |    ✘
Roles             |  ✘  |   ✘   |   ✘    |    ✘
```

Cada negocio configura sus propias matrices según cómo divida responsabilidades.

### 3.4 Funcionalidades del módulo

- Crear un nuevo rol con nombre y matriz de permisos.
- Editar el nombre o los permisos de un rol existente.
- Listar los roles del sistema con un resumen de sus permisos.
- Eliminar un rol (no se puede eliminar un rol asignado a usuarios activos).
- Asignar un rol a un usuario (desde el módulo Usuarios).

### 3.5 Módulos sobre los que aplican los permisos

Pedidos · Productos · Clientes · Repartidores · Ingredientes · Recetas · Ajustes de Inventario · Gastos · Cuentas por Cobrar · Abonos · Cierre Diario · Usuarios · Roles · Auditoría de Pedidos · Dashboard.

### 3.6 Usuario Administrador (caso especial)

Existe un usuario **"Administrador"** con privilegios totales que **no se rige por la matriz de permisos**:

- Tiene acceso completo a **todos los módulos** y todas las acciones (Ver, Crear, Editar, Eliminar).
- Es el único que puede gestionar el módulo de **Roles**.
- **No se puede eliminar ni desactivar** desde la interfaz: es el usuario maestro del sistema.
- Su rol es fijo y existe siempre, garantizando que el sistema nunca quede sin un usuario con acceso completo.

```mermaid
flowchart TB
    ADM[Usuario Administrador]
    ADM -- acceso total fijo --> ALL[Todos los módulos / todas las acciones]

    R1[Rol: Cajero]
    R2[Rol: Inventarios]
    R3[Rol: Repartidor]
    R4[Rol personalizado X]

    R1 -- matriz de permisos --> M1[Permisos por módulo]
    R2 -- matriz de permisos --> M2[Permisos por módulo]
    R3 -- matriz de permisos --> M3[Permisos por módulo]
    R4 -- matriz de permisos --> M4[Permisos por módulo]

    U1[Usuarios] -- tiene un rol --> R1
    U1 -- tiene un rol --> R2
    U1 -- tiene un rol --> R3
    U1 -- tiene un rol --> R4
```

### 3.7 Reglas clave

- Solo el **Administrador** puede crear, editar o eliminar roles.
- Un rol no puede eliminarse si tiene usuarios asignados.
- Si un usuario intenta acceder a un módulo o acción para la que no tiene permiso, el sistema lo redirige con un mensaje y no ejecuta la acción.
- Todas las acciones de eliminación, sin importar el rol, **quedan auditadas** cuando aplican (ej. eliminación de pedidos).
- El usuario **Administrador** existe siempre y no se ve afectado por cambios en el módulo de Roles.

---

## 4. Módulo: Autenticación

**Objetivo:** controlar quién entra al sistema y bloquear intentos maliciosos.

**Funcionalidades:**
- Login con usuario y contraseña.
- Cierre de sesión.
- **Protección contra fuerza bruta**: tras 5 intentos fallidos consecutivos, la cuenta queda bloqueada 15 minutos.
- Mensaje de intentos restantes para guiar al usuario legítimo.
- Tras un login exitoso, se reinicia el contador de fallos.

**Flujo de inicio de sesión:**

```mermaid
flowchart TD
    A[Usuario llega al login] --> B{¿Cuenta bloqueada?}
    B -- Sí --> C[Mostrar tiempo restante de bloqueo]
    B -- No --> D[Intentar autenticar]
    D --> E{¿Credenciales válidas?}
    E -- Sí --> F[Resetear intentos fallidos]
    F --> G[Redirigir al Dashboard]
    E -- No --> H[Incrementar contador]
    H --> I{¿5 intentos fallidos?}
    I -- Sí --> J[Bloquear cuenta 15 min]
    I -- No --> K[Mostrar intentos restantes]
```

---

## 5. Módulo: Productos (Menú)

**Objetivo:** mantener el catálogo de productos que se venden.

**Atributos del producto:**
- Nombre, precio, descripción, imagen.
- Estado: disponible / no disponible (un producto desactivado no se vende).

**Funcionalidades:**
- Crear, editar, eliminar productos.
- Subir imagen del producto.
- Activar/desactivar disponibilidad sin eliminar el registro.
- Cada producto puede tener una **receta** (ver módulo Recetas) que define qué insumos consume al venderse.

**Reglas de negocio:**
- No se puede eliminar un producto que ya tiene ventas asociadas (se debe desactivar en su lugar).
- El precio debe ser numérico y mayor a cero.

---

## 6. Módulo: Clientes

**Objetivo:** mantener un directorio de clientes recurrentes para acelerar la toma de pedidos y soportar el crédito.

**Atributos:**
- Nombre completo, teléfono, dirección.

**Funcionalidades:**
- Registrar, editar, eliminar clientes.
- Listado de clientes históricos.
- **Auto-creación**: cuando se hace una venta a Crédito y el teléfono ingresado no existe, el cliente se crea automáticamente.

**Conexión con otros módulos:**
- Toda **Cuenta por Cobrar** está vinculada a un cliente.
- En el formulario de pedido, los nombres y teléfonos se autocompletan desde aquí.

---

## 7. Módulo: Repartidores

**Objetivo:** gestionar al personal que entrega los domicilios.

**Atributos:**
- Nombre, apellido, teléfono.

**Funcionalidades:**
- Registrar, editar, eliminar repartidores.
- Asignar repartidores a pedidos de tipo domicilio.
- Un repartidor puede estar **vinculado a un usuario del sistema**, lo que le permite iniciar sesión y ver únicamente sus entregas. El rol que tenga ese usuario define qué más puede hacer.

**Conexión con otros módulos:**
- Cada pedido a domicilio referencia a un repartidor.
- El **Dashboard** calcula el ranking de repartidores por número de entregas y monto generado.
- El repartidor recibe sus ganancias en función del **costo de envío** acumulado de los pedidos entregados.

---

## 8. Módulo: Pedidos (Ventas)

**Objetivo:** registrar y dar seguimiento a todas las ventas del negocio. Es el corazón operativo del sistema.

### 8.1 Atributos del pedido

- **Cliente**: nombre, teléfono, dirección (opcional).
- **Tipo**: local (en el punto físico) o domicilio.
- **Repartidor**: solo si es domicilio.
- **Costo de envío**: aplica solo a domicilios.
- **Producto y cantidad** (un pedido puede tener varios productos — se llama "pedido multi-producto").
- **Método de pago**: Efectivo, Nequi, Daviplata, Cuenta/Transferencia, Crédito (fiado).
- **Estado**: recibido → preparando → en camino → entregado, o cancelado.
- **Identificador de grupo**: cuando un pedido tiene varios productos, todos comparten un mismo identificador para tratarse como una sola transacción.

### 8.2 Cálculo del total

Para cada línea de producto:
```
total_línea = (precio_producto × cantidad) + costo_envío
```
El costo de envío se aplica una sola vez al pedido completo (en la primera línea del grupo), no por cada producto.

### 8.3 Flujo de creación de un pedido

```mermaid
flowchart TD
    A[Cajero/Staff abre Nuevo Pedido] --> B[Captura datos del cliente]
    B --> C[Selecciona Local o Domicilio]
    C --> D{¿Es domicilio?}
    D -- Sí --> E[Asigna repartidor + costo de envío]
    D -- No --> F[Sin repartidor]
    E --> G[Agrega productos al carrito]
    F --> G
    G --> H[Selecciona método de pago]
    H --> I{¿Método = Crédito?}
    I -- Sí --> J[Buscar/crear cliente por teléfono]
    J --> K[Generar Cuenta por Cobrar]
    I -- No --> L[Venta directa]
    K --> M[Guardar pedido con estado 'recibido']
    L --> M
    M --> N[Descontar insumos según receta]
    N --> O[Imprimir ticket]
```

### 8.4 Ciclo de vida del estado del pedido

```mermaid
stateDiagram-v2
    [*] --> recibido
    recibido --> preparando
    preparando --> en_camino: si es domicilio
    preparando --> entregado: si es local
    en_camino --> entregado
    recibido --> cancelado
    preparando --> cancelado
    en_camino --> cancelado
    cancelado --> recibido: reactivación manual
    entregado --> [*]
```

### 8.5 Reglas de negocio de pedidos

- Un pedido a **domicilio obliga** a tener repartidor; un pedido **local** no.
- Si el método de pago es **Crédito**, el total no entra como ingreso real hasta que se abone (ver Cuentas por Cobrar).
- Cuando un pedido pasa a **entregado**, queda registrada la fecha/hora de entrega.
- Cuando un pedido se **cancela**, el inventario se restaura automáticamente.
- Si un pedido cancelado se **reactiva**, el inventario se vuelve a descontar.
- Si un pedido se **edita** (cambia producto o cantidad), el sistema restaura los insumos antiguos y descuenta los nuevos.
- Un pedido **multi-producto** se gestiona como un grupo: cambiar el estado actualiza todos los productos del grupo a la vez.
- Los pedidos **cancelados** se ocultan del listado por defecto y no cuentan en métricas.
- Toda edición, cambio de estado o eliminación queda registrada en la **Auditoría de Pedidos**.

### 8.6 Impresión de tickets

- **Ticket individual**: por una línea de producto.
- **Ticket grupal**: por todo el pedido multi-producto, con el desglose y el total consolidado.
- El ticket muestra los datos del negocio (logo, NIT, dirección, teléfono).

---

## 9. Módulo: Auditoría de Pedidos (OrderLogs)

**Objetivo:** dejar huella de **toda modificación** sobre un pedido para tener trazabilidad y resolver disputas.

**Qué se registra automáticamente:**
- Cambio de estado (de "recibido" a "entregado", etc.).
- Cambio de producto, cantidad, tipo, costo de envío, cliente o método de pago.
- Cancelación de pedido (con autor y fecha).
- Eliminación definitiva del pedido (queda el log incluso después de borrar).

**Información en cada entrada:**
- Pedido afectado.
- Usuario que hizo el cambio.
- Detalle textual del cambio (ej. "Estado: de 'preparando' a 'entregado' por jhon").
- Fecha/hora exacta.

**Acceso:** solo el Admin puede consultar la auditoría.

---

## 10. Módulo: Ingredientes (Insumos)

**Objetivo:** llevar el control del stock de materias primas que se consumen al producir.

**Atributos del ingrediente:**
- Nombre (único), unidad de medida (gr, ml, unidad, etc.), stock actual, costo unitario.

**Funcionalidades:**
- Crear, editar, eliminar ingredientes.
- Visualizar stock y costo.
- Al eliminar un ingrediente, el sistema también elimina sus referencias en recetas y su historial de ajustes.

**Conexión con otros módulos:**
- Las **Recetas** los enlazan con los productos.
- Los **Pedidos** descuentan stock automáticamente vía la receta.
- Los **Ajustes de Inventario** suman o restan stock manualmente.
- El **Dashboard** muestra alertas de stock bajo (≤ 5 unidades).

---

## 11. Módulo: Recetas (ProductIngredients)

**Objetivo:** definir la "fórmula" de cada producto — qué insumos consume y en qué cantidad.

**Estructura:**
- Cada línea de receta = un producto + un ingrediente + cantidad requerida por unidad de producto.
- Un producto puede tener muchos ingredientes; un ingrediente puede estar en muchos productos.

**Funcionalidades:**
- Pantalla de receta por producto: añadir ingredientes con su cantidad.
- Eliminar ingredientes de la receta.
- Al añadir un ingrediente a la receta, se puede actualizar **el costo del insumo** en la misma operación (útil para mantener costos al día).

**Conexión central:** la receta es lo que conecta el mundo de **ventas** con el mundo de **inventario** y permite calcular el **costo real** de cada producto vendido.

```mermaid
flowchart LR
    PRD[Producto: Hamburguesa] --> R1[200gr Carne]
    PRD --> R2[1 unidad Pan]
    PRD --> R3[20gr Queso]
    PRD --> R4[15ml Salsa]

    R1 --> ING1[Insumo: Carne]
    R2 --> ING2[Insumo: Pan]
    R3 --> ING3[Insumo: Queso]
    R4 --> ING4[Insumo: Salsa]
```

---

## 12. Módulo: Ajustes de Inventario

**Objetivo:** corregir el stock por motivos que no son ventas (mermas, devoluciones, compras de insumo, daños, robos).

**Atributos del ajuste:**
- Ingrediente afectado.
- Tipo: **entrada** (suma stock) o **baja** (resta stock).
- Cantidad ajustada.
- Motivo (obligatorio): ej. "compra a proveedor", "merma", "daño", "conteo físico".
- Observaciones (opcional).
- Fecha y autor.

**Funcionalidades:**
- Listado histórico de ajustes (con qué pasó, cuánto y por qué).
- Registrar nuevo ajuste — al guardar, el stock del ingrediente se actualiza al instante.
- Eliminar un ajuste también revierte su impacto en el stock.

**Flujo:**

```mermaid
flowchart LR
    A[Encargado detecta diferencia] --> B[Registrar ajuste]
    B --> C{Tipo}
    C -- Entrada --> D[Stock + cantidad]
    C -- Baja --> E[Stock - cantidad]
    D --> F[Queda registrado con motivo]
    E --> F
```

---

## 13. Flujo integrado: Pedido → Inventario → Finanzas

Este es el flujo más importante del sistema porque conecta los tres núcleos.

```mermaid
sequenceDiagram
    actor Cajero
    participant Pedido
    participant Receta
    participant Inventario
    participant CxC as Cuentas por Cobrar
    participant Cierre as Cierre Diario

    Cajero->>Pedido: Crear pedido (productos + método pago)
    Pedido->>Receta: ¿Qué insumos consume?
    Receta->>Inventario: Restar insumos del stock
    alt Método = Crédito
        Pedido->>CxC: Generar deuda a nombre del cliente
        Note over CxC: No suma como ingreso aún
    else Método ≠ Crédito
        Pedido->>Cierre: Cuenta como ingreso del día
    end
    Cajero->>Pedido: Marca como entregado
    Pedido->>Pedido: Registra fecha de entrega
```

---

## 14. Módulo: Gastos

**Objetivo:** registrar todas las salidas de dinero del negocio para que el cierre diario y el dashboard reflejen la realidad.

**Atributos:**
- Descripción, monto, fecha.

**Funcionalidades:**
- Listar gastos ordenados por fecha.
- Registrar nuevo gasto (rápido, un solo formulario).
- Eliminar gasto.

**Conexión con otros módulos:**
- Los gastos del día se restan en el **Cierre Diario**.
- El **Dashboard** los muestra como total y los descuenta de la utilidad neta.

**Reglas:**
- Los montos deben ser positivos.
- Los gastos no se asignan a un pedido específico — son egresos generales del negocio.

---

## 15. Módulo: Cuentas por Cobrar (Crédito / Fiado)

**Objetivo:** gestionar las ventas a crédito ("fiados") y su recuperación mediante abonos parciales.

**Cómo nace una cuenta por cobrar:**
- Automáticamente, cuando se hace un pedido con método de pago **Crédito**.
- Manualmente, registrando una deuda directa a un cliente.

**Atributos:**
- Cliente deudor.
- Pedido asociado (si nació de una venta).
- Monto total adeudado.
- Descripción.
- Estado: **pendiente** o **pagado**.

**Funcionalidades:**
- Listado priorizado: primero las pendientes, luego las pagadas.
- Crear deuda manual.
- Registrar **abonos parciales** (ver siguiente módulo).
- Marcar como pagada manualmente.
- Eliminar la cuenta (queda registro).

**Flujo de recuperación:**

```mermaid
flowchart TD
    A[Pedido con pago Crédito] --> B[Cuenta por Cobrar pendiente]
    B --> C[Cliente abona parcial]
    C --> D[Sumar abono]
    D --> E{¿Saldo = 0?}
    E -- No --> F[Sigue pendiente con saldo menor]
    E -- Sí --> G[Marcar como pagada]
    F --> C
```

**Regla clave:** la venta a crédito **no cuenta como ingreso real** hasta que el cliente abona. Lo que cuenta como ingreso es el **abono**, no la venta.

---

## 16. Módulo: Abonos a Cuentas (AccountPayments)

**Objetivo:** registrar pagos parciales sobre una cuenta por cobrar.

**Atributos:**
- Cuenta a la que se abona.
- Monto del abono.
- Método de pago (Efectivo, Nequi, Daviplata, etc.).
- Fecha (automática) y observaciones.

**Comportamiento automático:**
- Al guardar un abono, el sistema suma todos los abonos previos de esa cuenta.
- Si el total abonado **iguala o supera** la deuda, la cuenta cambia automáticamente a **pagada**.
- Si no, queda como **pendiente** con saldo restante.

**Conexión:**
- Los abonos del día son **ingresos reales** y entran al cierre diario y al dashboard.
- Aparecen desglosados por método de pago en el resumen financiero.

---

## 17. Módulo: Cierre Diario de Caja

**Objetivo:** al final del día, cuadrar lo que el sistema esperaba en caja contra lo que realmente hay (conteo físico).

**Cálculo del esperado:**
```
Ingresos del día  = Ventas no-crédito del día + Abonos recibidos hoy
Salidas del día   = Gastos registrados hoy
Esperado neto     = Ingresos del día - Salidas del día
```

**Datos del cierre:**
- Fecha del cierre.
- Base inicial (caja con la que se arrancó el día).
- Monto esperado (calculado).
- Monto real (lo que se contó físicamente).
- Diferencia: real − esperado (positiva = sobrante, negativa = faltante).
- Observaciones.

**Flujo:**

```mermaid
flowchart TD
    A[Fin del día] --> B[Encargado abre Nuevo Cierre]
    B --> C[Sistema calcula esperado]
    C --> D[Encargado cuenta caja física]
    D --> E[Captura monto real]
    E --> F[Sistema calcula diferencia]
    F --> G{¿Diferencia?}
    G -- 0 --> H[Cuadre perfecto]
    G -- Positiva --> I[Sobrante - revisar]
    G -- Negativa --> J[Faltante - investigar]
    H --> K[Guardar cierre]
    I --> K
    J --> K
```

**Importancia del módulo:** es la herramienta de control de caja del dueño. Sin esto, no hay forma de detectar errores de cobro, pérdidas u olvidos de registro.

---

## 18. Módulo: Usuarios

**Objetivo:** gestionar quién entra al sistema y con qué rol.

**Atributos:**
- Usuario, contraseña.
- **Rol asignado** (uno solo, tomado del módulo de Roles).
- Vínculo opcional con un **registro de repartidor**: cuando un usuario representa a un repartidor físico, se enlaza para que el sistema pueda mostrarle solo sus entregas y calcular sus ganancias.
- Contadores de seguridad: intentos fallidos, hora de bloqueo.

**Funcionalidades:**
- Crear, editar, eliminar usuarios.
- Asignar/cambiar el rol del usuario.
- Vincular o desvincular el usuario a un repartidor.
- Reiniciar el bloqueo de cuenta.

**Reglas:**
- Solo el **Administrador** o un rol con permisos sobre el módulo Usuarios puede crearlos o editarlos.
- El **usuario Administrador** no puede eliminarse desde la interfaz.
- Un usuario sin rol asignado no puede iniciar sesión.
- Si un usuario está vinculado a un repartidor, el sistema le filtra automáticamente los pedidos a los suyos, independientemente de los permisos de su rol.

---

## 19. Módulo: Dashboard

**Objetivo:** ofrecer una vista única y filtrable del estado del negocio.

### 19.1 Vista para usuarios vinculados a un repartidor

Cuando un usuario está **vinculado a un repartidor**, su dashboard se reduce automáticamente a sus propios datos:

- **Entregas en el período**: número de pedidos que ha entregado.
- **Ganancia del período**: suma de los costos de envío de sus pedidos entregados.
- **Pendientes hoy**: pedidos asignados que aún no se entregan ni cancelan.
- Botón directo a "Mis entregas".

Esta vista es independiente del rol RBAC: no importa qué permisos tenga, si está enlazado a un repartidor el dashboard es personal.

### 19.2 Vista general (usuarios no repartidores)

```mermaid
flowchart TB
    DASH[Dashboard general]

    DASH --> M1[Métricas financieras]
    DASH --> M2[Análisis comercial]
    DASH --> M3[Alertas operativas]
    DASH --> M4[Filtros]

    M1 --> M1a[Ingresos: ventas no-crédito + abonos]
    M1 --> M1b[Costo de insumos vendidos]
    M1 --> M1c[Total de gastos]
    M1 --> M1d[Total de envíos]
    M1 --> M1e[Utilidad neta real]
    M1 --> M1f[Desglose por método de pago]

    M2 --> M2a[Ventas por día - tendencia]
    M2 --> M2b[Top 5 productos más vendidos]
    M2 --> M2c[Ranking de repartidores]
    M2 --> M2d[Ventas en local vs domicilio]

    M3 --> M3a[Insumos con stock bajo]

    M4 --> M4a[Rango de fechas]
```

### 19.3 Cálculos clave del dashboard

| Métrica | Cómo se calcula |
|---|---|
| Ingresos | Ventas que no son crédito + Abonos recibidos en el período. |
| Costo de insumos vendidos | Suma del costo de cada ingrediente consumido por los productos vendidos. |
| Total envíos | Suma de costos de envío de todos los pedidos del período. |
| Total gastos | Suma de gastos del período. |
| **Utilidad neta** | Ingresos − Envíos − Costo de insumos − Gastos. |
| Pedidos contados | Se cuenta cada pedido multi-producto como **una sola transacción**, no por cada línea. |

### 19.4 Filtros

- **Rango de fechas**: todas las métricas se recalculan según el rango (por defecto, todo el histórico).

### 19.5 Reglas

- Los pedidos **cancelados** se excluyen de **todas** las métricas.
- El crédito (fiado) **no cuenta como ingreso** hasta que se abone — el dashboard refleja flujo de caja real, no facturación bruta.

---

## 20. Mapa completo de relaciones entre módulos

```mermaid
erDiagram
    ROL ||--o{ PERMISO : define
    PERMISO }o--|| MODULO : aplica_a
    USUARIO }o--|| ROL : tiene
    USUARIO }o--o| REPARTIDOR : puede_ser

    PRODUCTO ||--o{ RECETA : compone
    INGREDIENTE ||--o{ RECETA : participa_en
    INGREDIENTE ||--o{ AJUSTE : se_ajusta

    PEDIDO }o--|| PRODUCTO : contiene
    PEDIDO }o--o| REPARTIDOR : asignado_a
    PEDIDO }o--o| CLIENTE : para
    PEDIDO ||--o{ AUDITORIA : genera

    PEDIDO ||--o| CXC : si_es_credito
    CLIENTE ||--o{ CXC : adeuda
    CXC ||--o{ ABONO : recibe

    PEDIDO }o--|| CIERRE : cuenta_en
    GASTO }o--|| CIERRE : descuenta_en
    ABONO }o--|| CIERRE : cuenta_en
```

---

## 21. Reglas globales de negocio

### Sobre dinero
1. El crédito (fiado) **no es ingreso** hasta que se abona.
2. Los abonos del día sí son ingreso real.
3. Los gastos siempre son positivos y restan del balance.
4. La utilidad real = ingresos efectivos − envíos − costo de insumos − gastos.
5. El cierre diario debe cuadrar entre lo esperado por el sistema y el conteo físico.

### Sobre inventario
1. Vender un producto descuenta automáticamente sus insumos según la receta.
2. Cancelar un pedido restaura los insumos.
3. Editar un pedido restaura los insumos viejos y descuenta los nuevos.
4. Si un producto no tiene receta, no se hace movimiento de inventario al venderlo.
5. Los ajustes manuales registran el motivo siempre.

### Sobre operación
1. Domicilio sin repartidor no es válido.
2. Local no requiere repartidor ni dirección.
3. Un pedido cancelado no aparece en el listado por defecto ni en métricas.
4. Toda modificación a un pedido queda en auditoría con autor y momento.
5. Pedidos multi-producto se manejan como una sola transacción a efectos de estado, ticket y conteo.

### Sobre acceso
1. El control de acceso es **RBAC**: roles personalizados con matriz de permisos (Ver / Crear / Editar / Eliminar) por módulo.
2. Solo el usuario **Administrador** tiene acceso total fijo y no se rige por la matriz.
3. Solo el Administrador puede gestionar el módulo de Roles.
4. Un usuario vinculado a un repartidor solo ve sus propios pedidos y sus propias métricas, independientemente de los permisos del rol.
5. Toda eliminación queda registrada en auditoría cuando aplica al pedido.

---

## 22. Glosario rápido

| Término | Significado en el sistema |
|---|---|
| **Pedido / Venta** | Una transacción de uno o varios productos, con o sin domicilio. |
| **Pedido grupo** | Pedido con varios productos, manejado como una única transacción. |
| **Local** | Venta en el punto físico, sin domicilio. |
| **Domicilio** | Venta con entrega a la dirección del cliente, requiere repartidor y costo de envío. |
| **Crédito / Fiado** | Venta sin pago inmediato; genera una cuenta por cobrar. |
| **Abono** | Pago parcial sobre una deuda. |
| **Cierre diario** | Cuadre de caja al final del día. |
| **Receta** | Lista de insumos que consume un producto. |
| **Ajuste de inventario** | Movimiento manual de stock (entrada o baja) con motivo. |
| **Stock bajo** | Insumo con 5 unidades o menos. |
| **Ranking de repartidores** | Repartidores ordenados por número de entregas y monto generado. |
| **Auditoría** | Bitácora inmutable de cambios sobre pedidos. |
| **Rol** | Conjunto de permisos identificado por nombre, asignado a usuarios. |
| **Permiso** | Habilitación de una acción (Ver/Crear/Editar/Eliminar) sobre un módulo, dentro de un rol. |
| **RBAC** | Role-Based Access Control: control de acceso basado en roles. |
| **Administrador** | Usuario maestro con acceso total fijo, no sujeto a la matriz de permisos. |

---

## 23. Resumen ejecutivo en una página

DAVI RAPI es la plataforma diaria de un negocio de comida rápida. Un cajero registra un **pedido** indicando productos, cliente y método de pago; el sistema **descuenta los insumos** automáticamente según la **receta** de cada producto. Si el pago es a **crédito**, se crea una **cuenta por cobrar** que el cliente irá pagando con **abonos**; mientras tanto no cuenta como ingreso real. Si el pedido es a **domicilio**, se asigna un **repartidor** con un **costo de envío** que él gana al entregar.

Al final del día, el encargado hace el **cierre diario**: el sistema calcula lo que debería haber en caja sumando ventas no-crédito y abonos del día, restando los **gastos**; el encargado cuenta físicamente y registra la **diferencia**.

El **dashboard** muestra en tiempo real ingresos, gastos, utilidad real, ranking de repartidores, productos más vendidos y alertas de stock. Toda **modificación de pedido queda auditada**.

El control de acceso es **RBAC**: el Administrador crea roles con cualquier nombre y define, para cada módulo, qué acciones pueden hacer (Ver / Crear / Editar / Eliminar). Existe un usuario **Administrador** fijo con acceso total que no se rige por la matriz de permisos.
