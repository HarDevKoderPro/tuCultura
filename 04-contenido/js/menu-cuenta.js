/**
 * Menu "Mi cuenta" de la Zona de Usuarios.
 * - "Ir a la tienda": si el usuario venia de una pagina de la tienda (la tienda guarda
 *   su ultima pagina en sessionStorage 'tcp_ultima_tienda' al pasar a la zona de
 *   asociados), vuelve exactamente alli (carrito, pago, producto...).
 * - Abre/cierra con clic, fuera de el o Escape.
 */
(function () {
  'use strict';

  var boton = document.getElementById('cuenta-zona-boton');
  var menu = document.getElementById('cuenta-zona-menu');
  var envoltura = document.getElementById('cuenta-zona');
  if (!boton || !menu || !envoltura) return;

  // Vuelta a donde se estaba en la tienda
  var enlaceTienda = document.getElementById('cuenta-zona-tienda');
  if (enlaceTienda) {
    var ultima = '';
    try { ultima = sessionStorage.getItem('tcp_ultima_tienda') || ''; } catch (e) { /* sin almacenamiento */ }
    // Solo paginas de la tienda (nombre.html con parametros simples)
    if (/^[a-z0-9\-]+\.html(\?[\w=&%.\-]*)?$/i.test(ultima)) {
      enlaceTienda.href = '../../05-tienda/' + ultima;
      var pagina = ultima.split('?')[0];
      if (pagina === 'checkout.html') enlaceTienda.textContent = 'Volver al pago';
      else if (pagina === 'carrito.html') enlaceTienda.textContent = 'Volver al carrito';
      else if (pagina !== 'index.html') enlaceTienda.textContent = 'Volver a la tienda';
    }
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
