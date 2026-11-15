/**
 * Invitacion a afiliarse que llega en los enlaces de producto
 * Tu Cultura es Progreso - Tienda Virtual
 *
 * - Si la URL trae ?ref=CODIGO (enlace compartido por un asociado), se guarda
 *   30 dias en este navegador. Si llega otro enlace, gana el ultimo.
 * - Sin sesion, se muestra bajo la cabecera el aviso "X te invito..." con el
 *   boton Registrarme (03-registrarse con ?ref= y la pagina para volver).
 * - Se puede cerrar el aviso; vuelve a salir en la proxima visita.
 *
 * Expone window.TCPInvitacion.codigo() para el registro y otras paginas.
 */

(function () {
  'use strict';

  var CLAVE = 'tcp_invitacion';
  var CLAVE_OCULTO = 'tcp_invitacion_oculta';
  var DIAS = 30;
  var FORMATO = /^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{6}$/;

  function leer() {
    try {
      var guardada = JSON.parse(localStorage.getItem(CLAVE) || 'null');
      if (guardada && FORMATO.test(guardada.codigo) && Date.now() - guardada.fecha < DIAS * 864e5) {
        return guardada.codigo;
      }
    } catch (e) { /* sin almacenamiento: sin invitacion recordada */ }
    return '';
  }

  function guardar(codigo) {
    try {
      localStorage.setItem(CLAVE, JSON.stringify({ codigo: codigo, fecha: Date.now() }));
      sessionStorage.removeItem(CLAVE_OCULTO);
    } catch (e) { /* sin almacenamiento */ }
  }

  // Guarda tambien el nombre corto de quien invita (el registro lo muestra al
  // instante aunque la consulta al servidor tarde o falle)
  function guardarNombre(codigo, nombre) {
    try {
      var guardada = JSON.parse(localStorage.getItem(CLAVE) || 'null');
      if (guardada && guardada.codigo === codigo) {
        guardada.nombre = nombre;
        localStorage.setItem(CLAVE, JSON.stringify(guardada));
      }
    } catch (e) { /* sin almacenamiento */ }
  }

  // 1) Guardar el codigo que trae la URL (el ultimo enlace gana)
  var refUrl = (new URLSearchParams(window.location.search).get('ref') || '').toUpperCase();
  if (FORMATO.test(refUrl)) guardar(refUrl);

  window.TCPInvitacion = { codigo: leer };

  // Producto del enlace compartido: si el receptor ya tiene cuenta, al iniciar sesion
  // va a su carrito con este producto (lo lee 02-iniciarSesion/js/iniciarSesion.js)
  var CLAVE_PENDIENTE = 'tcp_pendiente';
  var CLAVE_CONOCIDO = 'tcp_conocido';

  function guardarPendiente() {
    var params = new URLSearchParams(window.location.search);
    var id = params.get('id') || '';
    var esEnlaceCompartido = params.get('compartido') === '1' || FORMATO.test(refUrl);
    if (!/\/producto\.html$/.test(window.location.pathname) || !/^\d+$/.test(id) || !esEnlaceCompartido) return;
    try { localStorage.setItem(CLAVE_PENDIENTE, JSON.stringify({ id: id, fecha: Date.now() })); } catch (e) { /* sin almacenamiento */ }
  }

  // Este navegador ya inicio sesion alguna vez: ya es asociado, no se le invita a registrarse
  function yaEsConocido() {
    try { return localStorage.getItem(CLAVE_CONOCIDO) === '1'; } catch (e) { return false; }
  }

  document.addEventListener('DOMContentLoaded', function () {
    var codigo = leer();
    var oculto = false;
    try { oculto = sessionStorage.getItem(CLAVE_OCULTO) === codigo; } catch (e) { /* seguir */ }

    // 2) Solo a visitantes sin sesion
    fetch('php/sesion.php')
      .then(function (res) { return res.json(); })
      .then(function (sesion) {
        if (sesion && sesion.logueado) {
          try { localStorage.setItem(CLAVE_CONOCIDO, '1'); } catch (e) { /* sin almacenamiento */ }
          return null;
        }
        guardarPendiente();
        if (!codigo || oculto || yaEsConocido()) return null;
        return fetch('php/invitaciones.php?action=resolver&ref=' + encodeURIComponent(codigo))
          .then(function (res) { return res.json(); });
      })
      .then(function (data) {
        if (data && data.success && data.valido) {
          guardarNombre(codigo, data.nombre);
          mostrarAviso(codigo, data.nombre);
        }
      })
      .catch(function () { /* sin aviso */ });
  });

  function mostrarAviso(codigo, nombre) {
    if (document.getElementById('aviso-invitacion')) return;

    var volver = window.location.pathname + window.location.search;
    var urlRegistro = '../03-registrarse/registrarse.html?ref=' + encodeURIComponent(codigo) +
      '&volver=' + encodeURIComponent(volver);

    var aviso = document.createElement('div');
    aviso.id = 'aviso-invitacion';
    aviso.className = 'aviso-invitacion';
    aviso.setAttribute('role', 'region');
    aviso.setAttribute('aria-label', 'Invitación');
    aviso.innerHTML =
      '<div class="aviso-invitacion-contenido">' +
        '<i class="fas fa-gift aviso-invitacion-icono" aria-hidden="true"></i>' +
        '<p><strong></strong> te invitó a Tu Cultura es Progreso. ' +
        'Regístrate para comprar y ganar puntos.</p>' +
        '<a class="aviso-invitacion-boton"></a>' +
        '<a class="aviso-invitacion-login" href="../02-iniciarSesion/iniciarSesion.html?origen=tienda">¿Ya tienes cuenta? Inicia sesión</a>' +
        '<button type="button" class="aviso-invitacion-cerrar" aria-label="Cerrar aviso">&times;</button>' +
      '</div>';
    aviso.querySelector('strong').textContent = nombre;
    var boton = aviso.querySelector('.aviso-invitacion-boton');
    boton.href = urlRegistro;
    boton.textContent = 'Registrarme';
    aviso.querySelector('.aviso-invitacion-cerrar').addEventListener('click', function () {
      aviso.remove();
      try { sessionStorage.setItem(CLAVE_OCULTO, codigo); } catch (e) { /* sin almacenamiento */ }
    });

    var cabecera = document.querySelector('.tienda-header');
    if (cabecera) cabecera.insertAdjacentElement('afterend', aviso);
    else document.body.insertBefore(aviso, document.body.firstChild);
  }
})();
