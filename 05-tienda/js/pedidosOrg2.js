/**
 * JavaScript para Historial de Pedidos
 * Tu Cultura es Progreso - Tienda Virtual
 */

const API_BASE = 'php/';

document.addEventListener('DOMContentLoaded', () => {
    cargarPedidos();
    actualizarContadorCarrito();
});

async function cargarPedidos() {
    const container = document.getElementById('pedidos-contenido');
    
    try {
        const response = await fetch(`${API_BASE}pedidos.php?action=historial`);
        const data = await response.json();
        
        if (data.success) {
            if (data.pedidos.length === 0) {
                mostrarSinPedidos();
            } else {
                renderizarPedidos(data.pedidos);
            }
        } else if (data.error) {
            // Usuario no logueado
            container.innerHTML = `
                <div style="text-align: center; padding: 3rem; background: #fff; border-radius: 8px; box-shadow: var(--sombra);">
                    <i class="fas fa-user-lock" style="font-size: 4rem; color: #023859; margin-bottom: 1rem;"></i>
                    <h2>Inicia Sesi\u00f3n</h2>
                    <p>Debes iniciar sesi\u00f3n para ver tus pedidos.</p>
                    <a href="../02-iniciarSesion/iniciarSesion.html?origen=tienda" class="btn-primario" style="display: inline-block; margin-top: 1rem;">
                        <i class="fas fa-sign-in-alt"></i> Iniciar Sesi\u00f3n
                    </a>
                </div>
            `;
        }
    } catch (error) {
        console.error('Error:', error);
        container.innerHTML = '<p class="mensaje error">Error al cargar los pedidos</p>';
    }
}

function renderizarPedidos(pedidos) {
    const container = document.getElementById('pedidos-contenido');
    
    const html = pedidos.map(pedido => `
        <div class="pedido-item">
            <div class="pedido-header">
                <span class="pedido-numero">${pedido.numero_pedido}</span>
                <span class="pedido-estado ${pedido.estado}">${pedido.estado_texto}</span>
            </div>
            <div class="pedido-info">
                <span class="pedido-fecha"><i class="fas fa-calendar"></i> ${pedido.fecha_formateada}</span>
                <span class="pedido-items-count"><i class="fas fa-box"></i> ${pedido.total_items} productos</span>
                <span class="pedido-total">${pedido.total_formateado}</span>
            </div>
            <button class="btn-ver-pedido" onclick="verDetallePedido(${pedido.id})">
                <i class="fas fa-eye"></i> Ver Detalles
            </button>
        </div>
    `).join('');
    
    container.innerHTML = `<div class="pedidos-lista">${html}</div>`;
}

function mostrarSinPedidos() {
    const container = document.getElementById('pedidos-contenido');
    container.innerHTML = `
        <div style="text-align: center; padding: 3rem; background: #fff; border-radius: 8px; box-shadow: var(--sombra);">
            <i class="fas fa-shopping-bag" style="font-size: 4rem; color: #ccc; margin-bottom: 1rem;"></i>
            <h2>No tienes pedidos a\u00fan</h2>
            <p>\u00a1Explora nuestra tienda y realiza tu primera compra!</p>
            <a href="index.html" class="btn-primario" style="display: inline-block; margin-top: 1rem;">
                <i class="fas fa-store"></i> Ir a la Tienda
            </a>
        </div>
    `;
}

async function verDetallePedido(pedidoId) {
    const modal = document.getElementById('modal-detalle');
    const modalBody = document.getElementById('modal-body');
    
    modal.classList.add('activo');
    modalBody.innerHTML = '<div class="loading"><div class="spinner"></div></div>';
    
    try {
        const response = await fetch(`${API_BASE}pedidos.php?action=detalle&id=${pedidoId}`);
        const data = await response.json();
        
        if (data.success) {
            renderizarDetallePedido(data.pedido);
        } else {
            modalBody.innerHTML = '<p class="mensaje error">Error al cargar el detalle</p>';
        }
    } catch (error) {
        console.error('Error:', error);
        modalBody.innerHTML = '<p class="mensaje error">Error de conexi\u00f3n</p>';
    }
}

function renderizarDetallePedido(pedido) {
    const modalBody = document.getElementById('modal-body');
    
    const itemsHtml = pedido.detalles.map(item => `
        <div class="checkout-item">
            <img src="imagenes/productos/${item.imagen || '../01-principal/imagenes/fondo-producto.png'}" 
                 alt="${item.nombre_producto}" class="checkout-item-imagen"
                 onerror="this.src='../01-principal/imagenes/fondo-producto.png'">
            <div class="checkout-item-info">
                <p class="checkout-item-nombre">${item.nombre_producto}</p>
                <p class="checkout-item-cantidad">${item.cantidad} x ${item.precio_unitario_formateado}</p>
            </div>
            <span class="checkout-item-precio">${item.subtotal_formateado}</span>
        </div>
    `).join('');
    
    modalBody.innerHTML = `
        <div style="margin-bottom: 1rem;">
            <p><strong>N\u00famero:</strong> ${pedido.numero_pedido}</p>
            <p><strong>Fecha:</strong> ${pedido.fecha_formateada}</p>
            <p><strong>Estado:</strong> <span class="pedido-estado ${pedido.estado}">${pedido.estado_texto}</span></p>
        </div>
        
        <h3 style="margin: 1rem 0;">Productos</h3>
        <div style="max-height: 250px; overflow-y: auto;">
            ${itemsHtml}
        </div>
        
        <h3 style="margin: 1rem 0;">Direcci\u00f3n de Env\u00edo</h3>
        <p>${pedido.nombre_envio} ${pedido.apellido_envio}</p>
        <p>${pedido.direccion_envio}</p>
        <p>${pedido.ciudad_envio}, ${pedido.departamento_envio}</p>
        <p>Tel: ${pedido.telefono_envio}</p>
        
        <div style="margin-top: 1rem; padding-top: 1rem; border-top: 2px solid var(--color-borde);">
            <div class="resumen-linea"><span>Subtotal</span><span>${pedido.subtotal_formateado}</span></div>
            <div class="resumen-linea"><span>Env\u00edo</span><span>${pedido.costo_envio_formateado}</span></div>
            <div class="resumen-linea total"><span>Total</span><span>${pedido.total_formateado}</span></div>
        </div>
    `;
}

function cerrarModal() {
    document.getElementById('modal-detalle').classList.remove('activo');
}

// Cerrar modal al hacer clic fuera
document.getElementById('modal-detalle')?.addEventListener('click', (e) => {
    if (e.target.classList.contains('modal')) {
        cerrarModal();
    }
});

async function actualizarContadorCarrito() {
    const badge = document.getElementById('carrito-cantidad');
    if (!badge) return;
    
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