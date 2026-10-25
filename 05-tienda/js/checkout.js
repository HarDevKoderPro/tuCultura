/**
 * JavaScript para Checkout
 * Tu Cultura es Progreso - Tienda Virtual
 */

const API_BASE = "php/";
let carritoData = null;
let bonoData = null; // Bono por puntos disponible (pedidos.php?action=verificar_checkout)

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
        // ✅ Guardar returnUrl ANTES de mostrar el enlace (más robusto que onclick)
        sessionStorage.setItem('returnUrl', 'checkout.html');
        
        // ✅ Usuario no logueado: mostrar mensaje de login
        container.innerHTML = `
                    <div style="grid-column: 1/-1; background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); padding: 4rem 2rem; text-align: center; max-width: 900px; margin: 1rem auto; border: 1px solid #eee;">
                        <div style="margin-bottom: 1.5rem;">
                            <i class="fas fa-shopping-cart" style="font-size: 5rem; color: #d1d5db;"></i>
                        </div>
                        
                        <h2 style="color: #023859; font-size: 1.8rem; font-weight: 700; margin-bottom: 1rem;">Inicia Sesión para Continuar</h2>
                        
                        <p style="color: #666; font-size: 1.1rem; margin-bottom: 2rem;">Necesitas una cuenta para realizar tu compra.</p>
                        
                        <a href="../02-iniciarSesion/iniciarSesion.html?origen=tienda&returnUrl=checkout.html" 
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
                        
                        <p style="color: #666; font-size: 1.1rem; margin-bottom: 2rem;">Los administradores no pueden realizar compras.<br>Cierre sesión e ingrese como asociado para comprar.</p>
                        
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

    // ✅ Checkout cargó exitosamente → limpiar returnUrl para que no persista
    sessionStorage.removeItem("returnUrl");

    const responseCarrito = await fetch(`${API_BASE}carrito.php?action=ver`);
    const dataCarrito = await responseCarrito.json();

    if (dataCarrito.success && dataCarrito.carrito.items.length > 0) {
      carritoData = dataCarrito.carrito;
      bonoData = dataVerif.bono || null;
      renderizarCheckout(dataVerif.usuario, carritoData);
      inicializarBono();
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
                
                ${htmlBono(carrito)}

                <h2 style="margin-top: 2rem;"><i class="fas fa-credit-card"></i> Método de Pago</h2>
                
                <div class="form-grupo">
                    <label style="display: flex; align-items: center; gap: 1rem; padding: 1rem; background: var(--color-fondo); border-radius: var(--radio-borde); cursor: pointer;">
                        <input type="radio" name="metodo_pago" value="contraentrega" checked>
                        <i class="fas fa-money-bill-wave" style="font-size: 1.5rem; color: var(--color-exito);"></i>
                        <div>
                            <strong>Pago Contra Entrega</strong>
                            <p style="font-size: 0.85rem; color: var(--color-texto-claro);">Paga al recibir: en efectivo o con tarjeta/PSE escaneando un código QR</p>
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
                
                <div class="form-grupo">
                    <label style="display: flex; align-items: center; gap: 1rem; padding: 1rem; background: var(--color-fondo); border-radius: var(--radio-borde); cursor: pointer;">
                        <input type="radio" name="metodo_pago" value="mercadopago">
                        <i class="fas fa-credit-card" style="font-size: 1.5rem; color: #009ee3;"></i>
                        <div>
                            <strong>MercadoPago</strong>
                            <p style="font-size: 0.85rem; color: var(--color-texto-claro);">Tarjeta, PSE o efectivo. Pagas de forma segura en MercadoPago</p>
                        </div>
                    </label>
                </div>
                
                <div class="checkout-acciones">
                    <button type="submit" class="btn-checkout" id="btn-confirmar">
                        <i class="fas fa-check"></i> Confirmar Pedido
                    </button>
                    <button type="button" class="btn-cancelar-checkout" id="btn-cancelar-checkout" onclick="cancelarCheckout()">
                        <i class="fas fa-store"></i> Seguir Comprando
                    </button>
                </div>
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
            
            <div class="resumen-linea resumen-linea-bono" id="resumen-bono" hidden>
                <span>🎁 Bono por puntos</span>
                <span id="resumen-bono-valor"></span>
            </div>
            
            <div class="resumen-linea total">
                <span>Total a Pagar</span>
                <span id="resumen-total">${carrito.total_formateado}</span>
            </div>
            
            <a href="carrito.html" class="btn-seguir-comprando">
                <i class="fas fa-arrow-left"></i> Volver al Carrito
            </a>
        </div>
    `;
}

// ==== BONO POR PUNTOS ====
// El asociado con bono disponible puede redimirlo: se descuenta del total
// (sin pasar de 0) y el servidor lo marca entregado con el pedido.
function formatearCOP(valor) {
  return "$ " + Math.round(valor).toLocaleString("es-CO");
}

function htmlBono(carrito) {
  if (!bonoData || bonoData.disponibles < 1) return "";
  if (!bonoData.valor) {
    return `
        <div class="checkout-bono checkout-bono--inactivo">
            <i class="fas fa-gift"></i>
            <div><strong>Tienes un bono por puntos</strong>
            <p>Su valor aún no está definido por la tienda; pronto podrás usarlo.</p></div>
        </div>`;
  }
  const cubreTodo = bonoData.valor >= carrito.total;
  const cantidad = bonoData.disponibles === 1 ? "un bono" : `${bonoData.disponibles} bonos`;
  return `
        <label class="checkout-bono" for="usar_bono">
            <input type="checkbox" id="usar_bono" name="usar_bono" value="1">
            <i class="fas fa-gift"></i>
            <div>
                <strong>Usar mi bono de ${bonoData.valor_formateado} en esta compra</strong>
                <p>Tienes ${cantidad} por tus puntos. Al usarlo se descuenta del total y tus puntos empiezan a acumular de nuevo.</p>
                ${cubreTodo ? `<p class="checkout-bono-aviso">Tu bono cubre toda la compra: no tendrás que pagar nada. Lo que sobre del bono no se guarda.</p>` : ""}
            </div>
        </label>`;
}

function inicializarBono() {
  const casilla = document.getElementById("usar_bono");
  if (casilla) casilla.addEventListener("change", actualizarResumenBono);
}

function actualizarResumenBono() {
  const casilla = document.getElementById("usar_bono");
  const usar = !!(casilla && casilla.checked);
  const descuento = usar ? Math.min(bonoData.valor, carritoData.total) : 0;
  const total = Math.max(0, carritoData.total - descuento);

  document.getElementById("resumen-bono").hidden = !usar;
  document.getElementById("resumen-bono-valor").textContent = "- " + formatearCOP(descuento);
  document.getElementById("resumen-total").textContent = usar ? formatearCOP(total) : carritoData.total_formateado;

  // Sin nada que pagar, MercadoPago no aplica
  const radioMP = document.querySelector('input[name="metodo_pago"][value="mercadopago"]');
  if (radioMP) {
    radioMP.disabled = usar && total <= 0;
    radioMP.closest("label").style.opacity = radioMP.disabled ? "0.5" : "";
    if (radioMP.disabled && radioMP.checked) {
      document.querySelector('input[name="metodo_pago"][value="contraentrega"]').checked = true;
    }
  }
}

async function procesarPedido(event) {
  event.preventDefault();

  const form = document.getElementById("form-checkout");
  const btn = document.getElementById("btn-confirmar");
  const formData = new FormData(form);

  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Procesando...';

  if (formData.get("metodo_pago") === "mercadopago") {
    await pagarConMercadoPago(formData, btn);
    return;
  }

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


/**
 * MercadoPago: crea el pedido pendiente y la preferencia, y redirige al pago.
 * El pedido se confirma cuando MercadoPago aprueba el pago (webhook).
 */
async function pagarConMercadoPago(formData, btn) {
  const restaurarBoton = () => {
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-check"></i> Confirmar Pedido';
  };

  try {
    const response = await fetch(`${API_BASE}pagos.php?action=crear_preferencia`, {
      method: "POST",
      body: formData,
    });
    const data = await response.json();
    const urlPago = data.success
      ? data.preferencia.init_point || data.preferencia.sandbox_init_point
      : null;

    if (urlPago) {
      btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Redirigiendo a MercadoPago...';
      window.location.href = urlPago;
      return;
    }

    Swal.fire({
      icon: "error",
      title: "Error",
      text: data.error || "No se pudo iniciar el pago con MercadoPago",
      customClass: {
        popup: "swal-tcp-popup",
        confirmButton: "btn-swal-confirm",
      },
      buttonsStyling: false,
    });
    restaurarBoton();
  } catch (error) {
    console.error("Error:", error);
    Swal.fire({
      icon: "error",
      title: "Error de conexión",
      text: "No se pudo iniciar el pago. Intente nuevamente.",
      customClass: {
        popup: "swal-tcp-popup",
        confirmButton: "btn-swal-confirm",
      },
      buttonsStyling: false,
    });
    restaurarBoton();
  }
}

/**
 * Cancelar checkout y volver a la tienda.
 * Mantiene el carrito intacto para que el usuario pueda regresar después.
 * La sesión del usuario permanece activa.
 */
function cancelarCheckout() {
  Swal.fire({
    title: '¿Deseas volver a la tienda?',
    text: 'Tu carrito se mantendrá guardado para cuando quieras continuar.',
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: '<i class="fas fa-store"></i> Sí, seguir comprando',
    cancelButtonText: 'Quedarme aquí',
    customClass: {
      popup: 'swal-tcp-popup',
      confirmButton: 'btn-swal-confirm',
      cancelButton: 'btn-swal-cancel'
    },
    buttonsStyling: false
  }).then((result) => {
    if (result.isConfirmed) {
      window.location.href = 'index.html';
    }
  });
}
