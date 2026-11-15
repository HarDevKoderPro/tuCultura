/**
 * Opciones del menu "Mi cuenta", iguales en todas las pantallas del usuario
 * (tienda, producto, carrito, pago, pedidos, confirmacion y zona de asociados).
 *
 * Lo usan 05-tienda/js/sesion-ui.js y 04-contenido/js/menu-cuenta.js: cada pagina
 * conserva su propio boton y estilos, pero la lista de opciones sale de aqui.
 *
 *   TCPMenuUsuario.nodos({ area: 'tienda'|'zona', logueado, esAdmin, onSalir },
 *                        { iconos: true|false, claseOpcion, claseSalir })
 *   -> arreglo de elementos <a> listos para insertar en el menu.
 *
 * Tambien recuerda la ultima pagina de la tienda visitada (sessionStorage
 * 'tcp_ultima_tienda') para que la zona de asociados ofrezca "Volver al pago",
 * "Volver al carrito" o "Volver a la tienda".
 */
(function () {
  'use strict';

  // Carpeta raiz del sitio (por si no esta en la raiz del dominio)
  var coincidencia = window.location.pathname.match(/^(.*?)\/(?:05-tienda|04-contenido)\//);
  var BASE = (coincidencia ? coincidencia[1] : '') + '/';

  var URL = {
    tienda: BASE + '05-tienda/index.html',
    carrito: BASE + '05-tienda/carrito.html',
    pedidos: BASE + '05-tienda/mis-pedidos.html',
    zona: BASE + '04-contenido/php/contenido.php',
    admin: BASE + '05-tienda/admin/index.html',
    login: BASE + '02-iniciarSesion/iniciarSesion.html?origen=tienda',
    salirTienda: BASE + '05-tienda/php/logout.php',
    salirZona: BASE + '04-contenido/php/logout.php'
  };

  var CLAVE_ULTIMA = 'tcp_ultima_tienda';
  var FORMATO_PAGINA = /^[a-z0-9\-]+\.html(\?[\w=&%.\-]*)?$/i;

  var archivo = window.location.pathname.split('/').pop() || '';

  // Recordar la pagina de la tienda donde esta el usuario (no la confirmacion de un pedido)
  if (/\/05-tienda\/[^\/]+\.html$/.test(window.location.pathname) && archivo !== 'confirmacion.html') {
    try { sessionStorage.setItem(CLAVE_ULTIMA, archivo + window.location.search); } catch (e) { /* sin almacenamiento */ }
  }

  function ultimaTienda() {
    try {
      var guardada = sessionStorage.getItem(CLAVE_ULTIMA) || '';
      return FORMATO_PAGINA.test(guardada) ? guardada : '';
    } catch (e) { return ''; }
  }

  function enlace(opcion, opts) {
    var a = document.createElement('a');
    a.href = opcion.href;
    a.className = (opts.claseOpcion || '') + (opcion.actual ? ' actual' : '');
    a.setAttribute('role', 'menuitem');
    if (opcion.actual) a.setAttribute('aria-current', 'page');
    if (opts.iconos && opcion.icono) {
      var i = document.createElement('i');
      i.className = 'fas ' + opcion.icono;
      a.appendChild(i);
      a.appendChild(document.createTextNode(' '));
    }
    a.appendChild(document.createTextNode(opcion.texto));
    return a;
  }

  function nodos(ctx, opts) {
    opts = opts || {};
    var enTienda = ctx.area === 'tienda';
    var lista = [];

    // Tienda: desde la zona, vuelve a la pagina donde estaba (pago, carrito...)
    var textoTienda = 'Tienda';
    var hrefTienda = URL.tienda;
    var ultima = enTienda ? '' : ultimaTienda();
    if (ultima) {
      hrefTienda = BASE + '05-tienda/' + ultima;
      var pagina = ultima.split('?')[0];
      if (pagina === 'checkout.html') textoTienda = 'Volver al pago';
      else if (pagina === 'carrito.html') textoTienda = 'Volver al carrito';
      else if (pagina !== 'index.html') textoTienda = 'Volver a la tienda';
    }
    lista.push({ href: hrefTienda, icono: 'fa-store', texto: textoTienda,
      actual: enTienda && (archivo === 'index.html' || archivo === 'producto.html') });
    lista.push({ href: URL.carrito, icono: 'fa-shopping-cart', texto: 'Mi carrito',
      actual: enTienda && (archivo === 'carrito.html' || archivo === 'checkout.html') });
    lista.push({ href: URL.pedidos, icono: 'fa-box', texto: 'Mis pedidos',
      actual: enTienda && archivo === 'mis-pedidos.html' });

    if (ctx.logueado) {
      lista.push({ href: URL.zona, icono: 'fa-th-large', texto: 'Mi zona de asociado', actual: !enTienda });
      if (ctx.esAdmin) lista.push({ href: URL.admin, icono: 'fa-tools', texto: 'Panel administrativo' });
    }

    var elementos = lista.map(function (o) { return enlace(o, opts); });

    if (ctx.logueado) {
      var salir = enlace({ href: '#', icono: 'fa-sign-out-alt', texto: 'Cerrar sesión' }, opts);
      salir.id = 'btn-logout';
      if (opts.claseSalir) salir.classList.add(opts.claseSalir);
      salir.addEventListener('click', function (e) {
        e.preventDefault();
        if (typeof ctx.onSalir === 'function') ctx.onSalir();
        else window.location.href = enTienda ? URL.salirTienda : URL.salirZona;
      });
      elementos.push(salir);
    } else {
      elementos.push(enlace({ href: URL.login, icono: 'fa-sign-in-alt', texto: 'Iniciar sesión' }, opts));
    }
    return elementos;
  }

  window.TCPMenuUsuario = { nodos: nodos, urls: URL };
})();
