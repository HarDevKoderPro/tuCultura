"use strict";
/**
 * Registro de asociados.
 * Mismo contrato con el servidor de siempre:
 * - php/validarEmails.php  POST JSON {email, referente[, ref]} -> {emailExists, referenteExists}
 * - php/registrarse.php    POST JSON {nombres, apellidos, documento, telefono, email, pass,
 *                                     referente, fecha[, ref]} -> {respuesta}
 * Con invitacion por enlace (?ref= o codigo guardado por 05-tienda/js/invitacion.js) se
 * envia referente '' y ref = codigo; el servidor lo traduce al email del asociado.
 * Los errores se muestran bajo cada campo o en un aviso dentro del formulario.
 */
(function () {
  const getEl = (id) => document.getElementById(id);
  const EMAIL_VALIDO = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
  const FORMATO_CODIGO = /^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{6}$/;
  const REGISTRO_OK = "Datos enviados exitosamente!";

  const form = getEl("formRegistro");
  const inputNombres = getEl("inputNombres");
  const inputApellidos = getEl("inputApellidos");
  const inputDocumento = getEl("inputDocumento");
  const inputTelefono = getEl("inputTelefono");
  const inputEmail = getEl("inputEmail");
  const inputPass = getEl("inputPass");
  const inputReferente = getEl("inputReferente");
  const btnRegistrar = getEl("btnRegistrar");
  const invitacionRegistro = getEl("invitacionRegistro");
  const campoReferente = getEl("labelReferente");
  const paramsUrl = new URLSearchParams(window.location.search);
  let codigoInvitacion = "";

  // ---------- utilidades ----------
  function marcarError(input, mensaje) {
    const campo = input.closest(".campo");
    if (!campo) return;
    campo.classList.toggle("invalido", !!mensaje);
    campo.querySelector(".campo-error").textContent = mensaje || "";
    input.setAttribute("aria-invalid", mensaje ? "true" : "false");
  }

  function mostrarAviso(mensaje) {
    const aviso = getEl("avisoRegistro");
    getEl("avisoRegistroTexto").textContent = mensaje || "";
    aviso.classList.toggle("visible", !!mensaje);
  }

  async function postJson(url, datos) {
    const respuesta = await fetch(url, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(datos),
    });
    return respuesta.json();
  }

  /** Mayuscula inicial en cada palabra (como antes en libreria.js) */
  function capitalizar(texto) {
    return texto.trim().split(/\s+/)
      .map((p) => p.charAt(0).toUpperCase() + p.substring(1).toLowerCase())
      .join(" ");
  }

  function fechaLocal() {
    const hoy = new Date();
    return `${hoy.getFullYear()}-${String(hoy.getMonth() + 1).padStart(2, "0")}-${String(hoy.getDate()).padStart(2, "0")}`;
  }

  // Limpiar error al escribir
  form.querySelectorAll("input").forEach((input) => {
    input.addEventListener("input", () => { marcarError(input, ""); mostrarAviso(""); });
  });

  // Documento y telefono: solo numeros
  [inputDocumento, inputTelefono].forEach((input) => {
    input.addEventListener("input", () => { input.value = input.value.replace(/\D/g, ""); });
  });

  // Mostrar / ocultar contrasena
  document.querySelectorAll(".btn-ojo").forEach((boton) => {
    boton.addEventListener("click", () => {
      const input = getEl(boton.dataset.ojo);
      const mostrar = input.type === "password";
      input.type = mostrar ? "text" : "password";
      boton.setAttribute("aria-pressed", mostrar ? "true" : "false");
      boton.setAttribute("aria-label", mostrar ? "Ocultar contraseña" : "Mostrar contraseña");
      input.focus();
    });
  });

  // Medidor de seguridad de la contrasena
  const medidor = getEl("medidorPass");
  const medidorTexto = getEl("medidorTexto");
  inputPass.addEventListener("input", () => {
    const v = inputPass.value;
    let nivel = 0;
    if (v.length >= 6) nivel = 1;
    if (v.length >= 8 && /[a-zA-Z]/.test(v) && /\d/.test(v)) nivel = 2;
    if (v.length >= 10 && /[a-z]/.test(v) && /[A-Z]/.test(v) && /\d/.test(v)) nivel = 3;
    if (nivel === 3 && /[^a-zA-Z0-9]/.test(v)) nivel = 4;
    medidor.dataset.nivel = v ? String(Math.max(nivel, 1)) : "0";
    medidorTexto.textContent = !v ? "Mínimo 6 caracteres."
      : v.length < 6 ? `Muy corta: faltan ${6 - v.length} caracteres.`
      : ["", "Aceptable", "Buena", "Muy buena", "Excelente"][nivel];
  });

  // Validacion al salir de los correos
  [inputEmail, inputReferente].forEach((input) => {
    input.addEventListener("blur", () => {
      const v = input.value.trim();
      if (v && !EMAIL_VALIDO.test(v)) marcarError(input, "Revisa el correo: parece incompleto.");
    });
  });

  // ---------- invitacion por enlace ----------
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
      getEl("invitacionInicial").textContent = (data.nombre || "?").trim().charAt(0).toUpperCase();
      invitacionRegistro.hidden = false;
      campoReferente.hidden = true;
    } catch (e) {
      // Sin conexion con la tienda: registro normal con email del referente
    }
  }

  getEl("btnCambiarReferente").addEventListener("click", () => {
    codigoInvitacion = "";
    invitacionRegistro.hidden = true;
    campoReferente.hidden = false;
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

  function urlLogin() {
    if (!paramsUrl.has("volver")) return "../02-iniciarSesion/iniciarSesion.html";
    const returnUrl = returnUrlTienda();
    return "../02-iniciarSesion/iniciarSesion.html?origen=tienda" +
      (returnUrl ? "&returnUrl=" + encodeURIComponent(returnUrl) : "");
  }

  // "Inicia sesion" conserva el regreso a la tienda
  getEl("linkIniciarSesion").href = urlLogin();
  getEl("btnExitoContinuar").href = urlLogin();

  prepararInvitacion();

  // ---------- validacion y envio ----------
  function validar() {
    const reglas = [
      [inputNombres, (v) => !v ? "Escribe tu nombre." : ""],
      [inputApellidos, (v) => !v ? "Escribe tu apellido." : ""],
      [inputDocumento, (v) => !v ? "Escribe tu número de documento." : v.length < 5 ? "El documento parece incompleto." : ""],
      [inputTelefono, (v) => !v ? "Escribe tu teléfono." : v.length < 7 ? "El teléfono parece incompleto." : ""],
      [inputEmail, (v) => !v ? "Escribe tu correo electrónico." : !EMAIL_VALIDO.test(v) ? "Revisa el correo: parece incompleto." : ""],
      [inputPass, (v) => !v ? "Crea una contraseña." : v.length < 6 ? "Usa al menos 6 caracteres." : ""],
    ];
    if (!codigoInvitacion) {
      reglas.push([inputReferente, (v) => !v ? "Escribe el correo de quien te invitó." : !EMAIL_VALIDO.test(v) ? "Revisa el correo: parece incompleto." : ""]);
    }
    let primero = null;
    reglas.forEach(([input, regla]) => {
      const mensaje = regla(input === inputPass ? input.value : input.value.trim());
      marcarError(input, mensaje);
      if (mensaje && !primero) primero = input;
    });
    if (primero) primero.focus();
    return !primero;
  }

  function cargando(texto) {
    const original = btnRegistrar.innerHTML;
    btnRegistrar.disabled = true;
    btnRegistrar.innerHTML = `<span class="girando" aria-hidden="true"></span>${texto}`;
    return () => { btnRegistrar.disabled = false; btnRegistrar.innerHTML = original; };
  }

  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    mostrarAviso("");
    if (!validar()) return;

    const datosUsuario = {
      nombres: capitalizar(inputNombres.value),
      apellidos: capitalizar(inputApellidos.value),
      documento: inputDocumento.value.trim(),
      telefono: inputTelefono.value.trim(),
      email: inputEmail.value.trim().toLowerCase(),
      pass: inputPass.value,
      referente: codigoInvitacion ? "" : inputReferente.value.trim().toLowerCase(),
      fecha: fechaLocal(),
    };
    if (codigoInvitacion) datosUsuario.ref = codigoInvitacion;

    const restaurar = cargando("Creando tu cuenta…");
    try {
      // 1. El correo no debe existir y el referente si
      const val = await postJson("php/validarEmails.php", datosUsuario);
      if (val.emailExists || !val.referenteExists) {
        restaurar();
        if (val.emailExists) {
          marcarError(inputEmail, "Este correo ya está registrado. Si es tuyo, inicia sesión.");
        }
        if (!val.referenteExists) {
          if (codigoInvitacion) {
            mostrarAviso("No pudimos confirmar la invitación. Escribe el correo de quien te invitó.");
            getEl("btnCambiarReferente").click();
          } else {
            marcarError(inputReferente, "No encontramos a nadie registrado con ese correo.");
          }
        }
        (val.emailExists ? inputEmail : inputReferente).focus();
        return;
      }

      // 2. Registro
      const reg = await postJson("php/registrarse.php", datosUsuario);
      if (reg.respuesta !== REGISTRO_OK) {
        restaurar();
        mostrarAviso(reg.respuesta === "Referente no registrado"
          ? "No pudimos confirmar quién te invitó. Revisa ese dato."
          : "No pudimos crear la cuenta. Inténtalo de nuevo en un momento.");
        return;
      }

      // 3. Listo
      const volverTienda = paramsUrl.has("volver");
      if (volverTienda) {
        try { localStorage.removeItem("tcp_invitacion"); } catch (err) { /* sin almacenamiento */ }
      }
      getEl("vistaRegistro").hidden = true;
      getEl("vistaExito").hidden = false;
      getEl("exitoTexto").textContent = volverTienda
        ? "Ahora inicia sesión y te llevamos de vuelta a la tienda."
        : "Ya puedes iniciar sesión con tu correo y tu contraseña.";
      window.scrollTo({ top: 0 });
      // Desde la tienda se continua solo (como antes)
      if (volverTienda) setTimeout(() => { window.location.href = urlLogin(); }, 2500);
    } catch (error) {
      restaurar();
      mostrarAviso("No pudimos conectar con el servidor. Revisa tu conexión e inténtalo de nuevo.");
    }
  });
})();
