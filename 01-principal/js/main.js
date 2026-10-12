'use strict';
// ---------------------------------------------------------------------
// MENU PRINCIPAL
// ---------------------------------------------------------------------
// Efecto toggle para mostrar-ocultar el menu movil
$(document).ready(main);
var contador = 1;
function main() {
  $(".menu_bar span").click(function (e) {
    e.preventDefault(); //Se evita que regrese al inicio al presionar hamburguesa
    if (contador == 1) {
      $("nav").animate({
        left: "0",
      });
      contador = 0;
    } else {
      contador = 1;
      $("nav").animate({
        left: "-100%",
      });
    }
  });

  // Ocultar el panel de navegación al hacer clic sobre los enlaces del menu
  $("header nav ul li a").click(function () {
    contador = 1;
    $("nav").animate({
      left: "-100%",
    });
  });
}

// --------------------------------------------------------------------------
// DESPLAZAMIENTO SUAVE DEL MENU
// --------------------------------------------------------------------------
// Se anima por JS para que funcione igual en todos los navegadores y se
// descuenta la altura de la cabecera fija. Al terminar se corrige la posicion
// por si alguna imagen diferida cambio el alto de la pagina durante el recorrido.
const cabecera = document.querySelector(".header");

const posicionDestino = (destino) => {
  const altoCabecera = cabecera ? cabecera.offsetHeight : 0;
  const maximo = document.documentElement.scrollHeight - window.innerHeight;
  const y = destino.getBoundingClientRect().top + window.scrollY - altoCabecera;
  return Math.max(0, Math.min(y, maximo));
};

// "instant" evita que el scroll-behavior: smooth del CSS frene cada cuadro
const irA = (y) => window.scrollTo({ top: y, left: 0, behavior: "instant" });

const desplazarA = (destino, duracion = 700) => {
  const inicio = window.scrollY;
  const t0 = performance.now();
  const suavizado = (t) => (t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2);

  const paso = (ahora) => {
    const t = Math.min((ahora - t0) / duracion, 1);
    // El destino se recalcula en cada cuadro por si la pagina cambia de alto
    irA(inicio + (posicionDestino(destino) - inicio) * suavizado(t));
    if (t < 1) {
      requestAnimationFrame(paso);
    } else {
      setTimeout(() => irA(posicionDestino(destino)), 150);
    }
  };
  requestAnimationFrame(paso);
};

document.querySelectorAll('header nav a[href^="#"]').forEach((enlace) => {
  enlace.addEventListener("click", (e) => {
    const id = enlace.getAttribute("href");
    const destino = id.length > 1 ? document.querySelector(id) : null;
    if (!destino) return;
    e.preventDefault();
    history.pushState(null, "", id);
    desplazarA(destino);
  });
});

// --------------------------------------------------------------------------
// SERVICE WORKER
// --------------------------------------------------------------------------
// Verificar Existencia de Service worker en el navegador
if ("serviceWorker" in navigator) {
  navigator.serviceWorker
  .register("../sw.js")
  .then((reg) => console.log("Registro de SW exitoso", reg))
  .catch((err) => console.warn("Error al tratar de registrar el sw", err));
}

// --------------------------------------------------------------------------
// CARRUSEL DE INICIO (libro + productos destacados, bucle continuo)
// --------------------------------------------------------------------------
// Se clona la ultima diapositiva al inicio y la primera al final. Al llegar a
// un clon se salta sin animacion a la diapositiva real equivalente, asi el
// paso de la ultima a la primera se ve como un desplazamiento mas.
const contenedorCarrusel = document.querySelector(".container-carousel");
const carruseles = document.querySelector(".carruseles");
const btnLeft = document.querySelector(".btn-left");
const btnRight = document.querySelector(".btn-right");

const crearElemento = (etiqueta, clase, texto) => {
  const elemento = document.createElement(etiqueta);
  if (clase) elemento.className = clase;
  if (texto !== undefined) elemento.textContent = texto;
  return elemento;
};

// Los nombres llegan con entidades HTML (se sanitizan al guardar en el admin)
const decodificarHtml = (texto) => {
  const area = document.createElement("textarea");
  area.innerHTML = texto || "";
  return area.value;
};

const crearDiapositivaProducto = (p) => {
  const url = `./05-tienda/producto.html?id=${encodeURIComponent(p.id)}`;
  const nombre = decodificarHtml(p.nombre);
  const conDescuento = p.descuento_porcentaje > 0;

  const media = crearElemento("a", "slide-media");
  media.href = url;
  media.tabIndex = -1;
  const img = crearElemento("img");
  img.src = `./05-tienda/imagenes/productos/${encodeURIComponent(p.imagen)}`;
  img.alt = nombre;
  img.draggable = false;
  media.appendChild(img);

  const etiqueta = crearElemento("p", "slide-etiqueta", decodificarHtml(p.categoria_nombre) || "Destacado");
  if (conDescuento) etiqueta.appendChild(crearElemento("span", "slide-insignia", `-${p.descuento_porcentaje}%`));

  const precio = crearElemento("p", "slide-precio");
  if (conDescuento) precio.appendChild(crearElemento("del", "", p.precio_formateado));
  precio.appendChild(crearElemento("strong", "", conDescuento ? p.precio_oferta_formateado : p.precio_formateado));

  const boton = crearElemento("a", "slide-boton", "Ver producto ");
  boton.href = url;
  boton.appendChild(crearElemento("i", "bx bx-right-arrow-alt"));

  const info = crearElemento("div", "slide-info");
  info.append(etiqueta, crearElemento("h3", "slide-titulo", nombre), precio, boton);

  const slide = crearElemento("div", "slide");
  slide.append(media, info);
  const seccion = crearElemento("section", "slider-section");
  seccion.appendChild(slide);
  return seccion;
};

// Agrega al carrusel (despues del libro) los productos destacados de la tienda
const cargarDestacados = async () => {
  try {
    const respuesta = await fetch("./05-tienda/php/productos.php?action=destacados&limite=20");
    const datos = await respuesta.json();
    if (!datos.success) return;
    datos.productos
      .filter((p) => p.imagen)
      .forEach((p) => carruseles.appendChild(crearDiapositivaProducto(p)));
  } catch (error) {
    console.error("No se pudieron cargar los destacados:", error);
  }
};

const iniciarCarrusel = () => {
  const originales = Array.from(carruseles.children);
  const total = originales.length;
  const puntos = contenedorCarrusel.querySelector(".carrusel-puntos");
  const barra = contenedorCarrusel.querySelector(".carrusel-progreso span");

  // Con una sola diapositiva no hay nada que desplazar
  if (total < 2) {
    btnLeft.style.display = "none";
    btnRight.style.display = "none";
    return;
  }

  const reducirMovimiento = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  const duracion = reducirMovimiento ? 0 : 700;
  const intervalo = 4500;
  const curva = "cubic-bezier(0.22, 0.61, 0.36, 1)";

  carruseles.insertBefore(originales[total - 1].cloneNode(true), originales[0]);
  carruseles.appendChild(originales[0].cloneNode(true));
  // Los clones no se leen ni reciben foco
  [carruseles.firstElementChild, carruseles.lastElementChild].forEach((clon) => {
    clon.setAttribute("aria-hidden", "true");
    clon.querySelectorAll("a").forEach((a) => (a.tabIndex = -1));
  });
  const diapositivas = Array.from(carruseles.children);
  carruseles.style.width = `${(total + 2) * 100}%`;
  contenedorCarrusel.classList.add("animado");

  let indice = 1;
  let enMovimiento = false;
  let respaldo = null;
  let transcurrido = 0;

  const mover = (animar, desplazamiento = 0) => {
    carruseles.style.transition = animar && duracion ? `transform ${duracion}ms ${curva}` : "none";
    carruseles.style.transform = `translateX(calc(-${(indice * 100) / (total + 2)}% + ${desplazamiento}px))`;
  };

  // La diapositiva activa anima su contenido; la real que reemplaza a un clon
  // se muestra completa de una vez (sin-animacion) para que no parpadee
  const marcarActiva = (sinAnimacion) => {
    const actual = diapositivas[indice];
    actual.classList.toggle("sin-animacion", sinAnimacion);
    actual.classList.add("activa");
    const real = (indice - 1 + total) % total;
    Array.from(puntos.children).forEach((punto, i) => {
      punto.classList.toggle("activo", i === real);
      punto.setAttribute("aria-current", i === real ? "true" : "false");
    });
  };

  const alTerminar = () => {
    if (!enMovimiento) return;
    enMovimiento = false;
    clearTimeout(respaldo);
    if (indice === total + 1 || indice === 0) {
      indice = indice === 0 ? total : 1;
      mover(false);
      marcarActiva(true);
    }
    diapositivas.forEach((d, i) => {
      if (i !== indice) d.classList.remove("activa", "sin-animacion");
    });
  };

  carruseles.addEventListener("transitionend", (e) => {
    if (e.target === carruseles) alTerminar();
  });

  const irA = (destino) => {
    if (enMovimiento || destino === indice) return;
    enMovimiento = true;
    indice = destino;
    mover(true);
    marcarActiva(false);
    transcurrido = 0;
    // Respaldo por si el navegador no emite transitionend
    respaldo = setTimeout(alTerminar, duracion + 100);
  };

  for (let i = 0; i < total; i++) {
    const punto = crearElemento("button");
    punto.type = "button";
    punto.setAttribute("aria-label", `Ir a la diapositiva ${i + 1}`);
    punto.addEventListener("click", () => irA(i + 1));
    puntos.appendChild(punto);
  }

  btnRight.addEventListener("click", () => irA(indice + 1));
  btnLeft.addEventListener("click", () => irA(indice - 1));
  contenedorCarrusel.addEventListener("keydown", (e) => {
    if (e.key === "ArrowRight") irA(indice + 1);
    if (e.key === "ArrowLeft") irA(indice - 1);
  });

  // Avance automatico con barra de progreso. Se pausa con el mouse encima
  // (solo equipos con mouse), con el foco dentro, al arrastrar o con la
  // pestana oculta. No avanza solo si el equipo pide reducir movimiento.
  let pausaMouse = false;
  let pausaFoco = false;
  let arrastrando = false;
  let ultimo = null;

  const ciclo = (ahora) => {
    const activo = !pausaMouse && !pausaFoco && !arrastrando && !enMovimiento && !document.hidden;
    if (ultimo !== null && activo) {
      transcurrido += Math.min(ahora - ultimo, 100);
      if (transcurrido >= intervalo) irA(indice + 1);
    }
    ultimo = ahora;
    barra.style.transform = `scaleX(${Math.min(transcurrido / intervalo, 1)})`;
    requestAnimationFrame(ciclo);
  };

  if (window.matchMedia("(hover: hover)").matches) {
    contenedorCarrusel.addEventListener("mouseenter", () => (pausaMouse = true));
    contenedorCarrusel.addEventListener("mouseleave", () => (pausaMouse = false));
  }
  contenedorCarrusel.addEventListener("focusin", () => (pausaFoco = true));
  contenedorCarrusel.addEventListener("focusout", () => (pausaFoco = false));

  // Deslizar con el dedo (o arrastrar con el mouse)
  let punteroId = null;
  let inicioX = 0;
  let dx = 0;
  let huboArrastre = false;

  contenedorCarrusel.addEventListener("dragstart", (e) => e.preventDefault());
  contenedorCarrusel.addEventListener("pointerdown", (e) => {
    if (e.button !== 0 || enMovimiento || e.target.closest(".btn, .carrusel-puntos")) return;
    punteroId = e.pointerId;
    inicioX = e.clientX;
    dx = 0;
    huboArrastre = false;
    arrastrando = true;
  });
  contenedorCarrusel.addEventListener("pointermove", (e) => {
    if (!arrastrando || e.pointerId !== punteroId) return;
    dx = e.clientX - inicioX;
    if (!huboArrastre && Math.abs(dx) > 8) {
      huboArrastre = true;
      contenedorCarrusel.setPointerCapture(punteroId);
    }
    if (huboArrastre) mover(false, dx);
  });
  const soltar = (e) => {
    if (!arrastrando || e.pointerId !== punteroId) return;
    arrastrando = false;
    if (!huboArrastre) return;
    // El clic que sigue al arrastre se descarta solo en este instante
    setTimeout(() => (huboArrastre = false), 50);
    const umbral = Math.min(80, contenedorCarrusel.offsetWidth * 0.15);
    if (dx <= -umbral) irA(indice + 1);
    else if (dx >= umbral) irA(indice - 1);
    else mover(true);
  };
  contenedorCarrusel.addEventListener("pointerup", soltar);
  contenedorCarrusel.addEventListener("pointercancel", soltar);
  // Un arrastre no debe abrir el enlace sobre el que termino
  contenedorCarrusel.addEventListener("click", (e) => {
    if (huboArrastre) {
      e.preventDefault();
      e.stopPropagation();
    }
  }, true);

  mover(false);
  marcarActiva(false);
  if (!reducirMovimiento) requestAnimationFrame(ciclo);
};

if (contenedorCarrusel && carruseles && btnLeft && btnRight) {
  cargarDestacados().then(iniciarCarrusel);
}
