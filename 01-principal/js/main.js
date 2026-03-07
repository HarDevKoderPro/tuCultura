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