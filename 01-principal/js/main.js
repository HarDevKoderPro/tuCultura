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
// CARRUSEL DE INICIO (libro + productos destacados)
// --------------------------------------------------------------------------
// Las diapositivas estan apiladas y cambian con un desvanecimiento cruzado.
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
  // La foto no se descarga aqui: data-src se pasa a src cuando hace falta
  // (cargarFotoDiapositiva), para no competir con la carga inicial de la pagina
  const img = crearElemento("img");
  img.dataset.src = `./05-tienda/imagenes/productos/${encodeURIComponent(p.imagen)}`;
  img.alt = nombre;
  img.draggable = false;
  img.decoding = "async";
  img.setAttribute("fetchpriority", "low");
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

// Descarga la foto de una diapositiva (si aun no la tiene); resuelve al terminar
const cargarFotoDiapositiva = (diapositiva) => {
  const img = diapositiva && diapositiva.querySelector("img[data-src]");
  if (!img) return Promise.resolve();
  return new Promise((resolver) => {
    img.addEventListener("load", resolver, { once: true });
    img.addEventListener("error", resolver, { once: true });
    img.src = img.dataset.src;
    img.removeAttribute("data-src");
  });
};

// Cuando la pagina termino de cargar, baja las fotos de una en una, en orden
const precargarFotosCarrusel = (diapositivas) => {
  const empezar = async () => {
    for (const diapositiva of diapositivas) await cargarFotoDiapositiva(diapositiva);
  };
  if (document.readyState === "complete") empezar();
  else window.addEventListener("load", empezar, { once: true });
};

const iniciarCarrusel = () => {
  const diapositivas = Array.from(carruseles.children);
  const total = diapositivas.length;
  const puntos = contenedorCarrusel.querySelector(".carrusel-puntos");
  const barra = contenedorCarrusel.querySelector(".carrusel-progreso span");

  // Con una sola diapositiva no hay nada que cambiar
  if (total < 2) {
    btnLeft.style.display = "none";
    btnRight.style.display = "none";
    return;
  }

  const intervalo = 5500;
  let indice = 0;
  let transcurrido = 0;

  // La diapositiva activa se funde sobre la anterior y anima su contenido (CSS)
  const mostrar = (destino) => {
    indice = (destino + total) % total;
    // Si se llega antes de la precarga (flecha, punto), se pide de inmediato
    // la foto actual y la siguiente
    cargarFotoDiapositiva(diapositivas[indice]);
    if (document.readyState === "complete") cargarFotoDiapositiva(diapositivas[(indice + 1) % total]);
    diapositivas.forEach((d, i) => {
      d.classList.toggle("activa", i === indice);
      d.setAttribute("aria-hidden", i === indice ? "false" : "true");
    });
    Array.from(puntos.children).forEach((punto, i) => {
      punto.classList.toggle("activo", i === indice);
      punto.setAttribute("aria-current", i === indice ? "true" : "false");
    });
    transcurrido = 0;
  };

  for (let i = 0; i < total; i++) {
    const punto = crearElemento("button");
    punto.type = "button";
    punto.setAttribute("aria-label", `Ir a la diapositiva ${i + 1}`);
    punto.addEventListener("click", () => mostrar(i));
    puntos.appendChild(punto);
  }

  btnRight.addEventListener("click", () => mostrar(indice + 1));
  btnLeft.addEventListener("click", () => mostrar(indice - 1));
  contenedorCarrusel.addEventListener("keydown", (e) => {
    if (e.key === "ArrowRight") mostrar(indice + 1);
    if (e.key === "ArrowLeft") mostrar(indice - 1);
  });

  // Avance automatico con barra de progreso. Se pausa con el mouse encima
  // (solo equipos con mouse), con el foco dentro, al arrastrar o con la
  // pestana oculta.
  let pausaMouse = false;
  let pausaFoco = false;
  let arrastrando = false;
  let ultimo = null;

  const ciclo = (ahora) => {
    const activo = !pausaMouse && !pausaFoco && !arrastrando && !document.hidden;
    if (ultimo !== null && activo) {
      transcurrido += Math.min(ahora - ultimo, 100);
      if (transcurrido >= intervalo) mostrar(indice + 1);
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

  // Deslizar con el dedo (o arrastrar con el mouse) cambia de diapositiva
  let punteroId = null;
  let inicioX = 0;
  let huboArrastre = false;

  contenedorCarrusel.addEventListener("dragstart", (e) => e.preventDefault());
  contenedorCarrusel.addEventListener("pointerdown", (e) => {
    if (e.button !== 0 || e.target.closest(".btn, .carrusel-puntos")) return;
    punteroId = e.pointerId;
    inicioX = e.clientX;
    huboArrastre = false;
    arrastrando = true;
  });
  contenedorCarrusel.addEventListener("pointermove", (e) => {
    if (!arrastrando || e.pointerId !== punteroId || huboArrastre) return;
    if (Math.abs(e.clientX - inicioX) > 8) {
      huboArrastre = true;
      contenedorCarrusel.setPointerCapture(punteroId);
    }
  });
  const soltar = (e) => {
    if (!arrastrando || e.pointerId !== punteroId) return;
    arrastrando = false;
    if (!huboArrastre) return;
    // El clic que sigue al arrastre se descarta solo en este instante
    setTimeout(() => (huboArrastre = false), 50);
    const dx = e.clientX - inicioX;
    const umbral = Math.min(60, contenedorCarrusel.offsetWidth * 0.12);
    if (dx <= -umbral) mostrar(indice + 1);
    else if (dx >= umbral) mostrar(indice - 1);
  };
  contenedorCarrusel.addEventListener("pointerup", soltar);
  // Si el navegador toma el gesto (desplazamiento vertical), no se cambia
  contenedorCarrusel.addEventListener("pointercancel", (e) => {
    if (e.pointerId !== punteroId) return;
    arrastrando = false;
    huboArrastre = false;
  });
  // Un arrastre no debe abrir el enlace sobre el que termino
  contenedorCarrusel.addEventListener("click", (e) => {
    if (huboArrastre) {
      e.preventDefault();
      e.stopPropagation();
    }
  }, true);

  contenedorCarrusel.classList.add("animado");
  mostrar(0);
  requestAnimationFrame(ciclo);
  precargarFotosCarrusel(diapositivas);
};

if (contenedorCarrusel && carruseles && btnLeft && btnRight) {
  cargarDestacados().then(iniciarCarrusel);
}
