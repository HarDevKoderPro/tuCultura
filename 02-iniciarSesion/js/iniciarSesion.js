/**
 * Iniciar sesion y recuperar contrasena.
 * Mismo contrato con el servidor de siempre:
 * - php/iniciarSesion.php  POST JSON {email, pass, origen, returnUrl} -> {respuesta, redirectUrl}
 * - php/recuperarPass.php  POST JSON {email} | {email, codigo} | {email, nuevaPass} -> {respuesta, message}
 * Los errores se muestran bajo cada campo o en un aviso dentro del formulario.
 */
document.addEventListener("DOMContentLoaded", () => {
  const EMAIL_VALIDO = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

  const formLogin = document.getElementById("formLogin");
  const inputEmail = document.getElementById("inputEmail");
  const inputPass = document.getElementById("inputPass");
  const btnLogin = document.getElementById("btnIniciarSesion");
  const linkOlvido = document.getElementById("linkOlvidastePass");
  if (!formLogin || !inputEmail || !inputPass || !btnLogin) return;

  // ---------- utilidades ----------
  function campoDe(input) { return input.closest(".campo"); }

  function marcarError(input, mensaje) {
    const campo = campoDe(input);
    if (!campo) return;
    campo.classList.toggle("invalido", !!mensaje);
    const error = campo.querySelector(".campo-error");
    if (error) error.textContent = mensaje || "";
    input.setAttribute("aria-invalid", mensaje ? "true" : "false");
  }

  function limpiarAlEscribir(input) {
    input.addEventListener("input", () => marcarError(input, ""));
  }

  function mostrarAviso(id, mensaje, tipo = "error") {
    const aviso = document.getElementById(id);
    if (!aviso) return;
    aviso.classList.remove("error", "exito");
    aviso.classList.add(tipo);
    const icono = aviso.querySelector("svg");
    if (icono) {
      icono.innerHTML = tipo === "exito"
        ? '<circle cx="12" cy="12" r="10"/><path d="m8 12 3 3 5-6"/>'
        : '<circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/>';
    }
    aviso.querySelector("span").textContent = mensaje || "";
    aviso.classList.toggle("visible", !!mensaje);
  }

  /** Pone el boton en "cargando" y devuelve una funcion para restaurarlo. */
  function cargando(boton, texto) {
    const original = boton.innerHTML;
    boton.disabled = true;
    boton.innerHTML = `<span class="girando" aria-hidden="true"></span>${texto}`;
    return () => { boton.disabled = false; boton.innerHTML = original; };
  }

  async function postJson(url, datos) {
    const respuesta = await fetch(url, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(datos),
    });
    return respuesta.json();
  }

  // Mostrar / ocultar contrasena
  document.querySelectorAll(".btn-ojo").forEach((boton) => {
    boton.addEventListener("click", () => {
      const input = document.getElementById(boton.dataset.ojo);
      if (!input) return;
      const mostrar = input.type === "password";
      input.type = mostrar ? "text" : "password";
      boton.setAttribute("aria-pressed", mostrar ? "true" : "false");
      boton.setAttribute("aria-label", mostrar ? "Ocultar contraseña" : "Mostrar contraseña");
      input.focus();
    });
  });

  // ---------- iniciar sesion ----------
  [inputEmail, inputPass].forEach(limpiarAlEscribir);
  [inputEmail, inputPass].forEach((el) => el.addEventListener("input", () => mostrarAviso("avisoLogin", "")));
  inputEmail.addEventListener("blur", () => {
    const email = inputEmail.value.trim();
    if (email && !EMAIL_VALIDO.test(email)) marcarError(inputEmail, "Revisa el correo: parece incompleto.");
  });

  formLogin.addEventListener("submit", async (e) => {
    e.preventDefault();
    mostrarAviso("avisoLogin", "");
    const email = inputEmail.value.trim();
    const pass = inputPass.value.trim();

    let valido = true;
    if (!email) { marcarError(inputEmail, "Escribe tu correo electrónico."); valido = false; }
    else if (!EMAIL_VALIDO.test(email)) { marcarError(inputEmail, "Revisa el correo: parece incompleto."); valido = false; }
    if (!pass) { marcarError(inputPass, "Escribe tu contraseña."); valido = false; }
    if (!valido) {
      (campoDe(inputEmail).classList.contains("invalido") ? inputEmail : inputPass).focus();
      return;
    }

    // PHP decide la URL de destino (origen=tienda, returnUrl de un producto, etc.)
    const params = new URLSearchParams(window.location.search);
    const origen = params.get("origen") || "";
    const returnUrl = params.get("returnUrl") || sessionStorage.getItem("returnUrl") || "";

    const restaurar = cargando(btnLogin, "Ingresando…");
    try {
      const data = await postJson("php/iniciarSesion.php", { email, pass, origen, returnUrl });
      if (data.respuesta) {
        sessionStorage.removeItem("returnUrl");
        btnLogin.classList.add("listo");
        btnLogin.innerHTML = "¡Bienvenido!";
        setTimeout(() => {
          window.location.href = data.redirectUrl || "../04-contenido/php/contenido.php";
        }, 500);
        return;
      }
      restaurar();
      mostrarAviso("avisoLogin", "El correo o la contraseña no son correctos. Revísalos e inténtalo de nuevo.");
      inputPass.select();
    } catch (error) {
      restaurar();
      mostrarAviso("avisoLogin", "No pudimos conectar con el servidor. Revisa tu conexión e inténtalo de nuevo.");
    }
  });

  // ---------- recuperar contrasena ----------
  const vistaLogin = document.getElementById("vistaLogin");
  const vistaRecuperar = document.getElementById("vistaRecuperar");
  const pasos = {
    1: document.getElementById("pasoCorreo"),
    2: document.getElementById("pasoCodigo"),
    3: document.getElementById("pasoNueva"),
  };
  const inputRecEmail = document.getElementById("inputRecEmail");
  const inputCodigo = document.getElementById("inputCodigo");
  const inputNueva = document.getElementById("inputNuevaPass");
  let emailRecuperacion = "";

  [inputRecEmail, inputCodigo, inputNueva].forEach((input) => {
    limpiarAlEscribir(input);
    input.addEventListener("input", () => mostrarAviso("avisoRecuperar", ""));
  });
  // El codigo solo admite numeros
  inputCodigo.addEventListener("input", () => {
    inputCodigo.value = inputCodigo.value.replace(/\D/g, "").slice(0, 6);
  });

  function irAPaso(n) {
    Object.entries(pasos).forEach(([num, form]) => { form.hidden = Number(num) !== n; });
    [1, 2, 3].forEach((num) => {
      const ind = document.getElementById(`indPaso${num}`);
      ind.classList.toggle("actual", num === n);
      ind.classList.toggle("hecho", num < n);
      ind.querySelector("span").textContent = num < n ? "✓" : String(num);
    });
    mostrarAviso("avisoRecuperar", "");
    const primerCampo = pasos[n].querySelector("input");
    if (primerCampo) setTimeout(() => primerCampo.focus(), 50);
  }

  function abrirRecuperar() {
    vistaLogin.hidden = true;
    vistaRecuperar.hidden = false;
    inputRecEmail.value = inputEmail.value.trim();
    inputCodigo.value = "";
    inputNueva.value = "";
    irAPaso(1);
  }

  function volverALogin(mensajeExito) {
    vistaRecuperar.hidden = true;
    vistaLogin.hidden = false;
    if (emailRecuperacion) inputEmail.value = emailRecuperacion;
    inputPass.value = "";
    // Las contrasenas vuelven a quedar ocultas
    document.querySelectorAll(".btn-ojo").forEach((boton) => {
      const input = document.getElementById(boton.dataset.ojo);
      if (input) input.type = "password";
      boton.setAttribute("aria-pressed", "false");
      boton.setAttribute("aria-label", "Mostrar contraseña");
    });
    mostrarAviso("avisoLogin", mensajeExito || "", "exito");
    (mensajeExito ? inputPass : inputEmail).focus();
  }

  if (linkOlvido) linkOlvido.addEventListener("click", abrirRecuperar);
  document.querySelectorAll("[data-volver-login]").forEach((b) => b.addEventListener("click", () => volverALogin()));

  async function enviarCodigo(boton) {
    const email = inputRecEmail.value.trim();
    if (!EMAIL_VALIDO.test(email)) {
      marcarError(inputRecEmail, email ? "Revisa el correo: parece incompleto." : "Escribe tu correo electrónico.");
      inputRecEmail.focus();
      return false;
    }
    const restaurar = cargando(boton, "Enviando…");
    try {
      const data = await postJson("php/recuperarPass.php", { email });
      restaurar();
      if (!data.respuesta) {
        mostrarAviso("avisoRecuperar", data.message || "No se pudo enviar el código.");
        return false;
      }
      emailRecuperacion = email;
      document.getElementById("recEmailMostrado").textContent = email;
      return true;
    } catch (error) {
      restaurar();
      mostrarAviso("avisoRecuperar", "No pudimos conectar con el servidor. Inténtalo de nuevo.");
      return false;
    }
  }

  pasos[1].addEventListener("submit", async (e) => {
    e.preventDefault();
    if (await enviarCodigo(pasos[1].querySelector(".btn-acceso"))) irAPaso(2);
  });

  document.getElementById("btnReenviarCodigo").addEventListener("click", async (e) => {
    inputRecEmail.value = emailRecuperacion;
    if (await enviarCodigo(e.currentTarget)) {
      inputCodigo.value = "";
      mostrarAviso("avisoRecuperar", `Te enviamos un nuevo código a ${emailRecuperacion}.`, "exito");
    }
  });

  pasos[2].addEventListener("submit", async (e) => {
    e.preventDefault();
    const codigo = inputCodigo.value.trim();
    if (codigo.length !== 6) {
      marcarError(inputCodigo, "El código tiene 6 dígitos.");
      inputCodigo.focus();
      return;
    }
    const restaurar = cargando(pasos[2].querySelector(".btn-acceso"), "Verificando…");
    try {
      const data = await postJson("php/recuperarPass.php", { email: emailRecuperacion, codigo });
      restaurar();
      if (!data.respuesta) {
        marcarError(inputCodigo, data.message || "Código incorrecto o vencido.");
        return;
      }
      irAPaso(3);
    } catch (error) {
      restaurar();
      mostrarAviso("avisoRecuperar", "No pudimos conectar con el servidor. Inténtalo de nuevo.");
    }
  });

  pasos[3].addEventListener("submit", async (e) => {
    e.preventDefault();
    const nuevaPass = inputNueva.value;
    if (nuevaPass.length < 6) {
      marcarError(inputNueva, "Usa al menos 6 caracteres.");
      inputNueva.focus();
      return;
    }
    const restaurar = cargando(pasos[3].querySelector(".btn-acceso"), "Guardando…");
    try {
      const data = await postJson("php/recuperarPass.php", { email: emailRecuperacion, nuevaPass });
      restaurar();
      if (!data.respuesta) {
        mostrarAviso("avisoRecuperar", data.message || "No se pudo actualizar la contraseña.");
        return;
      }
      volverALogin("¡Listo! Tu contraseña se actualizó. Ya puedes ingresar con ella.");
    } catch (error) {
      restaurar();
      mostrarAviso("avisoRecuperar", "No pudimos conectar con el servidor. Inténtalo de nuevo.");
    }
  });
});
