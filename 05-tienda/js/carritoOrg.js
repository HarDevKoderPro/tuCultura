/**
 * JavaScript para Carrito de Compras
 * Tu Cultura es Progreso - Tienda Virtual
 */

const API_BASE = "php/";

document.addEventListener("DOMContentLoaded", () => {
  cargarCarrito();
});

async function cargarCarrito() {
  const container = document.getElementById("carrito-contenido");

  try {
    const response = await fetch(`${API_BASE}carrito.php?action=ver`);
    const data = await response.json();

    if (data.success) {
      renderizarCarrito(data.carrito);
    } else {
      mostrarCarritoVacio();
    }
  } catch (error) {
    console.error("Error:", error);
    container.innerHTML =
      '<p class="mensaje error">Error al cargar el carrito</p>';
  }
}

function renderizarCarrito(carrito) {
  const container = document.getElementById("carrito-contenido");

  if (carrito.items.length === 0) {
    mostrarCarritoVacio();
    return;
  }

  const itemsHtml = carrito.items
    .map(
      (item) => `
        <div class="carrito-item" data-id="${item.id}">
            <img src="imagenes/productos/${item.imagen || "../01-principal/imagenes/fondo-producto.png"}" 
                 alt="${item.nombre}" 
                 class="carrito-item-imagen"
                 onerror="this.src='../01-principal/imagenes/fondo-producto.png'">
            
            <div class="carrito-item-info">
                <h3><a href="producto.html?id=${item.producto_id}">${item.nombre}</a></h3>
                <p class="carrito-item-precio">${item.precio_final_formateado}</p>
                ${!item.disponible ? '<p class="mensaje error" style="padding: 0.25rem 0.5rem; font-size: 0.8rem;">Stock insuficiente</p>' : ""}
            </div>
            
            <div class="cantidad-control">
                <button onclick="actualizarCantidad(${item.id}, ${item.cantidad - 1})">-</button>
                <input type="number" value="${item.cantidad}" min="1" max="${item.stock}" 
                    onchange="actualizarCantidad(${item.id}, this.value)" readonly>
                <button onclick="actualizarCantidad(${item.id}, ${item.cantidad + 1})">+</button>
            </div>
            
            <div class="carrito-item-acciones">
                <span class="carrito-item-subtotal">${item.subtotal_item_formateado}</span>
                <button class="btn-eliminar-item" onclick="eliminarItem(${item.id})">
                    <i class="fas fa-trash"></i> Eliminar
                </button>
            </div>
        </div>
    `,
    )
    .join("");

  const envioMensaje =
    carrito.costo_envio === 0
      ? '<div class="envio-gratis-mensaje"><i class="fas fa-truck"></i> ¡Envío GRATIS!</div>'
      : `<div class="envio-gratis-mensaje" style="background: #fff3cd; color: #856404;">
             <i class="fas fa-truck"></i> Te faltan ${carrito.falta_envio_gratis_formateado} para envío gratis
           </div>`;

  container.innerHTML = `
        <div class="carrito-items">
            ${itemsHtml}
        </div>
        
        <div class="carrito-resumen">
            <h2>Resumen del Pedido</h2>
            
            <div class="resumen-linea">
                <span>Subtotal (${carrito.cantidad_items} productos)</span>
                <span>${carrito.subtotal_formateado}</span>
            </div>
            
            <div class="resumen-linea">
                <span>Envío</span>
                <span>${carrito.costo_envio === 0 ? "GRATIS" : carrito.costo_envio_formateado}</span>
            </div>
            
            ${envioMensaje}
            
            <div class="resumen-linea total">
                <span>Total</span>
                <span>${carrito.total_formateado}</span>
            </div>
            
            <button class="btn-checkout" onclick="irAlCheckout()">
                <i class="fas fa-lock"></i> Proceder al Pago
            </button>
            
            <a href="index.html" class="btn-seguir-comprando">
                <i class="fas fa-arrow-left"></i> Seguir Comprando
            </a>
            
            <button class="btn-secundario" style="width: 100%; margin-top: 1rem;" onclick="vaciarCarrito()">
                <i class="fas fa-trash"></i> Vaciar Carrito
            </button>
        </div>
    `;
}

// ✅ CORRECCIÓN: Centrado total (Horizontal y Vertical) para Carrito Vacío
function mostrarCarritoVacio() {
  const container = document.getElementById("carrito-contenido");

  // Aplicamos flex al contenedor padre para centrar la tarjeta
  container.style.display = "flex";
  container.style.justifyContent = "center";
  container.style.alignItems = "center";
  container.style.minHeight = "60vh"; // Le damos altura para que se note el centrado vertical
  container.style.width = "100%";

  container.innerHTML = `
        <div style="background: white; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); padding: 4rem 2rem; text-align: center; width: 100%; max-width: 500px; border: 1px solid #f0f0f0;">
            <div style="margin-bottom: 1.5rem;">
                <i class="fas fa-shopping-cart" style="font-size: 5rem; color: #d1d5db;"></i>
            </div>
            
            <h2 style="color: #023859; font-size: 1.8rem; font-weight: 700; margin-bottom: 1rem;">Tu carrito está vacío</h2>
            
            <p style="color: #666; font-size: 1.1rem; margin-bottom: 2rem; max-width: 300px; margin-left: auto; margin-right: auto;">¡Explora nuestra tienda y encuentra productos increíbles!</p>
            
            <a href="index.html" 
               style="display: inline-flex; align-items: center; gap: 10px; background: #1e3a5a; color: white; padding: 0.8rem 2.5rem; border-radius: 6px; text-decoration: none; font-weight: 600; font-size: 1rem; transition: background 0.3s ease; box-shadow: 0 4px 6px rgba(30, 58, 90, 0.2);">
                <i class="fas fa-store"></i> Ir a la Tienda
            </a>
        </div>
    `;
}

async function actualizarCantidad(itemId, nuevaCantidad) {
  if (nuevaCantidad < 1) {
    eliminarItem(itemId);
    return;
  }

  try {
    const formData = new FormData();
    formData.append("item_id", itemId);
    formData.append("cantidad", nuevaCantidad);

    const response = await fetch(`${API_BASE}carrito.php?action=actualizar`, {
      method: "POST",
      body: formData,
    });

    const data = await response.json();

    if (data.success) {
      cargarCarrito();
    } else {
      Swal.fire({
        title: "Error",
        text: data.error || "No se pudo actualizar",
        customClass: {
          popup: "swal-tcp-popup",
          title: "swal-tcp-title",
          confirmButton: "btn-swal-confirm",
        },
        buttonsStyling: false,
      });
    }
  } catch (error) {
    console.error("Error:", error);
  }
}

async function eliminarItem(itemId) {
  const result = await Swal.fire({
    title: "¿Eliminar producto?",
    text: "¿Estás seguro de eliminar este producto del carrito?",
    showCancelButton: true,
    confirmButtonText: "Sí, eliminar",
    cancelButtonText: "Cancelar",
    position: "center",
    customClass: {
      popup: "swal-tcp-popup",
      title: "swal-tcp-title",
      confirmButton: "btn-swal-danger",
      cancelButton: "btn-swal-cancel",
    },
    buttonsStyling: false,
  });

  if (!result.isConfirmed) return;

  try {
    const formData = new FormData();
    formData.append("item_id", itemId);

    const response = await fetch(`${API_BASE}carrito.php?action=eliminar`, {
      method: "POST",
      body: formData,
    });

    const data = await response.json();

    if (data.success) {
      cargarCarrito();
    }
  } catch (error) {
    console.error("Error:", error);
  }
}

async function vaciarCarrito() {
  const result = await Swal.fire({
    title: "¿Vaciar carrito?",
    text: "Se eliminarán todos los productos del carrito",
    showCancelButton: true,
    confirmButtonText: "Sí, vaciar",
    cancelButtonText: "Cancelar",
    position: "center",
    customClass: {
      popup: "swal-tcp-popup",
      title: "swal-tcp-title",
      confirmButton: "btn-swal-danger",
      cancelButton: "btn-swal-cancel",
    },
    buttonsStyling: false,
  });

  if (!result.isConfirmed) return;

  try {
    const response = await fetch(`${API_BASE}carrito.php?action=vaciar`, {
      method: "POST",
    });

    const data = await response.json();

    if (data.success) {
      mostrarCarritoVacio();
    }
  } catch (error) {
    console.error("Error:", error);
  }
}

function irAlCheckout() {
  const container = document.getElementById("carrito-contenido");
  const hayItems = container.querySelector(".carrito-item");

  if (!hayItems) {
    Swal.fire({
      title: "Carrito vacío",
      text: "Agrega productos al carrito para continuar",
      position: "center",
      customClass: {
        popup: "swal-tcp-popup",
        title: "swal-tcp-title",
        confirmButton: "btn-swal-confirm",
      },
      buttonsStyling: false,
    });
    return;
  }

  window.location.href = "checkout.html";
}
