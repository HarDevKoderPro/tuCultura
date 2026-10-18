/**
 * Galeria de fotos del producto: foto grande + tira de miniaturas
 * Tu Cultura es Progreso - Tienda Virtual
 *
 * La usan el modal de detalle del catalogo (js/tienda.js) y producto.html
 * (js/producto.js). Al tocar una miniatura la foto se muestra en grande; en
 * celular tambien se puede deslizar la foto grande para pasar a la siguiente.
 *
 * Uso: montarGaleriaProducto(imgPrincipal, contenedorMiniaturas, imagenes, nombre)
 *   imagenes: nombres de archivo en imagenes/productos/ (la principal primero)
 */

const GALERIA_RUTA_IMAGENES = 'imagenes/productos/';
const GALERIA_IMAGEN_DEFECTO = '../01-principal/imagenes/fondo-producto.png';

function montarGaleriaProducto(imgPrincipal, contenedor, imagenes, nombre) {
    if (!imgPrincipal || !contenedor) return;

    const lista = (imagenes || []).filter(Boolean);
    const galeria = contenedor.closest('.galeria-producto');
    let actual = 0;

    // Una sola foto (o ninguna): sin tira, como antes
    if (lista.length < 2) {
        contenedor.innerHTML = '';
        contenedor.hidden = true;
        if (galeria) galeria.classList.remove('con-miniaturas');
        imgPrincipal.ontouchstart = imgPrincipal.ontouchend = null;
        return;
    }

    contenedor.hidden = false;
    if (galeria) galeria.classList.add('con-miniaturas');
    contenedor.setAttribute('role', 'tablist');
    contenedor.setAttribute('aria-label', 'Fotos del producto');
    contenedor.innerHTML = lista.map((imagen, i) => `
        <button type="button" class="galeria-miniatura${i === 0 ? ' activa' : ''}" data-indice="${i}"
                role="tab" aria-selected="${i === 0}" aria-label="Foto ${i + 1} de ${lista.length}">
            <img src="${GALERIA_RUTA_IMAGENES}${imagen}" alt="" loading="lazy"
                 onerror="this.src='${GALERIA_IMAGEN_DEFECTO}'">
        </button>
    `).join('');

    function mostrar(indice) {
        actual = (indice + lista.length) % lista.length;
        imgPrincipal.src = GALERIA_RUTA_IMAGENES + lista[actual];
        imgPrincipal.alt = `${nombre} - foto ${actual + 1} de ${lista.length}`;
        contenedor.querySelectorAll('.galeria-miniatura').forEach((boton, i) => {
            boton.classList.toggle('activa', i === actual);
            boton.setAttribute('aria-selected', i === actual);
        });
        // Centrar la miniatura activa solo dentro de la tira (sin mover la pagina)
        const activa = contenedor.querySelector('.galeria-miniatura.activa');
        if (activa) {
            contenedor.scrollTo({
                left: activa.offsetLeft - (contenedor.clientWidth - activa.offsetWidth) / 2,
                behavior: 'smooth'
            });
        }
    }

    contenedor.onclick = (e) => {
        const boton = e.target.closest('.galeria-miniatura');
        if (boton) mostrar(Number(boton.dataset.indice));
    };

    // Deslizar la foto grande (celular)
    let inicioX = null;
    imgPrincipal.ontouchstart = (e) => { inicioX = e.touches[0].clientX; };
    imgPrincipal.ontouchend = (e) => {
        if (inicioX === null) return;
        const dx = e.changedTouches[0].clientX - inicioX;
        inicioX = null;
        if (Math.abs(dx) > 40) mostrar(actual + (dx < 0 ? 1 : -1));
    };

    mostrar(0);
}
