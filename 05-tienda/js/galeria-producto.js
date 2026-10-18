/**
 * Galeria de fotos del producto: foto grande + tira de miniaturas + visor ampliado
 * Tu Cultura es Progreso - Tienda Virtual
 *
 * La usan el modal de detalle del catalogo (js/tienda.js) y producto.html
 * (js/producto.js).
 * - Computador: al pasar el mouse por una miniatura cambia la foto grande.
 * - Clic en una miniatura o en la foto grande: visor a pantalla completa con
 *   flechas, contador y miniaturas (Esc, flechas del teclado o deslizar).
 * - Celular: la foto grande tambien se desliza para pasar a la siguiente.
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

    // El visor tambien sirve con una sola foto
    imgPrincipal.classList.add('galeria-ampliable');
    imgPrincipal.onclick = () => {
        const fotos = lista.length ? lista.map(rutaFotoGaleria) : [imgPrincipal.src];
        abrirVisorFotos(fotos, actual, nombre, lista.length > 1 ? mostrar : null);
    };

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
    contenedor.setAttribute('aria-label', 'Fotos del producto');
    contenedor.innerHTML = lista.map((imagen, i) => `
        <button type="button" class="galeria-miniatura${i === 0 ? ' activa' : ''}" data-indice="${i}"
                aria-label="Ampliar foto ${i + 1} de ${lista.length}">
            <img src="${rutaFotoGaleria(imagen)}" alt="" loading="lazy"
                 onerror="this.src='${GALERIA_IMAGEN_DEFECTO}'">
        </button>
    `).join('');

    function mostrar(indice) {
        actual = (indice + lista.length) % lista.length;
        imgPrincipal.src = rutaFotoGaleria(lista[actual]);
        imgPrincipal.alt = `${nombre} - foto ${actual + 1} de ${lista.length}`;
        contenedor.querySelectorAll('.galeria-miniatura').forEach((boton, i) => {
            boton.classList.toggle('activa', i === actual);
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
        if (!boton) return;
        mostrar(Number(boton.dataset.indice));
        abrirVisorFotos(lista.map(rutaFotoGaleria), actual, nombre, mostrar);
    };

    // Pasar el mouse por una miniatura cambia la foto grande (solo con mouse)
    contenedor.onmouseover = (e) => {
        if (!window.matchMedia('(hover: hover)').matches) return;
        const boton = e.target.closest('.galeria-miniatura');
        if (boton && Number(boton.dataset.indice) !== actual) mostrar(Number(boton.dataset.indice));
    };

    // Deslizar la foto grande (celular)
    let inicioX = null;
    imgPrincipal.ontouchstart = (e) => { inicioX = e.touches[0].clientX; };
    imgPrincipal.ontouchend = (e) => {
        if (inicioX === null) return;
        const dx = e.changedTouches[0].clientX - inicioX;
        inicioX = null;
        if (Math.abs(dx) > 40) {
            e.preventDefault(); // un deslizamiento no abre el visor
            mostrar(actual + (dx < 0 ? 1 : -1));
        }
    };

    mostrar(0);
}

function rutaFotoGaleria(imagen) {
    return GALERIA_RUTA_IMAGENES + imagen;
}

// ==== VISOR AMPLIADO (uno solo por pagina, se crea al primer uso) ====
const visorFotos = { el: null, fotos: [], indice: 0, nombre: '', alCambiar: null, foco: null };

function crearVisorFotos() {
    const el = document.createElement('div');
    el.className = 'visor-fotos';
    el.hidden = true;
    el.setAttribute('role', 'dialog');
    el.setAttribute('aria-modal', 'true');
    el.setAttribute('aria-label', 'Fotos del producto ampliadas');
    el.innerHTML = `
        <div class="visor-barra">
            <span class="visor-contador"></span>
            <button type="button" class="visor-cerrar" aria-label="Cerrar">&times;</button>
        </div>
        <div class="visor-escenario">
            <button type="button" class="visor-flecha visor-anterior" aria-label="Foto anterior">
                <i class="fas fa-chevron-left"></i>
            </button>
            <img class="visor-imagen" alt="" onerror="this.src='${GALERIA_IMAGEN_DEFECTO}'">
            <button type="button" class="visor-flecha visor-siguiente" aria-label="Foto siguiente">
                <i class="fas fa-chevron-right"></i>
            </button>
        </div>
        <div class="visor-miniaturas"></div>
    `;
    document.body.appendChild(el);

    el.querySelector('.visor-cerrar').onclick = cerrarVisorFotos;
    el.querySelector('.visor-anterior').onclick = () => irAFotoVisor(visorFotos.indice - 1);
    el.querySelector('.visor-siguiente').onclick = () => irAFotoVisor(visorFotos.indice + 1);
    el.querySelector('.visor-miniaturas').onclick = (e) => {
        const boton = e.target.closest('.visor-miniatura');
        if (boton) irAFotoVisor(Number(boton.dataset.indice));
    };
    // Clic en el fondo oscuro (fuera de la foto y los botones) cierra
    el.addEventListener('click', (e) => {
        if (e.target === el || e.target.classList.contains('visor-escenario')) cerrarVisorFotos();
    });

    let inicioX = null;
    const escenario = el.querySelector('.visor-escenario');
    escenario.addEventListener('touchstart', (e) => { inicioX = e.touches[0].clientX; }, { passive: true });
    escenario.addEventListener('touchend', (e) => {
        if (inicioX === null) return;
        const dx = e.changedTouches[0].clientX - inicioX;
        inicioX = null;
        if (Math.abs(dx) > 40) irAFotoVisor(visorFotos.indice + (dx < 0 ? 1 : -1));
    });

    document.addEventListener('keydown', (e) => {
        if (el.hidden) return;
        if (e.key === 'Escape') { e.stopPropagation(); cerrarVisorFotos(); }
        else if (e.key === 'ArrowLeft') irAFotoVisor(visorFotos.indice - 1);
        else if (e.key === 'ArrowRight') irAFotoVisor(visorFotos.indice + 1);
    }, true);

    return el;
}

function abrirVisorFotos(fotos, indice, nombre, alCambiar) {
    if (!fotos || !fotos.length) return;
    if (!visorFotos.el) visorFotos.el = crearVisorFotos();

    Object.assign(visorFotos, { fotos, nombre, alCambiar, foco: document.activeElement });
    const el = visorFotos.el;
    const varias = fotos.length > 1;
    el.classList.toggle('una-foto', !varias);
    el.querySelector('.visor-miniaturas').innerHTML = varias ? fotos.map((src, i) => `
        <button type="button" class="visor-miniatura" data-indice="${i}" aria-label="Foto ${i + 1} de ${fotos.length}">
            <img src="${src}" alt="" onerror="this.src='${GALERIA_IMAGEN_DEFECTO}'">
        </button>
    `).join('') : '';

    el.hidden = false;
    document.body.classList.add('visor-fotos-abierto');
    irAFotoVisor(indice || 0);
    el.querySelector('.visor-cerrar').focus();
}

function irAFotoVisor(indice) {
    const { el, fotos } = visorFotos;
    if (!el || !fotos.length) return;
    const i = (indice + fotos.length) % fotos.length;
    visorFotos.indice = i;

    const img = el.querySelector('.visor-imagen');
    img.src = fotos[i];
    img.alt = `${visorFotos.nombre} - foto ${i + 1} de ${fotos.length}`;
    el.querySelector('.visor-contador').textContent = fotos.length > 1 ? `${i + 1} / ${fotos.length}` : '';

    el.querySelectorAll('.visor-miniatura').forEach((boton, j) => {
        boton.classList.toggle('activa', j === i);
        if (j === i) {
            const tira = boton.parentElement;
            tira.scrollTo({ left: boton.offsetLeft - (tira.clientWidth - boton.offsetWidth) / 2, behavior: 'smooth' });
        }
    });

    // La foto de la tarjeta sigue a la del visor
    if (visorFotos.alCambiar) visorFotos.alCambiar(i);
}

function cerrarVisorFotos() {
    const el = visorFotos.el;
    if (!el || el.hidden) return;
    el.hidden = true;
    document.body.classList.remove('visor-fotos-abierto');
    if (visorFotos.foco && visorFotos.foco.focus) visorFotos.foco.focus();
}
