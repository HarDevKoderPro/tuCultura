"use strict";

// Importo libreria de funciones
import { Libreria } from "../../libreria.js";

// -----------------------------------------------------------------
// REFERENCIAS DEL DOM
// -----------------------------------------------------------------
const getEl = (id) => document.getElementById(id);
const inputNombres = getEl("inputNombres");
const inputApellidos = getEl("inputApellidos");
const inputDocumento = getEl("inputDocumento");
const inputTelefono = getEl("inputTelefono");
const inputEmail = getEl("inputEmail");
const inputPass = getEl("inputPass");
const inputReferente = getEl("inputReferente");
const btnRegistrar = getEl("btnRegistrar");
const invitacionRegistro = getEl("invitacionRegistro");
const labelReferente = getEl("labelReferente");

// -----------------------------------------------------------------
// INVITACION POR ENLACE (05-tienda/js/invitacion.js)
// -----------------------------------------------------------------
// Si la persona llega desde el enlace de un asociado (?ref= o codigo guardado
// en este navegador), el campo "Referido por" se reemplaza por "Te invito: X".
// Puede cambiarlo con "¿Te invito otra persona?".
const FORMATO_CODIGO = /^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{6}$/;
const paramsUrl = new URLSearchParams(window.location.search);
let codigoInvitacion = "";

function codigoGuardado() {
  try {
    const guardada = JSON.parse(localStorage.getItem("tcp_invitacion") || "null");
    if (guardada && FORMATO_CODIGO.test(guardada.codigo) && Date.now() - guardada.fecha < 30 * 864e5) {
      return guardada.codigo;
    }
  } catch (e) {
    // Sin almacenamiento: registro normal
  }
  return "";
}

async function prepararInvitacion() {
  const codigoUrl = (paramsUrl.get("ref") || "").toUpperCase();
  const codigo = FORMATO_CODIGO.test(codigoUrl) ? codigoUrl : codigoGuardado();
  if (!codigo) return;

  try {
    const res = await fetch(`../05-tienda/php/invitaciones.php?action=resolver&ref=${encodeURIComponent(codigo)}`);
    const data = await res.json();
    if (!data.success || !data.valido) return;

    codigoInvitacion = codigo;
    getEl("invitacionNombre").textContent = data.nombre;
    invitacionRegistro.hidden = false;
    labelReferente.hidden = true;
  } catch (e) {
    // Sin conexion con la tienda: registro normal con email del referente
  }
}

getEl("btnCambiarReferente").addEventListener("click", () => {
  codigoInvitacion = "";
  invitacionRegistro.hidden = true;
  labelReferente.hidden = false;
  inputReferente.focus();
});

// Pagina de la tienda a la que se vuelve tras registrarse (solo rutas conocidas)
function returnUrlTienda() {
  const volver = paramsUrl.get("volver") || "";
  const producto = volver.match(/^\/05-tienda\/producto\.html\?id=(\d+)/);
  if (producto) return `producto.html?id=${producto[1]}`;
  const pagina = volver.match(/^\/05-tienda\/(checkout|carrito|mis-pedidos)\.html/);
  return pagina ? `${pagina[1]}.html` : "";
}

prepararInvitacion();

// -----------------------------------------------------------------
// PROGRAMA PRINCIPAL
// -----------------------------------------------------------------

// Acciones al presionar botón de envío de datos
btnRegistrar.addEventListener("click", () => {
  // Valido inputs vacíos (con invitacion, el campo de referente no se usa)
  const hayVacios = codigoInvitacion
    ? [...document.querySelectorAll("input")].some((input) => input !== inputReferente && input.value === "")
    : Libreria.hayInputsVacios();
  if (hayVacios) {
    Libreria.sweetAlert("error", "Faltan Datos...", "0.8em");
  } else {
    // Si los datos estan completos, los extraigo
    let datosUsuario = Libreria.obtenerDatosInputs();
    if (codigoInvitacion) {
      // El servidor traduce el codigo al email del asociado que invito
      datosUsuario.referente = "";
      datosUsuario.ref = codigoInvitacion;
    }
    if (
      // Verifico que tengan formato valido de Email
      Libreria.emailValido(datosUsuario.email) &&
      (codigoInvitacion || Libreria.emailValido(datosUsuario.referente))
    ) {
      // Consulto que Email y Referente ingresados existan en la base de datos
      Libreria.enviarDatosParaConsultas(
        datosUsuario,
        "php/validarEmails.php",
        (data) => {
          if (data.emailExists && data.referenteExists) {
            Libreria.sweetAlert("error", "Email ya existe!", "0.8em");
          } else if (data.emailExists && !data.referenteExists) {
            Libreria.sweetAlert(
              "error",
              "Email ya existe, Referente no registrado!",
              "0.8em"
            );
          } else if (!data.emailExists && !data.referenteExists) {
            Libreria.sweetAlert("error", "Referente no registrado!", "0.8em");
          } else {
            //Envio datos al servidor para registro (base de datos)
            Libreria.enviarDatosParaConsultas(
              datosUsuario,
              "php/registrarse.php",
              (data) => {
                Libreria.sweetAlert("exito", "Registro Exitoso!", "0.8em");
                console.log(data.respuesta);
                // Viene de la tienda (aviso de invitacion): iniciar sesion y volver al producto
                const volverTienda = paramsUrl.has("volver");
                if (volverTienda) {
                  try { localStorage.removeItem("tcp_invitacion"); } catch (e) { /* sin almacenamiento */ }
                }
                const returnUrl = returnUrlTienda();
                setTimeout(() => {
                  Libreria.redireccionarA(
                    volverTienda
                      ? "../02-iniciarSesion/iniciarSesion.html?origen=tienda" +
                          (returnUrl ? "&returnUrl=" + encodeURIComponent(returnUrl) : "")
                      : "../index.html"
                  );
                }, 1500);
              }
            );
          }
        }
      );
    } else {
      Libreria.sweetAlert("error", "Email no válido", "0.8em");
    }
  }
});
