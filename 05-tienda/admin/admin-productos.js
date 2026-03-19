/**
 * JavaScript para Gestión de Productos - Admin
 * Con selector visual de imágenes, validaciones y subida automática
 */

const API_BASE = '../php/admin.php';
let productos = [];
let categorias = [];
let imagenSeleccionada = null; // File object de la imagen nueva
let imagenActual = '';         // Nombre de imagen existente (al editar)

// ==================== CONSTANTES DE VALIDACIÓN ====================
const FORMATOS_PERMITIDOS = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'];
const EXTENSIONES_PERMITIDAS = ['.jpg', '.jpeg', '.png', '.webp', '.gif', '.avif'];
const MAX_TAMANO = 5 * 1024 * 1024; // 5 MB

// ==================== INICIALIZACIÓN ====================
document.addEventListener('DOMContentLoaded', () => {
    cargarCategorias();
    cargarProductos();
    inicializarSelectorImagen();
});

// ==================== SELECTOR DE IMAGEN ====================
function inicializarSelectorImagen() {
    const uploadArea = document.getElementById('imagen-upload-area');
    const fileInput = document.getElementById('producto-imagen-file');

    // Click en el área abre el selector de archivos
    uploadArea.addEventListener('click', (e) => {
        // No abrir si se hizo clic en el botón de quitar
        if (e.target.closest('.btn-quitar-imagen')) return;
        fileInput.click();
    });

    // Cambio de archivo
    fileInput.addEventListener('change', (e) => {
        if (e.target.files && e.target.files[0]) {
            procesarArchivoImagen(e.target.files[0]);
        }
    });

    // Drag & Drop
    uploadArea.addEventListener('dragover', (e) => {
        e.preventDefault();
        uploadArea.classList.add('dragover');
    });
    uploadArea.addEventListener('dragleave', () => {
        uploadArea.classList.remove('dragover');
    });
    uploadArea.addEventListener('drop', (e) => {
        e.preventDefault();
        uploadArea.classList.remove('dragover');
        if (e.dataTransfer.files && e.dataTransfer.files[0]) {
            procesarArchivoImagen(e.dataTransfer.files[0]);
        }
    });
}

function procesarArchivoImagen(file) {
    const errorEl = document.getElementById('imagen-error');
    const nombreEl = document.getElementById('imagen-nombre-archivo');
    errorEl.style.display = 'none';
    errorEl.textContent = '';
    nombreEl.textContent = '';

    // Validar tipo MIME
    if (!FORMATOS_PERMITIDOS.includes(file.type)) {
        mostrarErrorImagen('Formato no permitido. Usa: JPG, PNG, WebP, GIF o AVIF.');
        return;
    }

    // Validar extensión
    const ext = '.' + file.name.split('.').pop().toLowerCase();
    if (!EXTENSIONES_PERMITIDAS.includes(ext)) {
        mostrarErrorImagen('Extensión de archivo no permitida.');
        return;
    }

    // Validar tamaño
    if (file.size > MAX_TAMANO) {
        const tamanoMB = (file.size / (1024 * 1024)).toFixed(1);
        mostrarErrorImagen(`El archivo pesa ${tamanoMB} MB. El máximo permitido es 5 MB.`);
        return;
    }

    // Validar que sea realmente una imagen
    const reader = new FileReader();
    reader.onload = function(e) {
        const img = new Image();
        img.onload = function() {
            // Es una imagen válida
            imagenSeleccionada = file;
            mostrarVistaPrevia(e.target.result, file.name);
        };
        img.onerror = function() {
            mostrarErrorImagen('El archivo no es una imagen válida.');
        };
        img.src = e.target.result;
    };
    reader.readAsDataURL(file);
}

function mostrarVistaPrevia(src, nombre) {
    const preview = document.getElementById('imagen-preview');
    const previewContainer = document.getElementById('imagen-preview-container');
    const placeholder = document.getElementById('imagen-upload-placeholder');
    const nombreEl = document.getElementById('imagen-nombre-archivo');

    preview.src = src;
    previewContainer.style.display = 'inline-block';
    placeholder.style.display = 'none';
    nombreEl.textContent = nombre ? `📎 ${nombre}` : '';
}

function mostrarErrorImagen(mensaje) {
    const errorEl = document.getElementById('imagen-error');
    errorEl.textContent = mensaje;
    errorEl.style.display = 'block';
    // Limpiar el input file
    document.getElementById('producto-imagen-file').value = '';
}

function quitarImagen() {
    imagenSeleccionada = null;
    imagenActual = '';

    const preview = document.getElementById('imagen-preview');
    const previewContainer = document.getElementById('imagen-preview-container');
    const placeholder = document.getElementById('imagen-upload-placeholder');
    const nombreEl = document.getElementById('imagen-nombre-archivo');
    const errorEl = document.getElementById('imagen-error');
    const fileInput = document.getElementById('producto-imagen-file');

    preview.src = '';
    previewContainer.style.display = 'none';
    placeholder.style.display = 'block';
    nombreEl.textContent = '';
    errorEl.style.display = 'none';
    fileInput.value = '';
}

function resetearSelectorImagen() {
    quitarImagen();
}

// ==================== CATEGORÍAS ====================
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

// ==================== PRODUCTOS ====================
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
                <img src="../imagenes/productos/${p.imagen || 'sin-imagen.png'}" 
                     alt="${p.nombre}"
                     style="width:50px; height:50px; object-fit:cover; border-radius:6px;"
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
            <td>${p.activo ? '<span style="color: green;">✓</span>' : '<span style="color: red;">✗</span>'}</td>
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

// ==================== MODAL ====================
function abrirModalProducto() {
    document.getElementById('modal-titulo').textContent = 'Nuevo Producto';
    document.getElementById('form-producto').reset();
    document.getElementById('producto-id').value = '';
    document.getElementById('producto-activo').checked = true;
    resetearSelectorImagen();
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
            document.getElementById('producto-destacado').checked = p.destacado == 1;
            document.getElementById('producto-activo').checked = p.activo == 1;
            
            // Mostrar imagen actual si existe
            resetearSelectorImagen();
            if (p.imagen) {
                imagenActual = p.imagen;
                mostrarVistaPrevia(`../imagenes/productos/${p.imagen}`, p.imagen);
            }
            
            document.getElementById('modal-producto').classList.add('activo');
        }
    } catch (error) {
        console.error('Error:', error);
    }
}

// ==================== GUARDAR PRODUCTO ====================
async function guardarProducto(event) {
    event.preventDefault();
    
    const form = document.getElementById('form-producto');
    const formData = new FormData(form);
    const id = formData.get('id');
    
    // Manejar checkboxes
    formData.set('destacado', document.getElementById('producto-destacado').checked ? 1 : 0);
    formData.set('activo', document.getElementById('producto-activo').checked ? 1 : 0);
    
    // Eliminar el campo file del FormData nativo (lo agregaremos manualmente)
    formData.delete('imagen_file');
    
    // Agregar imagen según el caso
    if (imagenSeleccionada) {
        // Nueva imagen seleccionada → subir archivo
        formData.append('imagen_file', imagenSeleccionada);
    } else if (imagenActual) {
        // Mantener imagen actual (editando sin cambiar imagen)
        formData.append('imagen_actual', imagenActual);
    }
    // Si no hay ni imagenSeleccionada ni imagenActual, no se envía imagen
    
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
                title: 'Éxito',
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
        Swal.fire('Error', 'Error de conexión', 'error');
    }
}

// ==================== ELIMINAR PRODUCTO ====================
async function eliminarProducto(id) {
    const result = await Swal.fire({
        icon: 'warning',
        title: '¿Eliminar producto?',
        text: 'El producto será desactivado',
        showCancelButton: true,
        confirmButtonText: 'Sí, eliminar',
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

// ==================== UTILIDADES ====================
function cerrarModal() {
    document.getElementById('modal-producto').classList.remove('activo');
}

// Cerrar modal al hacer clic fuera
document.getElementById('modal-producto').addEventListener('click', (e) => {
    if (e.target.classList.contains('modal')) {
        cerrarModal();
    }
});
