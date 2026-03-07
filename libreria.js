"use strict";

// Clase de Funciones
export class Libreria {
  // Redireccionar a página
  static redireccionarA(ruta) {
    window.location.href = ruta;
  }

  // Validar Inputs Vacíos
  static hayInputsVacios() {
    const inputsArr = document.querySelectorAll("input");
    return [...inputsArr].some((input) => input.value === "");
  }

  // Validar Email
  static emailValido(email) {
    let regex = /^\w+@\w+\.\w+$/gi;
    return regex.test(email) ? true : false;
  }

  // Borrar Inputs
  static borrarInputs() {
    document.querySelectorAll("input").forEach((input) => (input.value = ""));
  }

  // Obtener datos de los inputs
  static obtenerDatosInputs() {
    // Obtener fecha local del usuario en formato YYYY-MM-DD
    const hoy = new Date();
    const fechaLocal =
      hoy.getFullYear() +
      "-" +
      String(hoy.getMonth() + 1).padStart(2, "0") +
      "-" +
      String(hoy.getDate()).padStart(2, "0");

    // Armo un objeto con los datos a enviar al servidor
    let datosUsuario = {
      nombres: this.colocarMayusculaInicial(inputNombres.value),
      apellidos: this.colocarMayusculaInicial(inputApellidos.value),
      documento: inputDocumento.value,
      telefono: inputTelefono.value,
      email: inputEmail.value.toLowerCase(),
      pass: inputPass.value,
      referente: inputReferente.value.toLowerCase(),
      fecha: fechaLocal, // 👈 ahora se guarda la fecha local del navegador
    };

    return datosUsuario;
  }

  // Colocar mayúscula Inicial a palabras de Texto
  static colocarMayusculaInicial = (texto) => {
    // Capitalizo letra inicial de cada palabra del texto
    let resultado = "";
    texto.split(" ").forEach((palabra) => {
      resultado += `${palabra.charAt(0).toUpperCase()}${palabra
        .substring(1)
        .toLowerCase()} `;
    });

    return resultado;
  };

  // SweetAlert General
  static sweetAlert(tipo, message, fontSize) {
    // variables a ser modificadas
    let icon, background, iconColor, color;
    // Asigno estilos de acuerdo al tipo de alerta
    if (tipo === "exito") {
      icon = "success";
      background = "#ABEBC6";
      iconColor = "green";
      color = "green";
    } else if (tipo === "error") {
      icon = "error";
      background = "#E6B0AA";
      iconColor = "red";
      color = "red";
    }
    // Cuerpo general del mensaje
    Swal.fire({
      position: "center",
      width: "250px",
      heightAuto: false,
      showConfirmButton: false,
      background: background,
      icon: icon,
      iconColor: iconColor,
      color: color,
      timer: 1300,
      // Personaliza el tamaño del mensaje
      html: `<div style="font-size: ${fontSize}; text-align: center; font-weight:bold">${message}</div>`,
    });
  }

  // SweetAlert de Confirmación
  static sweetAlertConfirmacion(mensaje, tamanoTitulo, callback) {
    Swal.fire({
      title: mensaje,
      position: "center",
      icon: "warning",
      iconColor: "#F39C12",
      background: "#F9E79F",
      width: "300px",
      heightAuto: false,
      showCancelButton: true,
      confirmButtonColor: "#3085d6",
      cancelButtonColor: "#d33",
      confirmButtonText: "Aceptar",
      cancelButtonText: "Cancelar",
      // Modificar el estilo del título directamente
      didOpen: () => {
        const title = document.querySelector(".swal2-title");
        if (title) {
          title.style.fontSize = tamanoTitulo; // Ajustar el tamaño del título
        }
      },
    }).then((result) => {
      if (result.isConfirmed) {
        callback(true);
      } else {
        callback(false);
      }
    });
  }

  // Envío de datos para consultas
  static enviarDatosParaConsultas(datosAEnviar, rutaPhp, callback) {
    // Envio de datos Js a variables PHP con fetch
    // Ruta con respecto al archivo html que se este utilizando
    // El callback regresa la respuesta del server para ejecutar acciones
    fetch(rutaPhp, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
      },
      body: JSON.stringify(datosAEnviar),
    })
      // Respuesta desde archivo PHP (mensaje)
      .then((response) => response.json())
      .then((data) => {
        // Respuesta en consola para pruebas (opcional)
        console.log("Respuesta PHP: ", data);
        // Ejecutar el callback con los datos recibidos
        callback(data);
      })

      // Captura de errores
      .catch((error) => console.error("Error:", error));
  }
}
