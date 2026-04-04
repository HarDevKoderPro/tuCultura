document.addEventListener("DOMContentLoaded", () => {
  // ─────────────────────────────────────────
  // FLIP DE TARJETAS DE PRODUCTOS
  // ─────────────────────────────────────────
  const tarjetas = document.querySelectorAll(".tarjeta");

  if (tarjetas && tarjetas.length > 0) {
    tarjetas.forEach((tarjeta) => {
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
    });
  }

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

  // Construir filas de la tabla (sin columna #, con teléfono)
  function renderTabla(datos, nivel) {
    tablaBody.innerHTML = "";
    tituloNivel.textContent = `📋 Listado de Registros — Nivel ${nivel}`;

    if (!datos || datos.length === 0) {
      tablaBody.innerHTML = `<tr><td colspan="5" class="tabla-vacia">No hay registros para mostrar.</td></tr>`;
      tablaTotalCount.textContent = 0;
      return;
    }

    datos.forEach((reg) => {
      const asociado = `${reg.nombres} ${reg.apellidos}`;
      const tr = document.createElement("tr");
      tr.innerHTML = `
        <td>${asociado}</td>
        <td>${reg.documento}</td>
        <td>${reg.email}</td>
        <td>${reg.telefono ?? "-"}</td>
        <td>${reg.fecha}</td>
      `;
      tablaBody.appendChild(tr);
    });

    tablaTotalCount.textContent = datos.length;
  }

  // Fetch al endpoint
  function cargarTabla(nivel) {
    tablaBody.innerHTML = `<tr><td colspan="5" class="tabla-cargando">Cargando...</td></tr>`;
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
        tablaBody.innerHTML = `<tr><td colspan="5" class="tabla-vacia">Error al cargar los datos.</td></tr>`;
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
