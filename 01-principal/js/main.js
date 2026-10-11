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
// CARRUSEL DE IMAGENES
// --------------------------------------------------------------------------
// Referenciar elementos del DOM
const btnLeft = document.querySelector(".btn-left");
const btnRight = document.querySelector(".btn-right");
const carruseles = document.querySelector(".carruseles");
let posicion = 0;
let transitionTime;

// Efecto deslizante
const transicion = (posicion, transitionTime) => {
  carruseles.style.transform = `translate(-${posicion}%)`;
  carruseles.style.transition = `all ease ${transitionTime}`;
};

// Desplazamiento hacia la derecha (>)
const clicBotonDerecho = () => {
  [posicion, transitionTime] =
    posicion < 80 ? [(posicion += 20), "1s"] : [(posicion += -80), ".0s"];
  transicion(posicion, transitionTime);
};

// Desplazamiento hacia la izquierda (<)
const clicBotonIzquierdo = () => {
  [posicion, transitionTime] =
    posicion === 0 ? [(posicion -= -80), "0s"] : [(posicion -= 20), "1s"];
  transicion(posicion, transitionTime);
};

// ------------------------------------------------------------------------
// PROGRAMA PRINCIPAL
// ------------------------------------------------------------------------
btnLeft.addEventListener("click", clicBotonIzquierdo);
btnRight.addEventListener("click", clicBotonDerecho);
setInterval(clicBotonDerecho, 2500);