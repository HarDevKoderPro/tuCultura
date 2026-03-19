/**
 * JavaScript para Checkout
 * Tu Cultura es Progreso - Tienda Virtual
 */

const API_BASE = "php/";
let carritoData = null;

document.addEventListener("DOMContentLoaded", () => {
  verificarYCargarCheckout();
});

async function verificarYCargarCheckout() {
  const container = document.getElementById("checkout-contenido");

  try {
    const responseVerif = await fetch(
      `${API_BASE}pedidos.php?action=verificar_checkout`,
    );
    const dataVerif = await responseVerif.json();

    if (!dataVerif.success) {
      if (dataVerif.requiere_login) {
        // ✅ Usuario no logueado: mostrar mensaje de login
        container.innerHTML = `
                    <div style="grid-column: 1/-1; background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); padding: 4rem 2rem; text-align: center; max-width: 900px; margin: 1rem auto; border: 1px solid #eee;">
                        <div style="margin-bottom: 1.5rem;">
                            <i class="fas fa-shopping-cart" style="font-size: 5rem; color: #d1d5db;"></i>
                        </div>
                        
                        <h2 style="color: #023859; font-size: 1.8rem; font-weight: 700; margin-bottom: 1rem;">Inicia Sesión para Continuar</h2>
                        
                        <p style="color: #666; font-size: 1.1rem; margin-bottom: 2rem;">Necesitas una cuenta para realizar tu compra.</p>
                        
                        <a href="../02-iniciarSesion/iniciarSesion.html?origen=tienda" 
                           onclick="sessionStorage.setItem('returnUrl', 'checkout.html')"
                           style="display: inline-flex; align-items: center; gap: 10px; background: #1e3a5a; color: white; padding: 0.8rem 2rem; border-radius: 6px; text-decoration: none; font-weight: 600; font-size: 1rem; transition: background 0.3s ease;">
                            <i class="fas fa-sign-in-alt"></i> Iniciar Sesión
                        </a>
                    </div>
                `;
        return;
      }
      // ✅ Admin intentando comprar: mostrar mensaje de restricción
      if (dataVerif.es_admin) {
        container.innerHTML = `
                    <div style="grid-column: 1/-1; background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); padding: 4rem 2rem; text-align: center; max-width: 900px; margin: 1rem auto; border: 1px solid #eee;">
                        <div style="margin-bottom: 1.5rem;">
                            <i class="fas fa-user-shield" style="font-size: 5rem; color: #f59e0b;"></i>
                        </div>
                        
                        <h2 style="color: #023859; font-size: 1.8rem; font-weight: 700; margin-bottom: 1rem;">Acceso Restringido</h2>
                        
                        <p style="color: #666; font-size: 1.1rem; margin-bottom: 2rem;">Los administradores no pueden realizar compras.<br>Cierre sesión e ingrese como cliente para comprar.</p>
                        
                        <a href="index.html"
                           style="display: inline-flex; align-items: center; gap: 10px; background: #1e3a5a; color: white; padding: 0.8rem 2rem; border-radius: 6px; text-decoration: none; font-weight: 600; font-size: 1rem; transition: background 0.3s ease;">
                            <i class="fas fa-store"></i> Volver a la Tienda
                        </a>
                    </div>
                `;
        return;
      }
      if (dataVerif.carrito_vacio) {
        window.location.href = "carrito.html";
        return;
      }
    }

    const responseCarrito = await fetch(`${API_BASE}carrito.php?action=ver`);
    const dataCarrito = await responseCarrito.json();

    if (dataCarrito.success && dataCarrito.carrito.items.length > 0) {
      carritoData = dataCarrito.carrito;
      renderizarCheckout(dataVerif.usuario, carritoData);
    } else {
      window.location.href = "carrito.html";
    }
  } catch (error) {
    console.error("Error:", error);
    container.innerHTML =
      '<p class="mensaje error">Error al cargar el checkout</p>';
  }
}

function renderizarCheckout(usuario, carrito) {
  const container = document.getElementById("checkout-contenido");

  const itemsHtml = carrito.items
    .map(
      (item) => `
        <div class="checkout-item">
            <img src="imagenes/productos/${item.imagen || "../01-principal/imagenes/fondo-producto.png"}" 
                 alt="${item.nombre}" class="checkout-item-imagen"
                 onerror="this.src='../01-principal/imagenes/fondo-producto.png'">
            <div class="checkout-item-info">
                <p class="checkout-item-nombre">${item.nombre}</p>
                <p class="checkout-item-cantidad">Cantidad: ${item.cantidad}</p>
            </div>
            <span class="checkout-item-precio">${item.subtotal_item_formateado}</span>
        </div>
    `,
    )
    .join("");

  container.innerHTML = `
        <div class="checkout-formulario">
            <h2><i class="fas fa-truck"></i> Datos de Envío</h2>
            
            <form id="form-checkout" onsubmit="procesarPedido(event)">
                <div class="form-fila">
                    <div class="form-grupo">
                        <label for="nombre">Nombre *</label>
                        <input type="text" id="nombre" name="nombre" value="${usuario.nombres || ""}" required>
                    </div>
                    <div class="form-grupo">
                        <label for="apellido">Apellido *</label>
                        <input type="text" id="apellido" name="apellido" value="${usuario.apellidos || ""}" required>
                    </div>
                </div>
                
                <div class="form-fila">
                    <div class="form-grupo">
                        <label for="email">Email *</label>
                        <input type="email" id="email" name="email" value="${usuario.email || ""}" required>
                    </div>
                    <div class="form-grupo">
                        <label for="telefono">Teléfono *</label>
                        <input type="tel" id="telefono" name="telefono" value="${usuario.telefono || ""}" required>
                    </div>
                </div>
                
                <div class="form-grupo">
                    <label for="direccion">Dirección de Envío *</label>
                    <input type="text" id="direccion" name="direccion" placeholder="Calle, número, apartamento..." required>
                </div>
                
                <div class="form-fila">
                    <div class="form-grupo">
                        <label for="ciudad">Ciudad *</label>
                        <input type="text" id="ciudad" name="ciudad" required>
                    </div>
                    <div class="form-grupo">
                        <label for="departamento">Departamento *</label>
                        <input type="text" id="departamento" name="departamento" required>
                    </div>
                </div>
                
                <div class="form-grupo">
                    <label for="codigo_postal">Código Postal</label>
                    <input type="text" id="codigo_postal" name="codigo_postal">
                </div>
                
                <div class="form-grupo">
                    <label for="notas">Notas del pedido (opcional)</label>
                    <textarea id="notas" name="notas" rows="3" placeholder="Instrucciones especiales para la entrega..."></textarea>
                </div>
                
                <h2 style="margin-top: 2rem;"><i class="fas fa-credit-card"></i> Método de Pago</h2>
                
                <div class="form-grupo">
                    <label style="display: flex; align-items: center; gap: 1rem; padding: 1rem; background: var(--color-fondo); border-radius: var(--radio-borde); cursor: pointer;">
                        <input type="radio" name="metodo_pago" value="contraentrega" checked>
                        <i class="fas fa-money-bill-wave" style="font-size: 1.5rem; color: var(--color-exito);"></i>
                        <div>
                            <strong>Pago Contra Entrega</strong>
                            <p style="font-size: 0.85rem; color: var(--color-texto-claro);">Paga cuando recibas tu pedido</p>
                        </div>
                    </label>
                </div>
                
                <div class="form-grupo">
                    <label style="display: flex; align-items: center; gap: 1rem; padding: 1rem; background: var(--color-fondo); border-radius: var(--radio-borde); cursor: pointer;">
                        <input type="radio" name="metodo_pago" value="transferencia">
                        <i class="fas fa-university" style="font-size: 1.5rem; color: var(--color-primario);"></i>
                        <div>
                            <strong>Transferencia Bancaria</strong>
                            <p style="font-size: 0.85rem; color: var(--color-texto-claro);">Te enviaremos los datos para la transferencia</p>
                        </div>
                    </label>
                </div>
                
                <button type="submit" class="btn-checkout" id="btn-confirmar">
                    <i class="fas fa-check"></i> Confirmar Pedido
                </button>
            </form>
        </div>
        
        <div class="carrito-resumen">
            <h2>Resumen del Pedido</h2>
            
            <div class="checkout-resumen-items">
                ${itemsHtml}
            </div>
            
            <div class="resumen-linea">
                <span>Subtotal</span>
                <span>${carrito.subtotal_formateado}</span>
            </div>
            
            <div class="resumen-linea">
                <span>Envío</span>
                <span>${carrito.costo_envio === 0 ? "GRATIS" : carrito.costo_envio_formateado}</span>
            </div>
            
            <div class="resumen-linea total">
                <span>Total a Pagar</span>
                <span>${carrito.total_formateado}</span>
            </div>
            
            <a href="carrito.html" class="btn-seguir-comprando">
                <i class="fas fa-arrow-left"></i> Volver al Carrito
            </a>
        </div>
    `;
}

async function procesarPedido(event) {
  event.preventDefault();

  const form = document.getElementById("form-checkout");
  const btn = document.getElementById("btn-confirmar");
  const formData = new FormData(form);

  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Procesando...';

  try {
    const response = await fetch(`${API_BASE}pedidos.php?action=crear`, {
      method: "POST",
      body: formData,
    });

    const data = await response.json();

    if (data.success) {
      window.location.href = `confirmacion.html?pedido=${data.pedido.numero}`;
    } else {
      Swal.fire({
        icon: "error",
        title: "Error",
        text: data.error || "No se pudo procesar el pedido",
        customClass: {
          popup: "swal-tcp-popup",
          confirmButton: "btn-swal-confirm",
        },
        buttonsStyling: false,
      });
      btn.disabled = false;
      btn.innerHTML = '<i class="fas fa-check"></i> Confirmar Pedido';
    }
  } catch (error) {
    console.error("Error:", error);
    Swal.fire({
      icon: "error",
      title: "Error de conexión",
      text: "No se pudo procesar el pedido. Intente nuevamente.",
      customClass: {
        popup: "swal-tcp-popup",
        confirmButton: "btn-swal-confirm",
      },
      buttonsStyling: false,
    });
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-check"></i> Confirmar Pedido';
  }
}
