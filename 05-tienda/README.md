# Módulo de Tienda Virtual - Tu Cultura es Progreso

## Descripción
Módulo completo de tienda virtual integrado con la aplicación Tu Cultura es Progreso.

## Estructura de Archivos

```
05-tienda/
├── admin/                      # Panel de administración
│   ├── index.html             # Dashboard admin
│   ├── productos.html         # CRUD de productos
│   ├── categorias.html        # CRUD de categorías
│   ├── pedidos.html           # Gestión de pedidos
│   └── admin-productos.js     # JS para productos
├── css/
│   └── tienda.css             # Estilos principales
├── js/
│   ├── tienda.js              # JS catálogo principal
│   ├── producto.js            # JS detalle de producto
│   ├── carrito.js             # JS carrito de compras
│   ├── checkout.js            # JS proceso de compra
│   └── pedidos.js             # JS historial de pedidos
├── php/
│   ├── config.php             # Configuración y helpers
│   ├── productos.php          # API de productos
│   ├── categorias.php         # API de categorías
│   ├── carrito.php            # API del carrito
│   ├── pedidos.php            # API de pedidos
│   └── admin.php              # API de administración
├── imagenes/
│   └── productos/             # Imágenes de productos
├── index.html                 # Página principal tienda
├── producto.html              # Detalle de producto
├── carrito.html               # Carrito de compras
├── checkout.html              # Proceso de checkout
├── confirmacion.html          # Confirmación de pedido
├── mis-pedidos.html           # Historial de pedidos
├── database.sql               # Script SQL para BD
└── README.md                  # Esta documentación
```

## Configuración de Base de Datos

1. Importar el archivo `database.sql` en la base de datos `tucultur_asociados`
2. El script creará las tablas:
   - `categorias`: Categorías de productos
   - `productos`: Catálogo de productos
   - `pedidos`: Pedidos realizados
   - `detalle_pedido`: Items de cada pedido
   - `carrito`: Carrito de compras
   - `favoritos`: Lista de deseos
   - `resenas`: Reseñas de productos

## Características

### Catálogo de Productos
- Listado con paginación
- Filtros por categoría y precio
- Ordenamiento múltiple
- Búsqueda de productos
- Productos destacados
- Vista detallada

### Carrito de Compras
- Agregar/eliminar productos
- Actualizar cantidades
- Cálculo automático de totales
- Persistencia por sesión/usuario
- Indicador de envío gratis

### Checkout
- Formulario de datos de envío
- Resumen del pedido
- Métodos de pago: Contraentrega, Transferencia
- Validación de formularios
- Confirmación de pedido

### Panel de Administración
- Dashboard con estadísticas
- CRUD completo de productos
- CRUD de categorías
- Gestión de pedidos (cambio de estado)
- Alertas de bajo stock

## Seguridad

- Sanitización de inputs con `htmlspecialchars`
- Prepared statements para prevenir SQL injection
- Validación de sesiones para checkout
- Control de acceso admin por email

## Configuración de Administradores

En `php/config.php`, modificar el array de emails admin:

```php
function esAdmin() {
    $admins = ['luisfer5428@gmail.com', 'admin@girabienes.com'];
    return isset($_SESSION['email']) && in_array($_SESSION['email'], $admins);
}
```

## Tecnologías

- HTML5, CSS3 (Mobile-First)
- JavaScript (ES6+)
- PHP 7.4+
- MySQL 5.7+
- SweetAlert2 para notificaciones
- Font Awesome para iconos

## URLs de Acceso

- Tienda: `/05-tienda/index.html`
- Admin: `/05-tienda/admin/index.html`

## Notas

- Los precios están en COP (Pesos Colombianos)
- Envío gratis para compras mayores a $200.000
- Costo de envío base: $15.000
- Las imágenes de productos deben subirse a `/imagenes/productos/`

## Próximas Mejoras

- [ ] Integración con pasarela de pagos
- [ ] Sistema de cupones de descuento
- [ ] Notificaciones por email
- [ ] Sistema de reseñas
- [ ] Lista de deseos
- [ ] Comparador de productos
