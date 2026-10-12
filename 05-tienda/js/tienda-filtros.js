// =========================================================================
// FILTROS DE LA TIENDA EN CELULAR
// =========================================================================
// En celular (<768px) la barra lateral de filtros se muestra como un panel
// que sube desde abajo, con el rango de precio (categorias y orden ya estan
// en la fila superior y en el selector). En escritorio la barra lateral se ve
// como siempre. Usa aplicarFiltros() y timeoutPrecio de tienda.js.

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
  const navCategorias = document.getElementById("nav-categorias");

  // Resalta la categoria tocada en la fila superior (antes quedaba "Todos")
  if (navCategorias) {
    navCategorias.addEventListener("click", (e) => {
      const enlace = e.target.closest("a[data-categoria]");
      if (!enlace) return;
      navCategorias.querySelectorAll("a").forEach((a) => a.classList.toggle("activo", a === enlace));
    });
  }

  if (!panel || !fondo || !botonAbrir) return;

  const actualizarContador = () => {
    const activos = [precioMinimo, precioMaximo].filter((campo) => campo && campo.value !== "").length;
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
