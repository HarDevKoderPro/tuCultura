/**
 * JavaScript Principal de la Tienda
 * Tu Cultura es Progreso - Tienda Virtual
 */

const isLocal = window.location.hostname === "localhost";
const API_BASE = isLocal
  ? "/localhost/PORTAFOLIO/05-%20TUCULTURA/05-tienda/php/"
  : "/05-tienda/php/";

// Variables globales
let productos = [];
let categoriaActual = 0;
let paginaActual = 1;
let ordenActual = "relevancia";
let precioMin = null;
let precioMax = null;
let timeoutPrecio = null;

// ==== INICIALIZACIÓN ====

document.addEventListener("DOMContentLoaded", () => {
  cargarCategorias();
  cargarProductos();
  cargarProductosDestacados();
  actualizarContadorCarrito();
  initEventListeners();

  const modalDetalle = document.getElementById("modal-detalle-producto");
  if (modalDetalle) {
    modalDetalle.addEventListener("click", (e) => {
      if (e.target.id === "modal-detalle-producto") cerrarModalDetalle();
    });
  }

  window.addEventListener("scroll", () => {
    const btn = document.getElementById("btn-volver-arriba");
    if (btn) btn.classList.toggle("visible", window.scrollY > 400);
  });
});

function initEventListeners() {
  // Búsqueda
  const btnBuscar = document.getElementById("btn-buscar");
  const inputBusqueda = document.getElementById("busqueda-input");
  if (btnBuscar) btnBuscar.addEventListener("click", buscarProductos);
  if (inputBusqueda) {
    inputBusqueda.addEventListener("keypress", (e) => {
      if (e.key === "Enter") buscarProductos();
    });
  }

  // Orden (Select superior) — automático
  const selectOrden = document.getElementById("select-orden");
  if (selectOrden) {
    selectOrden.addEventListener("change", (e) => {
      ordenActual = e.target.value;
      const radio = document.querySelector(
        `input[name="orden"][value="${ordenActual}"]`,
      );
      if (radio) radio.checked = true;
      aplicarFiltros();
    });
  }

  // Orden (Radios del sidebar) — automático
  document.querySelectorAll('input[name="orden"]').forEach((radio) => {
    radio.addEventListener("change", () => {
      ordenActual = radio.value;
      const select = document.getElementById("select-orden");
      if (select) select.value = ordenActual;
      aplicarFiltros();
    });
  });

  // Precios — automático con debounce
  const minInput = document.getElementById("precio-min");
  const maxInput = document.getElementById("precio-max");
  [minInput, maxInput].forEach((inp) => {
    if (inp) {
      inp.addEventListener("input", () => {
        clearTimeout(timeoutPrecio);
        timeoutPrecio = setTimeout(aplicarFiltros, 700);
      });
    }
  });
}

// ==== CATEGORÍAS ====

async function cargarCategorias() {
  try {
    const response = await fetch(`${API_BASE}categorias.php?action=listar`);
    const text = await response.text();
    console.log("[CATEGORIAS RAW]", text);
    const data = JSON.parse(text);
    if (data.success) {
      renderizarNavCategorias(data.categorias);
      renderizarFiltroCategorias(data.categorias);
    }
  } catch (error) {
    console.error("Error al cargar categorías:", error);
  }
}

function renderizarNavCategorias(categorias) {
  const nav = document.getElementById("nav-categorias");
  if (!nav) return;

  let html = '<a href="#" data-categoria="0" class="activo">Todos</a>';
  categorias.forEach((cat) => {
    html += `<a href="#" data-categoria="${cat.id}">${cat.nombre} (${cat.total_productos})</a>`;
  });
  nav.innerHTML = html;

  nav.querySelectorAll("a").forEach((link) => {
    link.addEventListener("click", (e) => {
      e.preventDefault();
      categoriaActual = parseInt(link.dataset.categoria);
      const radio = document.querySelector(
        `input[name="categoria"][value="${categoriaActual}"]`,
      );
      if (radio) radio.checked = true;
      aplicarFiltros();
    });
  });
}

function renderizarFiltroCategorias(categorias) {
  const filtro = document.getElementById("filtro-categorias");
  if (!filtro) return;

  let html = `<label><input type="radio" name="categoria" value="0" checked> Todas</label>`;
  categorias.forEach((cat) => {
    html += `<label><input type="radio" name="categoria" value="${cat.id}"> ${cat.nombre}</label>`;
  });
  filtro.innerHTML = html;

  filtro.querySelectorAll('input[name="categoria"]').forEach((radio) => {
    radio.addEventListener("change", () => {
      categoriaActual = parseInt(radio.value);
      const navLinks = document.querySelectorAll("#nav-categorias a");
      navLinks.forEach((link) => {
        link.classList.toggle(
          "activo",
          parseInt(link.dataset.categoria) === categoriaActual,
        );
      });
      aplicarFiltros();
    });
  });
}

function actualizarTituloCategoria(nombre) {
  const titulo = document.getElementById("titulo-categoria");
  if (titulo) {
    titulo.textContent =
      nombre === "Todos" || nombre === "Todas" ? "Todos los Productos" : nombre;
  }
}

// ==== FILTROS COMBINADOS ====

function aplicarFiltros() {
  // 1. Capturar categoría
  const catRadio = document.querySelector('input[name="categoria"]:checked');
  categoriaActual = catRadio ? parseInt(catRadio.value) : 0;

  // 2. Capturar orden
  const ordRadio = document.querySelector('input[name="orden"]:checked');
  ordenActual = ordRadio ? ordRadio.value : "relevancia";

  // 3. Capturar precios (solo si tienen valor real)
  const minInp = document.getElementById("precio-min");
  const maxInp = document.getElementById("precio-max");
  precioMin = minInp && minInp.value !== "" ? parseFloat(minInp.value) : null;
  precioMax = maxInp && maxInp.value !== "" ? parseFloat(maxInp.value) : null;

  // 4. Actualizar título
  const catLabel = catRadio
    ? catRadio.parentElement.textContent.trim()
    : "Todos";
  actualizarTituloCategoria(catLabel);

  // 5. Ocultar destacados si hay filtros activos
  const destacados = document.getElementById("productos-destacados");
  if (destacados) {
    const hayFiltros =
      categoriaActual !== 0 ||
      precioMin !== null ||
      precioMax !== null ||
      ordenActual !== "relevancia";
    destacados.classList.toggle("oculto", hayFiltros);
  }

  // 6. Resetear página y cargar
  paginaActual = 1;
  cargarProductos();
}

// ==== PRODUCTOS ====

async function cargarProductos() {
  const grid = document.getElementById("productos-grid");
  if (!grid) return;

  grid.innerHTML = '<div class="loading"><div class="spinner"></div></div>';

  try {
    // Construir URL con URLSearchParams para evitar parámetros vacíos
    const params = new URLSearchParams({
      action: "listar",
      pagina: paginaActual,
      orden: ordenActual,
    });

    if (categoriaActual > 0) params.append("categoria", categoriaActual);

    // Solo enviar precio si tiene valor real (individual o combinado)
    const minInp = document.getElementById("precio-min");
    const maxInp = document.getElementById("precio-max");
    const pMin = minInp && minInp.value !== "" ? minInp.value : null;
    const pMax = maxInp && maxInp.value !== "" ? maxInp.value : null;
    if (pMin !== null) params.append("precio_min", pMin);
    if (pMax !== null) params.append("precio_max", pMax);

    const url = `${API_BASE}productos.php?${params.toString()}`;
    console.log("[PRODUCTOS URL]", url);

    const response = await fetch(url);
    const text = await response.text();
    console.log("[PRODUCTOS RAW]", text);
    const data = JSON.parse(text);

    if (data.success) {
      productos = data.productos;
      renderizarProductos(data.productos);
      renderizarPaginacion(data.paginacion);
    } else {
      grid.innerHTML =
        '<p class="mensaje info">No se encontraron productos con estos filtros.</p>';
    }
  } catch (error) {
    console.error("Error al cargar productos:", error);
    grid.innerHTML =
      '<p class="mensaje error">Error al cargar los productos.</p>';
  }
}

async function cargarProductosDestacados() {
  const seccion = document.getElementById("productos-destacados");
  const grid = document.getElementById("grid-destacados");
  if (!seccion || !grid) return;

  try {
    const response = await fetch(
      `${API_BASE}productos.php?action=destacados&limite=4`,
    );
    const data = await response.json();
    if (data.success && data.productos.length > 0) {
      seccion.classList.remove("oculto");
      grid.innerHTML = data.productos
        .map((p) => crearTarjetaProducto(p))
        .join("");
      agregarEventosCarrito(grid);
    }
  } catch (error) {
    console.error("Error al cargar destacados:", error);
  }
}

function renderizarProductos(lista) {
  const grid = document.getElementById("productos-grid");
  if (!grid) return;

  if (lista.length === 0) {
    grid.innerHTML = `
      <div style="grid-column: 1/-1; text-align: center; padding: 3rem;">
        <i class="fas fa-search" style="font-size: 3rem; color: #ccc; margin-bottom: 1rem; display:block;"></i>
        <p>No se encontraron productos con los filtros seleccionados.</p>
      </div>`;
    return;
  }

  grid.innerHTML = lista.map((p) => crearTarjetaProducto(p)).join("");
  agregarEventosCarrito(grid);
}

function crearTarjetaProducto(producto) {
  const imagen = producto.imagen || "sin-imagen.png";
  const imgSrc = `imagenes/productos/${imagen}`;
  const imgFallback = "../01-principal/imagenes/fondo-producto.png";
  const tieneOferta =
    producto.precio_oferta && producto.precio_oferta < producto.precio;

  return `
    <article class="producto-card" data-id="${producto.id}" onclick="verDetalleProducto(${producto.id})">
      <div class="producto-imagen">
        ${tieneOferta ? `<span class="producto-badge oferta">-${producto.descuento_porcentaje}%</span>` : ""}
        ${producto.destacado ? '<span class="producto-badge">Destacado</span>' : ""}
        <img src="${imgSrc}" alt="${producto.nombre}" onerror="this.src='${imgFallback}'">
      </div>
      <div class="producto-info">
        <span class="producto-categoria">${producto.categoria_nombre || "Sin categoría"}</span>
        <h3 class="producto-nombre">
          <a href="#" onclick="event.preventDefault()">${producto.nombre}</a>
        </h3>
        <div class="producto-precios">
          ${
            tieneOferta
              ? `<span class="producto-precio">${producto.precio_oferta_formateado}</span>
                 <span class="producto-precio-original">${producto.precio_formateado}</span>`
              : `<span class="producto-precio">${producto.precio_formateado}</span>`
          }
        </div>
        <div class="producto-acciones">
          <button class="btn-agregar-carrito" data-id="${producto.id}">
            <i class="fas fa-cart-plus"></i> Agregar
          </button>
        </div>
      </div>
    </article>
  `;
}

function agregarEventosCarrito(container) {
  container.querySelectorAll(".btn-agregar-carrito").forEach((btn) => {
    btn.addEventListener("click", (e) => {
      e.preventDefault();
      e.stopPropagation(); // Evita abrir el modal al hacer clic en Agregar
      agregarAlCarrito(btn.dataset.id);
    });
  });
}

// ==== MODAL DETALLE PRODUCTO ====

function verDetalleProducto(id) {
  const p = productos.find((x) => x.id == id);
  if (!p) return;

  document.getElementById("detalle-nombre").textContent = p.nombre;
  document.getElementById("detalle-categoria").textContent =
    p.categoria_nombre || "Sin categoría";

  const img = document.getElementById("detalle-img");
  img.src = `imagenes/productos/${p.imagen || "sin-imagen.png"}`;
  img.onerror = () => {
    img.src = "../01-principal/imagenes/fondo-producto.png";
  };

  const preciosEl = document.getElementById("detalle-precios");
  const tieneOferta = p.precio_oferta && p.precio_oferta < p.precio;
  preciosEl.innerHTML = tieneOferta
    ? `<span class="producto-precio">${p.precio_oferta_formateado}</span>
       <span class="producto-precio-original">${p.precio_formateado}</span>`
    : `<span class="producto-precio">${p.precio_formateado}</span>`;

  const descEl = document.getElementById("detalle-descripcion-texto");
  const descripcion = p.descripcion || "Sin descripción disponible.";
  const items = descripcion
    .split(/,|\n/)
    .map((i) => i.trim())
    .filter((i) => i.length > 3);
  descEl.innerHTML =
    items.length > 1
      ? `<ul class="lista-detalle">${items.map((i) => `<li>${i}</li>`).join("")}</ul>`
      : `<p>${descripcion}</p>`;

  const btnAgregar = document.getElementById("btn-modal-agregar");
  btnAgregar.onclick = () => {
    agregarAlCarrito(p.id);
    cerrarModalDetalle();
  };

  document.getElementById("modal-detalle-producto").classList.add("activo");
}

function cerrarModalDetalle() {
  const modal = document.getElementById("modal-detalle-producto");
  if (modal) modal.classList.remove("activo");
}

// ==== CARRITO ====

async function agregarAlCarrito(productoId, cantidad = 1) {
  try {
    const formData = new FormData();
    formData.append("producto_id", productoId);
    formData.append("cantidad", cantidad);

    const response = await fetch(`${API_BASE}carrito.php?action=agregar`, {
      method: "POST",
      body: formData,
    });
    const data = await response.json();

    if (data.success) {
      actualizarContadorCarrito(data.total_items);
      mostrarToast(data.mensaje || "¡Producto agregado al carrito!", "exito");
    } else {
      mostrarToast(data.error || "No se pudo agregar el producto", "error");
    }
  } catch (error) {
    console.error("Error al agregar al carrito:", error);
    mostrarToast("Error de conexión. Intente nuevamente.", "error");
  }
}

function mostrarToast(mensaje, tipo = "exito") {
  const anterior = document.getElementById("tcp-toast");
  if (anterior) anterior.remove();

  const toast = document.createElement("div");
  toast.id = "tcp-toast";
  toast.className = `tcp-toast tcp-toast-${tipo}`;
  toast.innerHTML = `
    <i class="fas ${tipo === "exito" ? "fa-check-circle" : "fa-exclamation-circle"}"></i>
    <span>${mensaje}</span>
  `;
  document.body.appendChild(toast);
  requestAnimationFrame(() => toast.classList.add("tcp-toast-visible"));
  setTimeout(() => {
    toast.classList.remove("tcp-toast-visible");
    setTimeout(() => toast.remove(), 400);
  }, 2500);
}

async function actualizarContadorCarrito(cantidad = null) {
  const badge = document.getElementById("carrito-cantidad");
  if (!badge) return;

  if (cantidad !== null) {
    badge.textContent = cantidad;
    return;
  }

  try {
    const response = await fetch(`${API_BASE}carrito.php?action=contar`);
    const data = await response.json();
    if (data.success) badge.textContent = data.total_items;
  } catch (error) {
    console.error("Error al contar items del carrito:", error);
  }
}

// ==== BÚSQUEDA ====

async function buscarProductos() {
  const input = document.getElementById("busqueda-input");
  const query = input?.value.trim();

  if (!query || query.length < 2) {
    mostrarToast("Ingresa al menos 2 caracteres para buscar", "error");
    return;
  }

  const grid = document.getElementById("productos-grid");
  if (!grid) return;

  grid.innerHTML = '<div class="loading"><div class="spinner"></div></div>';

  const seccionDestacados = document.getElementById("productos-destacados");
  if (seccionDestacados) seccionDestacados.classList.add("oculto");

  actualizarTituloCategoria(`Resultados para "${query}"`);

  try {
    const response = await fetch(
      `${API_BASE}productos.php?action=buscar&q=${encodeURIComponent(query)}`,
    );
    const data = await response.json();

    if (data.success) {
      productos = data.productos;
      renderizarProductos(data.productos);
      const pag = document.getElementById("paginacion");
      if (pag) pag.innerHTML = "";
    } else {
      grid.innerHTML =
        '<p class="mensaje info">No se encontraron productos.</p>';
    }
  } catch (error) {
    console.error("Error en búsqueda:", error);
    grid.innerHTML = '<p class="mensaje error">Error en la búsqueda.</p>';
  }
}

// ==== PAGINACIÓN ====

function renderizarPaginacion(paginacion) {
  const container = document.getElementById("paginacion");
  if (!container) return;

  if (!paginacion || paginacion.total_paginas <= 1) {
    container.innerHTML = "";
    return;
  }

  let html = "";
  html += `<button ${paginacion.pagina === 1 ? "disabled" : ""} onclick="cambiarPagina(${paginacion.pagina - 1})">
    <i class="fas fa-chevron-left"></i>
  </button>`;

  const inicio = Math.max(1, paginacion.pagina - 2);
  const fin = Math.min(paginacion.total_paginas, paginacion.pagina + 2);

  if (inicio > 1) {
    html += `<span onclick="cambiarPagina(1)">1</span>`;
    if (inicio > 2) html += `<span>...</span>`;
  }

  for (let i = inicio; i <= fin; i++) {
    html += `<span class="${i === paginacion.pagina ? "activa" : ""}" onclick="cambiarPagina(${i})">${i}</span>`;
  }

  if (fin < paginacion.total_paginas) {
    if (fin < paginacion.total_paginas - 1) html += `<span>...</span>`;
    html += `<span onclick="cambiarPagina(${paginacion.total_paginas})">${paginacion.total_paginas}</span>`;
  }

  html += `<button ${paginacion.pagina === paginacion.total_paginas ? "disabled" : ""} onclick="cambiarPagina(${paginacion.pagina + 1})">
    <i class="fas fa-chevron-right"></i>
  </button>`;

  container.innerHTML = html;
}

function cambiarPagina(pagina) {
  paginaActual = pagina;
  cargarProductos();
  window.scrollTo({ top: 0, behavior: "smooth" });
}
