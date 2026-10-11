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
// CARRUSEL DE IMAGENES (bucle continuo)
// --------------------------------------------------------------------------
// Se clona la ultima imagen al inicio y la primera al final. Al llegar a un
// clon se salta sin animacion a la imagen real equivalente, asi el paso de la
// ultima a la primera se ve como un desplazamiento mas, sin reinicio visible.
const btnLeft = document.querySelector(".btn-left");
const btnRight = document.querySelector(".btn-right");
const carruseles = document.querySelector(".carruseles");
const contenedorCarrusel = document.querySelector(".container-carousel");

if (carruseles && btnLeft && btnRight) {
  const originales = Array.from(carruseles.children);
  const total = originales.length;
  const duracion = 1000;
  const intervalo = 2500;

  carruseles.insertBefore(originales[total - 1].cloneNode(true), originales[0]);
  carruseles.appendChild(originales[0].cloneNode(true));
  carruseles.style.width = `${(total + 2) * 100}%`;

  let indice = 1;
  let enMovimiento = false;
  let temporizador = null;

  const mover = (animar) => {
    carruseles.style.transition = animar ? `transform ${duracion}ms ease` : "none";
    carruseles.style.transform = `translateX(-${(indice * 100) / (total + 2)}%)`;
  };

  // Al terminar sobre un clon, saltar sin animacion a la imagen real
  const corregirClon = () => {
    enMovimiento = false;
    if (indice === total + 1) indice = 1;
    if (indice === 0) indice = total;
    mover(false);
  };

  carruseles.addEventListener("transitionend", (e) => {
    if (e.target === carruseles) corregirClon();
  });

  const avanzar = (direccion) => {
    if (enMovimiento) return;
    enMovimiento = true;
    indice += direccion;
    mover(true);
    // Respaldo por si el navegador no emite transitionend (pestana oculta)
    setTimeout(() => { if (enMovimiento) corregirClon(); }, duracion + 100);
  };

  const iniciarAuto = () => {
    clearInterval(temporizador);
    temporizador = setInterval(() => avanzar(1), intervalo);
  };
  const detenerAuto = () => clearInterval(temporizador);

  btnRight.addEventListener("click", () => { avanzar(1); iniciarAuto(); });
  btnLeft.addEventListener("click", () => { avanzar(-1); iniciarAuto(); });

  // Pausa al pasar el mouse (solo equipos con mouse) y con la pestana oculta
  if (contenedorCarrusel && window.matchMedia("(hover: hover)").matches) {
    contenedorCarrusel.addEventListener("mouseenter", detenerAuto);
    contenedorCarrusel.addEventListener("mouseleave", iniciarAuto);
  }
  document.addEventListener("visibilitychange", () => {
    document.hidden ? detenerAuto() : iniciarAuto();
  });

  mover(false);
  iniciarAuto();
}
