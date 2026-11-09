document.addEventListener("DOMContentLoaded", () => {
  // ─────────────────────────────────────────
  // FLIP DE TARJETAS DE PRODUCTOS
  // ─────────────────────────────────────────
  const tarjetas = document.querySelectorAll(".tarjeta");

  function activarGiro(tarjeta) {
    const inner = tarjeta.querySelector(".tarjeta-inner");
    const footer = tarjeta.querySelector(".tarjeta-footer");
    if (!inner || !footer) return;

    footer.addEventListener("click", (event) => {
      event.stopPropagation();
      inner.classList.toggle("is-flipped");
    });

    const trasera = tarjeta.querySelector(".tarjeta-trasera");
    if (trasera) {
      trasera.addEventListener("click", (event) => {
        event.stopPropagation();
        inner.classList.remove("is-flipped");
      });
    }
  }

  tarjetas.forEach(activarGiro);

  // ─────────────────────────────────────────
  // CELULAR: la tarjeta tocada se abre en grande en una ventana
  // (se puede girar y usar el carrito); al cerrarla vuelve la fila de 3.
  // ─────────────────────────────────────────
  const ES_CELULAR = window.matchMedia("(max-width: 768px) and (orientation: portrait)");
  const contenedorTarjetas = document.querySelector(".contenedorTarjetas");
  let ventanaTarjeta = null;
  let tarjetaOrigen = null;

  function cerrarVentanaTarjeta() {
    if (!ventanaTarjeta) return;
    const ventana = ventanaTarjeta;
    ventanaTarjeta = null;
    ventana.classList.remove("abierta");
    document.body.classList.remove("con-ventana-tarjeta");
    setTimeout(() => ventana.remove(), 220);
    if (tarjetaOrigen) tarjetaOrigen.focus({ preventScroll: true });
  }

  function abrirVentanaTarjeta(tarjeta) {
    tarjetaOrigen = tarjeta;
    const copia = tarjeta.cloneNode(true);
    copia.classList.add("tarjeta--grande");
    copia.removeAttribute("tabindex");
    copia.removeAttribute("role");
    copia.querySelector(".tarjeta-inner")?.classList.remove("is-flipped");
    activarGiro(copia);

    // El carrito de la copia usa el boton original (misma logica y avisos)
    const carritoCopia = copia.querySelector(".tarjeta-carrito");
    const carritoOriginal = tarjeta.querySelector(".tarjeta-carrito");
    if (carritoCopia && carritoOriginal) {
      carritoCopia.addEventListener("click", (event) => {
        event.stopPropagation();
        carritoOriginal.click();
      });
    }

    const ventana = document.createElement("div");
    ventana.className = "ventana-tarjeta";
    ventana.setAttribute("role", "dialog");
    ventana.setAttribute("aria-modal", "true");
    const titulo = tarjeta.querySelector(".tarjeta-titulo");
    ventana.setAttribute("aria-label", titulo ? titulo.textContent.trim() : "Producto destacado");

    const cerrar = document.createElement("button");
    cerrar.type = "button";
    cerrar.className = "ventana-tarjeta-cerrar";
    cerrar.setAttribute("aria-label", "Cerrar");
    cerrar.innerHTML = "&times;";
    cerrar.addEventListener("click", cerrarVentanaTarjeta);

    const ayuda = document.createElement("p");
    ayuda.className = "ventana-tarjeta-ayuda";
    const TEXTO_FRENTE = "Toca «Ver características» para girarla";
    ayuda.textContent = TEXTO_FRENTE;
    const innerCopia = copia.querySelector(".tarjeta-inner");
    if (innerCopia) {
      new MutationObserver(() => {
        ayuda.textContent = innerCopia.classList.contains("is-flipped")
          ? "Toca la tarjeta para volver a la foto"
          : TEXTO_FRENTE;
      }).observe(innerCopia, { attributes: true, attributeFilter: ["class"] });
    }

    ventana.append(cerrar, copia, ayuda);
    // Tocar el fondo oscuro cierra
    ventana.addEventListener("click", (event) => {
      if (event.target === ventana) cerrarVentanaTarjeta();
    });

    document.body.appendChild(ventana);
    document.body.classList.add("con-ventana-tarjeta");
    ventanaTarjeta = ventana;
    requestAnimationFrame(() => ventana.classList.add("abierta"));
    cerrar.focus({ preventScroll: true });
  }

  if (contenedorTarjetas && tarjetas.length > 0) {
    tarjetas.forEach((tarjeta) => {
      tarjeta.setAttribute("tabindex", "0");
      tarjeta.addEventListener("keydown", (event) => {
        if (ES_CELULAR.matches && (event.key === "Enter" || event.key === " ")) {
          event.preventDefault();
          abrirVentanaTarjeta(tarjeta);
        }
      });
    });
    // Fase de captura: en celular el toque abre la ventana en vez de girar la tarjeta pequena
    contenedorTarjetas.addEventListener("click", (event) => {
      // Con la ventana abierta (p. ej. el carrito reenviado al boton original) no se intercepta
      if (!ES_CELULAR.matches || ventanaTarjeta) return;
      const tarjeta = event.target.closest(".tarjeta");
      if (!tarjeta) return;
      event.preventDefault();
      event.stopPropagation();
      abrirVentanaTarjeta(tarjeta);
    }, true);
    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape") cerrarVentanaTarjeta();
    });
    ES_CELULAR.addEventListener?.("change", (e) => { if (!e.matches) cerrarVentanaTarjeta(); });
  }

  // ─────────────────────────────────────────
  // AGREGAR AL CARRITO DESDE LAS TARJETAS
  // Usa la misma API y sesion de la tienda (carrito del usuario logueado)
  // ─────────────────────────────────────────
  const URL_TIENDA = "../../05-tienda/";

  function mostrarAvisoCarrito(mensaje, tipo, conEnlace) {
    let aviso = document.getElementById("aviso-carrito");
    if (!aviso) {
      aviso = document.createElement("div");
      aviso.id = "aviso-carrito";
      aviso.setAttribute("role", "status");
      document.body.appendChild(aviso);
    }
    aviso.className = `aviso-carrito aviso-carrito--${tipo}`;
    aviso.innerHTML = "";
    const texto = document.createElement("span");
    texto.textContent = mensaje;
    aviso.appendChild(texto);
    if (conEnlace) {
      const enlace = document.createElement("a");
      enlace.href = `${URL_TIENDA}carrito.html`;
      enlace.textContent = "Ver carrito";
      aviso.appendChild(enlace);
    }
    aviso.classList.add("visible");
    clearTimeout(aviso._timer);
    aviso._timer = setTimeout(() => aviso.classList.remove("visible"), 4000);
  }

  document.querySelectorAll(".tarjeta-carrito").forEach((boton) => {
    boton.addEventListener("click", async (event) => {
      // Evita que el clic gire la tarjeta
      event.stopPropagation();

      if (boton.dataset.admin === "1") {
        mostrarAvisoCarrito(
          "Las cuentas de administrador no pueden comprar. Ingresa como asociado.",
          "error",
          false,
        );
        return;
      }

      boton.disabled = true;
      try {
        const datos = new FormData();
        datos.append("producto_id", boton.dataset.productoId);
        datos.append("cantidad", "1");
        const res = await fetch(`${URL_TIENDA}php/carrito.php?action=agregar`, {
          method: "POST",
          body: datos,
          credentials: "same-origin",
        });
        const data = await res.json();
        if (data.success) {
          mostrarAvisoCarrito(`${data.producto_nombre}: ${data.mensaje.toLowerCase()}`, "exito", true);
        } else {
          mostrarAvisoCarrito(data.error || "No se pudo agregar el producto", "error", false);
        }
      } catch (err) {
        console.error("Error agregando al carrito:", err);
        mostrarAvisoCarrito("Error de conexión. Intenta de nuevo.", "error", false);
      } finally {
        boton.disabled = false;
      }
    });
  });

  // ─────────────────────────────────────────
  // CARGA DINÁMICA DE TABLA POR NIVEL
  // ─────────────────────────────────────────
  const statCards = document.querySelectorAll(".stat-card--clickable");
  const tablaBody = document.getElementById("tablaBody");
  const tablaTotalCount = document.getElementById("tablaTotalCount");
  const tituloNivel = document.getElementById("tituloNivel");

  // Marcar tarjeta activa visualmente
  function setNivelActivo(nivel) {
    statCards.forEach((card) => {
      card.classList.toggle(
        "stat-card--activa",
        parseInt(card.dataset.nivel) === nivel,
      );
    });
  }

  // Construir filas de la tabla (asociado y fecha)
  function renderTabla(datos, nivel) {
    tablaBody.innerHTML = "";
    tituloNivel.textContent = `📋 Listado de Registros — Nivel ${nivel}`;

    if (!datos || datos.length === 0) {
      tablaBody.innerHTML = `<tr><td colspan="2" class="tabla-vacia">No hay registros para mostrar.</td></tr>`;
      tablaTotalCount.textContent = 0;
      return;
    }

    datos.forEach((reg) => {
      // Solo nombre y fecha (proteccion de datos personales)
      const tr = document.createElement("tr");
      const tdAsociado = document.createElement("td");
      tdAsociado.textContent = `${reg.nombres} ${reg.apellidos}`;
      const tdFecha = document.createElement("td");
      tdFecha.textContent = reg.fecha;
      tr.append(tdAsociado, tdFecha);
      tablaBody.appendChild(tr);
    });

    tablaTotalCount.textContent = datos.length;
  }

  // Fetch al endpoint
  function cargarTabla(nivel) {
    tablaBody.innerHTML = `<tr><td colspan="2" class="tabla-cargando">Cargando...</td></tr>`;
    tablaTotalCount.textContent = "...";

    fetch(`./obtener_niveles.php?nivel=${nivel}`)
      .then((res) => {
        if (!res.ok) throw new Error("Error en la respuesta del servidor");
        return res.json();
      })
      .then((datos) => {
        renderTabla(datos, nivel);
        setNivelActivo(nivel);
      })
      .catch((err) => {
        tablaBody.innerHTML = `<tr><td colspan="2" class="tabla-vacia">Error al cargar los datos.</td></tr>`;
        console.error("Error cargando tabla:", err);
      });
  }

  // Event listeners en tarjetas clickeables
  statCards.forEach((card) => {
    card.addEventListener("click", () => {
      const nivel = parseInt(card.dataset.nivel);
      cargarTabla(nivel);
    });
  });

  // Nivel 1 activo por defecto al cargar
  setNivelActivo(1);

  // ─────────────────────────────────────────
  // CÁLCULO AUTOMÁTICO: TOTAL REGISTROS USUARIO
  // Suma los valores de las cards Nivel 1 + Nivel 2 + Nivel 3
  // y actualiza la card "Total Registros Usuario"
  // ─────────────────────────────────────────
  function calcularTotalUsuario() {
    let total = 0;

    // Recorrer cada card con data-nivel (1, 2 y 3)
    document.querySelectorAll(".stat-card[data-nivel]").forEach((card) => {
      // Obtener el elemento .stat-value dentro de la card
      const valorEl = card.querySelector(".stat-value");
      if (valorEl) {
        // Parsear el texto a número; si no es válido, usar 0
        const valor = parseInt(valorEl.textContent.trim(), 10);
        total += isNaN(valor) ? 0 : valor;
      }
    });

    // Actualizar el span de la card "Total Registros Usuario"
    const spanTotal = document.getElementById("total-usuario-valor");
    if (spanTotal) {
      spanTotal.textContent = total;
    }
  }

  // Ejecutar el cálculo al cargar la página
  calcularTotalUsuario();
});
