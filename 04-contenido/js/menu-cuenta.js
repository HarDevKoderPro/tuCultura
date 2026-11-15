/**
 * Menu "Mi cuenta" de la Zona de Usuarios.
 * Las opciones (tienda o "Volver al pago/carrito", carrito, pedidos, zona, panel admin,
 * cerrar sesion) salen del componente compartido 01-principal/js/menu-usuario.js, las
 * mismas que ve el usuario en la tienda. Abre/cierra con clic, fuera de el o Escape.
 */
(function () {
  'use strict';

  var boton = document.getElementById('cuenta-zona-boton');
  var menu = document.getElementById('cuenta-zona-menu');
  var envoltura = document.getElementById('cuenta-zona');
  if (!boton || !menu || !envoltura) return;

  if (window.TCPMenuUsuario) {
    var opciones = window.TCPMenuUsuario.nodos(
      { area: 'zona', logueado: true, esAdmin: envoltura.dataset.admin === '1' },
      { iconos: false, claseSalir: 'cuenta-zona-salir' });
    menu.replaceChildren.apply(menu, opciones);
  }

  function abrir() { menu.hidden = false; boton.setAttribute('aria-expanded', 'true'); }
  function cerrar() { menu.hidden = true; boton.setAttribute('aria-expanded', 'false'); }

  boton.addEventListener('click', function (e) {
    e.preventDefault();
    if (menu.hidden) abrir(); else cerrar();
  });
  document.addEventListener('click', function (e) {
    if (!envoltura.contains(e.target)) cerrar();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !menu.hidden) { cerrar(); boton.focus(); }
  });
})();
