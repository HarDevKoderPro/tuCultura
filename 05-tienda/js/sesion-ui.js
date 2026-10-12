/**
 * Gestión de UI de sesión en la tienda
 * Tu Cultura es Progreso
 * 
 * Detecta si hay sesión activa y arma el menú "Mi cuenta" del ícono de
 * usuario (Mis pedidos, Mi carrito, Iniciar / Cerrar sesión).
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

  function crear(etiqueta, clase, texto) {
    var el = document.createElement(etiqueta);
    if (clase) el.className = clase;
    if (texto) el.textContent = texto;
    return el;
  }

  function opcionMenu(href, icono, texto) {
    var enlace = crear('a', 'menu-cuenta-opcion');
    enlace.href = href;
    enlace.setAttribute('role', 'menuitem');
    enlace.appendChild(crear('i', 'fas ' + icono));
    enlace.appendChild(document.createTextNode(' ' + texto));
    return enlace;
  }

  /**
   * El icono de usuario de la cabecera abre el menu "Mi cuenta":
   * - sin sesion: Mis pedidos, Mi carrito, Iniciar sesion
   * - cliente con sesion: saludo, Mis pedidos, Mi carrito, Cerrar sesion
   * Si la pagina no tiene icono de usuario (checkout, mis pedidos), se agrega.
   */
  function actualizarBotonSesion(data) {
    var contenedor = document.querySelector('.tienda-header-iconos');
    if (!contenedor) return;

    var logueado = data.logueado && !data.esAdmin;
    var nombre = data.nombre || 'Mi cuenta';

    var boton = contenedor.querySelector('#icono-usuario');
    if (!boton) {
      boton = crear('a', 'tienda-header-icono');
      boton.id = 'icono-usuario';
      contenedor.appendChild(boton);
    }
    // Sin JavaScript el enlace sigue llevando a iniciar sesion
    boton.href = '../02-iniciarSesion/iniciarSesion.html?origen=tienda';
    boton.title = 'Mi cuenta';
    boton.setAttribute('aria-haspopup', 'menu');
    boton.setAttribute('aria-expanded', 'false');
    boton.setAttribute('aria-controls', 'menu-cuenta');
    boton.replaceChildren(crear('i', 'fas ' + (logueado ? 'fa-user-check' : 'fa-user')),
      crear('span', 'texto-icono', logueado ? nombre : 'Cuenta'));
    boton.classList.toggle('cuenta-logueada', logueado);

    var envoltura = crear('div', 'cuenta-menu-envoltura');
    boton.parentNode.insertBefore(envoltura, boton);
    envoltura.appendChild(boton);

    var menu = crear('div', 'menu-cuenta');
    menu.id = 'menu-cuenta';
    menu.setAttribute('role', 'menu');
    menu.hidden = true;

    if (logueado) {
      var saludo = crear('div', 'menu-cuenta-saludo');
      saludo.appendChild(document.createTextNode('Hola, '));
      saludo.appendChild(crear('strong', '', nombre));
      if (data.email) saludo.appendChild(crear('small', '', data.email));
      menu.appendChild(saludo);
    }
    menu.appendChild(opcionMenu('mis-pedidos.html', 'fa-box', 'Mis pedidos'));
    menu.appendChild(opcionMenu('carrito.html', 'fa-shopping-cart', 'Mi carrito'));
    if (logueado) {
      var salir = opcionMenu('#', 'fa-sign-out-alt', 'Cerrar sesión');
      salir.id = 'btn-logout';
      salir.classList.add('menu-cuenta-salir');
      salir.addEventListener('click', function (e) {
        e.preventDefault();
        cerrarMenu();
        confirmarCerrarSesion();
      });
      menu.appendChild(salir);
    } else {
      menu.appendChild(opcionMenu('../02-iniciarSesion/iniciarSesion.html?origen=tienda', 'fa-sign-in-alt', 'Iniciar sesión'));
    }
    envoltura.appendChild(menu);

    function abrirMenu() {
      menu.hidden = false;
      boton.setAttribute('aria-expanded', 'true');
    }
    function cerrarMenu() {
      menu.hidden = true;
      boton.setAttribute('aria-expanded', 'false');
    }

    boton.addEventListener('click', function (e) {
      e.preventDefault();
      if (menu.hidden) abrirMenu(); else cerrarMenu();
    });
    document.addEventListener('click', function (e) {
      if (!envoltura.contains(e.target)) cerrarMenu();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !menu.hidden) {
        cerrarMenu();
        boton.focus();
      }
    });
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
