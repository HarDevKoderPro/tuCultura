document.addEventListener("DOMContentLoaded", () => {
  const btn = document.getElementById("btnIniciarSesion");
  const inputEmail = document.getElementById("inputEmail");
  const inputPass = document.getElementById("inputPass");
  const linkOlvido = document.getElementById("linkOlvidastePass");

  if (!btn || !inputEmail || !inputPass) return;

  // Enter para enviar login
  [inputEmail, inputPass].forEach((el) => {
    el.addEventListener("keypress", (e) => {
      if (e.key === "Enter") btn.click();
    });
  });

  // --- LÓGICA DE INICIO DE SESIÓN ---
  btn.addEventListener("click", async () => {
    const email = inputEmail.value.trim();
    const pass = inputPass.value.trim();

    if (!email || !pass) {
      Swal.fire({
        icon: "warning",
        title: "Atención",
        text: "Por favor ingresa tus credenciales.",
      });
      return;
    }

    try {
      const response = await fetch("php/iniciarSesion.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ email, pass }),
      });

      const data = await response.json();

      if (data.respuesta) {
        Swal.fire({
          icon: "success",
          title: "¡Bienvenido!",
          text: "Inicio de sesión exitoso.",
          timer: 1500,
          showConfirmButton: false,
        }).then(() => {
          // ✅ Detección automática de entorno
          const isLocal = window.location.hostname === "localhost";
          let basePath;

          if (isLocal) {
            // En local: extraer la base desde la URL actual
            const pathParts = window.location.pathname.split("/");
            const index02 = pathParts.indexOf("02-iniciarSesion");
            basePath = pathParts.slice(0, index02).join("/");
          } else {
            // En servidor real: la raíz es /
            basePath = "";
          }

          const params = new URLSearchParams(window.location.search);
          const esTienda = params.get("origen") === "tienda";

          // ✅ Verificar si hay una URL de retorno guardada (ej: desde checkout)
          const returnUrl = sessionStorage.getItem("returnUrl");

          if (returnUrl) {
            // Limpiar returnUrl para que no se reutilice
            sessionStorage.removeItem("returnUrl");
            // Redirigir de vuelta a la tienda (checkout, carrito, etc.)
            window.location.href = `${basePath}/05-tienda/${returnUrl}`;
          } else if (esTienda && data.esAdmin) {
            window.location.href = `${basePath}/05-tienda/admin/index.html`;
          } else if (esTienda) {
            // ✅ Cliente que inició sesión desde la tienda → volver a la tienda
            window.location.href = `${basePath}/05-tienda/index.html`;
          } else {
            window.location.href = `${basePath}/04-contenido/php/contenido.php`;
          }
        });
      } else {
        Swal.fire({
          icon: "error",
          title: "Error de acceso",
          text: "El correo electrónico o la contraseña son incorrectos.",
        });
      }
    } catch (error) {
      Swal.fire({
        icon: "error",
        title: "Error",
        text: "No se pudo conectar con el servidor.",
      });
    }
  });

  // --- LÓGICA DE RECUPERAR CONTRASEÑA ---
  if (linkOlvido) {
    linkOlvido.addEventListener("click", (e) => {
      e.preventDefault();

      // PASO 1: Pedir el correo y enviar código
      Swal.fire({
        title: "Recuperar Contraseña",
        text: "Ingresa tu correo electrónico registrado:",
        input: "email",
        inputPlaceholder: "tu-correo@ejemplo.com",
        showCancelButton: true,
        confirmButtonText: "Enviar código",
        cancelButtonText: "Cancelar",
        preConfirm: (email) => {
          if (!email) {
            Swal.showValidationMessage("Debes ingresar un correo válido.");
            return false;
          }
          return fetch("php/recuperarPass.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ email }),
          })
            .then((r) => r.json())
            .then((data) => {
              if (!data.respuesta) {
                Swal.showValidationMessage(
                  data.message || "No se pudo enviar el código.",
                );
                return false;
              }
              return { email };
            })
            .catch(() => {
              Swal.showValidationMessage("Error de conexión con el servidor.");
              return false;
            });
        },
      }).then((result) => {
        if (!result.isConfirmed || !result.value) return;

        const emailUsuario = result.value.email;

        // PASO 2: Pedir el código de 6 dígitos
        Swal.fire({
          title: "Código de verificación",
          html: `Se envió un código a <b>${emailUsuario}</b>.<br>Ingresa el código de 6 dígitos:`,
          input: "text",
          inputPlaceholder: "Ej: 012345",
          showCancelButton: true,
          confirmButtonText: "Verificar",
          cancelButtonText: "Cancelar",
          inputAttributes: { maxlength: 6 },
          preConfirm: (codigo) => {
            if (!codigo || codigo.trim().length !== 6) {
              Swal.showValidationMessage(
                "El código debe tener exactamente 6 dígitos.",
              );
              return false;
            }
            return fetch("php/recuperarPass.php", {
              method: "POST",
              headers: { "Content-Type": "application/json" },
              body: JSON.stringify({
                email: emailUsuario,
                codigo: codigo.trim(),
              }),
            })
              .then((r) => r.json())
              .then((data) => {
                if (!data.respuesta) {
                  Swal.showValidationMessage(
                    data.message || "Código incorrecto o expirado.",
                  );
                  return false;
                }
                return { email: emailUsuario };
              })
              .catch(() => {
                Swal.showValidationMessage(
                  "Error de conexión con el servidor.",
                );
                return false;
              });
          },
        }).then((result) => {
          if (!result.isConfirmed || !result.value) return;

          // PASO 3: Pedir nueva contraseña
          Swal.fire({
            title: "Nueva Contraseña",
            text: "Ingresa tu nueva contraseña (mínimo 6 caracteres):",
            input: "password",
            inputPlaceholder: "Nueva contraseña",
            showCancelButton: true,
            confirmButtonText: "Guardar",
            cancelButtonText: "Cancelar",
            preConfirm: (nuevaPass) => {
              if (!nuevaPass || nuevaPass.length < 6) {
                Swal.showValidationMessage(
                  "La contraseña debe tener al menos 6 caracteres.",
                );
                return false;
              }
              return fetch("php/recuperarPass.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ email: result.value.email, nuevaPass }),
              })
                .then((r) => r.json())
                .then((data) => {
                  if (!data.respuesta) {
                    Swal.showValidationMessage(
                      data.message || "No se pudo actualizar la contraseña.",
                    );
                    return false;
                  }
                  return true;
                })
                .catch(() => {
                  Swal.showValidationMessage(
                    "Error de conexión con el servidor.",
                  );
                  return false;
                });
            },
          }).then((result) => {
            if (result.isConfirmed) {
              Swal.fire({
                icon: "success",
                title: "¡Contraseña actualizada!",
                text: "Ya puedes iniciar sesión con tu nueva contraseña.",
                timer: 2500,
                showConfirmButton: false,
              });
            }
          });
        });
      });
    });
  }
});
