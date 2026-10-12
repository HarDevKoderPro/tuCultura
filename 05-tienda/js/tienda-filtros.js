// =========================================================================
// FILTROS DE LA TIENDA EN CELULAR
// =========================================================================
// En celular (<768px) la barra lateral de filtros se muestra como un panel
// que sube desde abajo, con el precio y "solo destacados"; las categorias van en
// un desplegable arriba y el orden en su selector. En escritorio la barra
// lateral y la fila de categorias se ven como siempre. Usa aplicarFiltros() y
// timeoutPrecio de tienda.js.

(() => {
  const panel = document.getElementById("filtros-sidebar");
  const fondo = document.getElementById("filtros-fondo");
  const botonAbrir = document.getElementById("btn-abrir-filtros");
  const botonCerrar = document.getElementById("btn-cerrar-filtros");
  const botonVer = document.getElementById("btn-ver-productos");
  const botonLimpiar = document.getElementById("btn-limpiar-filtros");
  const contador = document.getElementById("filtros-activos");
  const precioMinimo = document.getElementById("precio-min");
  const precioMaximo = document.getElementById("precio-max");
  const soloDestacados = document.getElementById("filtro-destacados");
  const navCategorias = document.getElementById("nav-categorias");

  const selectCategoria = document.getElementById("select-categoria");

  // Resalta la categoria tocada en la fila superior (antes quedaba "Todos")
  // y la refleja en el desplegable de celular
  if (navCategorias) {
    navCategorias.addEventListener("click", (e) => {
      const enlace = e.target.closest("a[data-categoria]");
      if (!enlace) return;
      navCategorias.querySelectorAll("a").forEach((a) => a.classList.toggle("activo", a === enlace));
      if (selectCategoria) selectCategoria.value = enlace.dataset.categoria;
    });
  }

  // Desplegable de categorias (celular): se arma con los mismos enlaces que
  // tienda.js pinta en la fila superior y, al elegir, "toca" el enlace
  // correspondiente, asi el filtro funciona exactamente igual.
  if (navCategorias && selectCategoria) {
    const llenarDesplegable = () => {
      const actual = navCategorias.querySelector("a.activo");
      selectCategoria.replaceChildren(...[...navCategorias.querySelectorAll("a[data-categoria]")].map((a) => {
        const opcion = document.createElement("option");
        opcion.value = a.dataset.categoria;
        opcion.textContent = a.textContent.trim();
        return opcion;
      }));
      if (actual) selectCategoria.value = actual.dataset.categoria;
    };
    llenarDesplegable();
    new MutationObserver(llenarDesplegable).observe(navCategorias, { childList: true });
    selectCategoria.addEventListener("change", () => {
      const enlace = navCategorias.querySelector(`a[data-categoria="${CSS.escape(selectCategoria.value)}"]`);
      if (enlace) enlace.click();
    });
  }

  if (!panel || !fondo || !botonAbrir) return;

  const actualizarContador = () => {
    const activos = [precioMinimo, precioMaximo].filter((campo) => campo && campo.value !== "").length +
      (soloDestacados && soloDestacados.checked ? 1 : 0);
    contador.hidden = activos === 0;
    contador.textContent = activos;
  };

  const abrirPanel = () => {
    panel.classList.add("abierto");
    fondo.hidden = false;
    botonAbrir.setAttribute("aria-expanded", "true");
    document.body.style.overflow = "hidden";
    botonCerrar.focus({ preventScroll: true });
  };

  const cerrarPanel = () => {
    if (!panel.classList.contains("abierto")) return;
    panel.classList.remove("abierto");
    fondo.hidden = true;
    botonAbrir.setAttribute("aria-expanded", "false");
    document.body.style.overflow = "";
    actualizarContador();
    botonAbrir.focus({ preventScroll: true });
  };

  // Aplica ya (sin esperar la pausa de 700 ms de tienda.js) y cierra
  const aplicarYCerrar = () => {
    if (typeof timeoutPrecio !== "undefined") clearTimeout(timeoutPrecio);
    aplicarFiltros();
    cerrarPanel();
  };

  botonAbrir.addEventListener("click", abrirPanel);
  botonCerrar.addEventListener("click", cerrarPanel);
  fondo.addEventListener("click", cerrarPanel);
  botonVer.addEventListener("click", aplicarYCerrar);
  botonLimpiar.addEventListener("click", () => {
    if (precioMinimo) precioMinimo.value = "";
    if (precioMaximo) precioMaximo.value = "";
    if (soloDestacados) soloDestacados.checked = false;
    aplicarYCerrar();
  });
  [precioMinimo, precioMaximo].forEach((campo) => {
    if (campo) campo.addEventListener("keydown", (e) => { if (e.key === "Enter") aplicarYCerrar(); });
  });
  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape") cerrarPanel();
  });

  // Si la pantalla pasa a tamano escritorio con el panel abierto, se cierra
  window.matchMedia("(min-width: 768px)").addEventListener("change", (e) => {
    if (e.matches) cerrarPanel();
  });
})();
