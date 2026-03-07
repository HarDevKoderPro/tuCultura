/**
 * JavaScript para Detalle de Producto
 * Tu Cultura es Progreso - Tienda Virtual
 */

const API_BASE = 'php/';
let productoActual = null;
let cantidadSeleccionada = 1;

document.addEventListener('DOMContentLoaded', () => {
    const params = new URLSearchParams(window.location.search);
    const productoId = params.get('id');
    
    if (productoId) {
        cargarProducto(productoId);
    } else {
        mostrarError('Producto no especificado');
    }
    
    actualizarContadorCarrito();
    initBusqueda();
});

function initBusqueda() {
    const btnBuscar = document.getElementById('btn-buscar');
    const inputBusqueda = document.getElementById('busqueda-input');
    
    if (btnBuscar && inputBusqueda) {
        btnBuscar.addEventListener('click', () => {
            const query = inputBusqueda.value.trim();
            if (query.length >= 2) {
                window.location.href = `index.html?buscar=${encodeURIComponent(query)}`;
            }
        });
        
        inputBusqueda.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                const query = inputBusqueda.value.trim();
                if (query.length >= 2) {
                    window.location.href = `index.html?buscar=${encodeURIComponent(query)}`;
                }
            }
        });
    }
}

async function cargarProducto(id) {
    const container = document.getElementById('producto-detalle');
    
    try {
        const response = await fetch(`${API_BASE}productos.php?action=detalle&id=${id}`);
        const data = await response.json();
        
        if (data.success) {
            productoActual = data.producto;
            renderizarProducto(data.producto);
            renderizarRelacionados(data.producto.relacionados);
            actualizarBreadcrumb(data.producto);
            document.title = `${data.producto.nombre} - Tu Cultura es Progreso`;
        } else {
            mostrarError(data.error || 'Producto no encontrado');
        }
    } catch (error) {
        console.error('Error:', error);
        mostrarError('Error al cargar el producto');
    }
}

function renderizarProducto(producto) {
    const container = document.getElementById('producto-detalle');
    const imagen = producto.imagen || '../01-principal/imagenes/fondo-producto.png';
    const tieneOferta = producto.precio_oferta && producto.precio_oferta < producto.precio;
    
    // Estado del stock
    let stockHtml = '';
    if (producto.stock > 10) {
        stockHtml = '<span class="stock-disponible"><i class="fas fa-check-circle"></i> En stock</span>';
    } else if (producto.stock > 0) {
        stockHtml = `<span class="stock-bajo"><i class="fas fa-exclamation-circle"></i> ¡Solo quedan ${producto.stock} unidades!</span>`;
    } else {
        stockHtml = '<span class="stock-agotado"><i class="fas fa-times-circle"></i> Agotado</span>';
    }
    
    container.innerHTML = `
        <div class="producto-detalle-galeria">
            <img src="imagenes/productos/${imagen}" 
                 alt="${producto.nombre}" 
                 class="producto-detalle-imagen-principal"
                 onerror="this.src='../01-principal/imagenes/fondo-producto.png'">
        </div>
        
        <div class="producto-detalle-info">
            <span class="producto-categoria">${producto.categoria_nombre || 'Sin categoría'}</span>
            <h1>${producto.nombre}</h1>
            
            <div class="producto-detalle-precio">
                ${tieneOferta ? `
                    <span class="precio-actual">${producto.precio_oferta_formateado}</span>
                    <span class="precio-anterior">${producto.precio_formateado}</span>
                    <span class="producto-descuento">-${producto.descuento_porcentaje}% OFF</span>
                ` : `
                    <span class="precio-actual">${producto.precio_formateado}</span>
                `}
            </div>
            
            <div class="producto-detalle-stock">
                ${stockHtml}
            </div>
            
            <div class="producto-detalle-descripcion">
                ${producto.descripcion || producto.descripcion_corta || 'Sin descripción disponible.'}
            </div>
            
            ${producto.marca ? `<p><strong>Marca:</strong> ${producto.marca}</p>` : ''}
            ${producto.sku ? `<p><strong>SKU:</strong> ${producto.sku}</p>` : ''}
            
            ${producto.stock > 0 ? `
                <div class="producto-detalle-cantidad">
                    <label>Cantidad:</label>
                    <div class="cantidad-control">
                        <button onclick="cambiarCantidad(-1)">-</button>
                        <input type="number" id="cantidad-input" value="1" min="1" max="${producto.stock}" readonly>
                        <button onclick="cambiarCantidad(1)">+</button>
                    </div>
                </div>
                
                <button class="btn-comprar-ahora" onclick="comprarAhora()">
                    <i class="fas fa-bolt"></i> Comprar Ahora
                </button>
                
                <button class="btn-agregar-grande" onclick="agregarAlCarritoDetalle()">
                    <i class="fas fa-cart-plus"></i> Agregar al Carrito
                </button>
            ` : `
                <button class="btn-agregar-grande" disabled style="background: #ccc; cursor: not-allowed;">
                    <i class="fas fa-times"></i> Producto Agotado
                </button>
            `}
        </div>
    `;
}

function renderizarRelacionados(productos) {
    const container = document.getElementById('productos-relacionados');
    if (!container || !productos || productos.length === 0) return;
    
    container.innerHTML = productos.map(p => `
        <article class="producto-card">
            <div class="producto-imagen">
                <img src="imagenes/productos/${p.imagen || '../01-principal/imagenes/fondo-producto.png'}" 
                     alt="${p.nombre}"
                     onerror="this.src='../01-principal/imagenes/fondo-producto.png'">
            </div>
            <div class="producto-info">
                <h3 class="producto-nombre">
                    <a href="producto.html?id=${p.id}">${p.nombre}</a>
                </h3>
                <div class="producto-precios">
                    ${p.precio_oferta ? `
                        <span class="producto-precio">${p.precio_oferta_formateado}</span>
                        <span class="producto-precio-original">${p.precio_formateado}</span>
                    ` : `
                        <span class="producto-precio">${p.precio_formateado}</span>
                    `}
                </div>
            </div>
        </article>
    `).join('');
}

function actualizarBreadcrumb(producto) {
    const catElement = document.getElementById('breadcrumb-categoria');
    const prodElement = document.getElementById('breadcrumb-producto');
    
    if (catElement) catElement.textContent = producto.categoria_nombre || 'Sin categoría';
    if (prodElement) prodElement.textContent = producto.nombre;
}

function cambiarCantidad(delta) {
    const input = document.getElementById('cantidad-input');
    if (!input || !productoActual) return;
    
    let nuevaCantidad = cantidadSeleccionada + delta;
    nuevaCantidad = Math.max(1, Math.min(nuevaCantidad, productoActual.stock));
    
    cantidadSeleccionada = nuevaCantidad;
    input.value = nuevaCantidad;
}

async function agregarAlCarritoDetalle() {
    if (!productoActual) return;
    
    try {
        const formData = new FormData();
        formData.append('producto_id', productoActual.id);
        formData.append('cantidad', cantidadSeleccionada);
        
        const response = await fetch(`${API_BASE}carrito.php?action=agregar`, {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            actualizarContadorCarrito(data.total_items);
            
            Swal.fire({
                icon: 'success',
                title: '¡Agregado al carrito!',
                html: `<p>${data.producto_nombre}</p><p>Cantidad: ${cantidadSeleccionada}</p>`,
                showCancelButton: true,
                confirmButtonText: 'Ir al Carrito',
                cancelButtonText: 'Seguir Comprando',
                confirmButtonColor: '#023859'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = 'carrito.html';
                }
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: data.error || 'No se pudo agregar al carrito'
            });
        }
    } catch (error) {
        console.error('Error:', error);
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Error de conexión'
        });
    }
}

async function comprarAhora() {
    if (!productoActual) return;
    
    try {
        const formData = new FormData();
        formData.append('producto_id', productoActual.id);
        formData.append('cantidad', cantidadSeleccionada);
        
        const response = await fetch(`${API_BASE}carrito.php?action=agregar`, {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            window.location.href = 'checkout.html';
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: data.error || 'No se pudo procesar'
            });
        }
    } catch (error) {
        console.error('Error:', error);
    }
}

async function actualizarContadorCarrito(cantidad = null) {
    const badge = document.getElementById('carrito-cantidad');
    if (!badge) return;
    
    if (cantidad !== null) {
        badge.textContent = cantidad;
        return;
    }
    
    try {
        const response = await fetch(`${API_BASE}carrito.php?action=contar`);
        const data = await response.json();
        if (data.success) {
            badge.textContent = data.total_items;
        }
    } catch (error) {
        console.error('Error:', error);
    }
}

function mostrarError(mensaje) {
    const container = document.getElementById('producto-detalle');
    container.innerHTML = `
        <div style="grid-column: 1/-1; text-align: center; padding: 3rem;">
            <i class="fas fa-exclamation-triangle" style="font-size: 4rem; color: #dc3545; margin-bottom: 1rem;"></i>
            <h2>${mensaje}</h2>
            <a href="index.html" class="btn-primario" style="display: inline-block; margin-top: 1rem;">
                <i class="fas fa-arrow-left"></i> Volver a la Tienda
            </a>
        </div>
    `;
}
