/**
 * Gestión de UI de sesión en la tienda
 * Tu Cultura es Progreso
 * 
 * Detecta si hay sesión activa y muestra/oculta
 * el botón de "Cerrar Sesión" / "Iniciar Sesión" dinámicamente.
 * El carrito en localStorage NO se toca al cerrar sesión.
 */

(function () {
  'use strict';

  // Verificar sesión al cargar la página
  document.addEventListener('DOMContentLoaded', function () {
    verificarSesionUI();
  });

  function verificarSesionUI() {
    fetch('php/sesion.php')
      .then(function (res) { return res.json(); })
      .then(function (data) {
        actualizarBotonSesion(data);
      })
      .catch(function () {
        // Si falla, mostrar botón de login por defecto
        actualizarBotonSesion({ logueado: false });
      });
  }

  function actualizarBotonSesion(data) {
    // Buscar el contenedor de íconos del header
    var contenedor = document.querySelector('.tienda-header-iconos');
    if (!contenedor) return;

    // Buscar el ícono de usuario existente (enlace a login)
    var iconoUsuario = contenedor.querySelector('#icono-usuario');

    if (data.logueado && !data.esAdmin) {
      // ---- USUARIO LOGUEADO (cliente) ----

      if (iconoUsuario) {
        // Reemplazar enlace de login por info de usuario + logout
        var wrapper = document.createElement('div');
        wrapper.className = 'sesion-usuario-wrapper';
        wrapper.innerHTML =
          '<span class="sesion-nombre-usuario" title="' + (data.email || '') + '">' +
          '<i class="fas fa-user-check"></i> ' +
          (data.nombre || 'Mi Cuenta') +
          '</span>' +
          '<a href="#" class="tienda-header-icono btn-cerrar-sesion" id="btn-logout" title="Cerrar Sesión">' +
          '<i class="fas fa-sign-out-alt"></i>' +
          '<span class="texto-icono">Salir</span>' +
          '</a>';

        iconoUsuario.parentNode.replaceChild(wrapper, iconoUsuario);
      } else {
        // Si no hay ícono de usuario, agregar el botón de logout al final
        var logoutBtn = document.createElement('a');
        logoutBtn.href = '#';
        logoutBtn.className = 'tienda-header-icono btn-cerrar-sesion';
        logoutBtn.id = 'btn-logout';
        logoutBtn.title = 'Cerrar Sesión';
        logoutBtn.innerHTML =
          '<i class="fas fa-sign-out-alt"></i>' +
          '<span class="texto-icono">Salir</span>';
        contenedor.appendChild(logoutBtn);
      }

      // Asignar evento al botón de logout
      var btnLogout = document.getElementById('btn-logout');
      if (btnLogout) {
        btnLogout.addEventListener('click', function (e) {
          e.preventDefault();
          confirmarCerrarSesion();
        });
      }

    }
    // Si no está logueado, dejamos el enlace de "Cuenta" / login como está
  }

  function confirmarCerrarSesion() {
    // Usar SweetAlert2 si está disponible
    if (typeof Swal !== 'undefined') {
      Swal.fire({
        title: '¿Cerrar sesión?',
        text: 'Tu carrito se mantendrá guardado para cuando vuelvas.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Sí, cerrar sesión',
        cancelButtonText: 'Cancelar',
        customClass: {
          popup: 'swal-tcp-popup',
          title: 'swal-tcp-title',
          confirmButton: 'btn-swal-confirm',
          cancelButton: 'btn-swal-cancel'
        },
        buttonsStyling: false
      }).then(function (result) {
        if (result.isConfirmed) {
          ejecutarLogout();
        }
      });
    } else {
      // Fallback con confirm nativo
      if (confirm('¿Estás seguro de cerrar sesión?\nTu carrito se mantendrá guardado.')) {
        ejecutarLogout();
      }
    }
  }

  function ejecutarLogout() {
    // Redirigir al endpoint de logout (NO toca localStorage/carrito)
    window.location.href = 'php/logout.php';
  }

})();
