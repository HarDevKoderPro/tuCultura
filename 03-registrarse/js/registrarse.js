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

// -----------------------------------------------------------------
// PROGRAMA PRINCIPAL
// -----------------------------------------------------------------

// Acciones al presionar botón de envío de datos
btnRegistrar.addEventListener("click", () => {
  // Valido inputs vacíos
  if (Libreria.hayInputsVacios()) {
    Libreria.sweetAlert("error", "Faltan Datos...", "0.8em");
  } else {
    // Si los datos estan completos, los extraigo
    let datosUsuario = Libreria.obtenerDatosInputs();
    if (
      // Verifico que tengan formato valido de Email
      Libreria.emailValido(datosUsuario.email) &&
      Libreria.emailValido(datosUsuario.referente)
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
                setTimeout(() => {
                  Libreria.redireccionarA("../index.html");
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
