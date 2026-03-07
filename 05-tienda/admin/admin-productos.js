/**
 * JavaScript para Gestión de Productos - Admin
 */

const API_BASE = '../php/admin.php';
let productos = [];
let categorias = [];

document.addEventListener('DOMContentLoaded', () => {
    cargarCategorias();
    cargarProductos();
});

async function cargarCategorias() {
    try {
        const response = await fetch(`${API_BASE}?action=categorias_listar`);
        const data = await response.json();
        
        if (data.success) {
            categorias = data.categorias;
            const select = document.getElementById('producto-categoria');
            select.innerHTML = '<option value="">Sin categoría</option>' + 
                categorias.map(c => `<option value="${c.id}">${c.nombre}</option>`).join('');
        }
    } catch (error) {
        console.error('Error:', error);
    }
}

async function cargarProductos() {
    const tbody = document.querySelector('#tabla-productos tbody');
    
    try {
        const response = await fetch(`${API_BASE}?action=productos_listar`);
        const data = await response.json();
        
        if (data.success) {
            productos = data.productos;
            renderizarProductos();
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Acceso Denegado',
                text: data.error || 'No tienes permisos',
                confirmButtonText: 'Volver'
            }).then(() => {
                window.location.href = '../index.html';
            });
        }
    } catch (error) {
        console.error('Error:', error);
        tbody.innerHTML = '<tr><td colspan="8">Error al cargar productos</td></tr>';
    }
}

function renderizarProductos() {
    const tbody = document.querySelector('#tabla-productos tbody');
    
    if (productos.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8">No hay productos registrados</td></tr>';
        return;
    }
    
    tbody.innerHTML = productos.map(p => `
        <tr>
            <td>
                <img src="../imagenes/productos/${p.imagen || '../01-principal/imagenes/fondo-producto.png'}" 
                     alt="${p.nombre}"
                     onerror="this.src='../../01-principal/imagenes/fondo-producto.png'">
            </td>
            <td><strong>${p.nombre}</strong><br><small>${p.sku || ''}</small></td>
            <td>${p.categoria_nombre || '-'}</td>
            <td>
                ${p.precio_oferta ? `
                    <span style="text-decoration: line-through; color: #999;">${p.precio_formateado}</span><br>
                    <strong style="color: var(--color-exito);">${p.precio_oferta_formateado}</strong>
                ` : `<strong>${p.precio_formateado}</strong>`}
            </td>
            <td>
                <span style="color: ${p.stock < 10 ? 'var(--color-error)' : 'var(--color-exito)'}; font-weight: bold;">
                    ${p.stock}
                </span>
            </td>
            <td>${p.destacado ? '<i class="fas fa-star" style="color: gold;"></i>' : '-'}</td>
            <td>${p.activo ? '<span style="color: green;">\u2713</span>' : '<span style="color: red;">\u2717</span>'}</td>
            <td>
                <button class="btn-accion editar" onclick="editarProducto(${p.id})" title="Editar">
                    <i class="fas fa-edit"></i>
                </button>
                <button class="btn-accion eliminar" onclick="eliminarProducto(${p.id})" title="Eliminar">
                    <i class="fas fa-trash"></i>
                </button>
            </td>
        </tr>
    `).join('');
}

function abrirModalProducto() {
    document.getElementById('modal-titulo').textContent = 'Nuevo Producto';
    document.getElementById('form-producto').reset();
    document.getElementById('producto-id').value = '';
    document.getElementById('producto-activo').checked = true;
    document.getElementById('modal-producto').classList.add('activo');
}

async function editarProducto(id) {
    try {
        const response = await fetch(`${API_BASE}?action=producto_detalle&id=${id}`);
        const data = await response.json();
        
        if (data.success) {
            const p = data.producto;
            
            document.getElementById('modal-titulo').textContent = 'Editar Producto';
            document.getElementById('producto-id').value = p.id;
            document.getElementById('producto-nombre').value = p.nombre;
            document.getElementById('producto-descripcion').value = p.descripcion || '';
            document.getElementById('producto-precio').value = p.precio;
            document.getElementById('producto-precio-oferta').value = p.precio_oferta || '';
            document.getElementById('producto-categoria').value = p.categoria_id || '';
            document.getElementById('producto-stock').value = p.stock;
            document.getElementById('producto-sku').value = p.sku || '';
            document.getElementById('producto-marca').value = p.marca || '';
            document.getElementById('producto-imagen').value = p.imagen || '';
            document.getElementById('producto-destacado').checked = p.destacado == 1;
            document.getElementById('producto-activo').checked = p.activo == 1;
            
            document.getElementById('modal-producto').classList.add('activo');
        }
    } catch (error) {
        console.error('Error:', error);
    }
}

async function guardarProducto(event) {
    event.preventDefault();
    
    const form = document.getElementById('form-producto');
    const formData = new FormData(form);
    const id = formData.get('id');
    
    // Manejar checkboxes
    formData.set('destacado', document.getElementById('producto-destacado').checked ? 1 : 0);
    formData.set('activo', document.getElementById('producto-activo').checked ? 1 : 0);
    
    const action = id ? 'producto_actualizar' : 'producto_crear';
    
    try {
        const response = await fetch(`${API_BASE}?action=${action}`, {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: '\u00c9xito',
                text: data.mensaje,
                timer: 2000,
                showConfirmButton: false
            });
            cerrarModal();
            cargarProductos();
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: data.error
            });
        }
    } catch (error) {
        console.error('Error:', error);
        Swal.fire('Error', 'Error de conexi\u00f3n', 'error');
    }
}

async function eliminarProducto(id) {
    const result = await Swal.fire({
        icon: 'warning',
        title: '\u00bfEliminar producto?',
        text: 'El producto ser\u00e1 desactivado',
        showCancelButton: true,
        confirmButtonText: 'S\u00ed, eliminar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#dc3545'
    });
    
    if (!result.isConfirmed) return;
    
    const formData = new FormData();
    formData.append('id', id);
    
    try {
        const response = await fetch(`${API_BASE}?action=producto_eliminar`, {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: 'Eliminado',
                text: data.mensaje,
                timer: 1500,
                showConfirmButton: false
            });
            cargarProductos();
        } else {
            Swal.fire('Error', data.error, 'error');
        }
    } catch (error) {
        console.error('Error:', error);
    }
}

function cerrarModal() {
    document.getElementById('modal-producto').classList.remove('activo');
}

// Cerrar modal al hacer clic fuera
document.getElementById('modal-producto').addEventListener('click', (e) => {
    if (e.target.classList.contains('modal')) {
        cerrarModal();
    }
});