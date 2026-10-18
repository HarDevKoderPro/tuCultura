/**
 * Galeria de fotos y videos del producto: foto grande + tira de miniaturas + visor ampliado
 * Tu Cultura es Progreso - Tienda Virtual
 *
 * La usan el modal de detalle del catalogo (js/tienda.js) y producto.html
 * (js/producto.js).
 * - Computador: al pasar el mouse por una miniatura de foto cambia la foto grande.
 * - Clic en una miniatura o en la foto grande: visor a pantalla completa con
 *   flechas, contador y miniaturas (Esc, flechas del teclado o deslizar).
 * - Los videos tienen miniatura con icono de reproducir y se ven solo en el
 *   visor (con controles); la foto grande de la tarjeta siempre es una foto.
 * - Celular: la foto grande tambien se desliza para pasar a la siguiente foto.
 *
 * Uso: montarGaleriaProducto(imgPrincipal, contenedorMiniaturas, medios, nombre)
 *   medios: [{tipo: 'imagen'|'video', archivo}] (productos.php 'medios') o nombres
 *   de archivo de fotos ('imagenes'), en imagenes/productos/, la principal primero.
 */

const GALERIA_RUTA_IMAGENES = 'imagenes/productos/';
const GALERIA_IMAGEN_DEFECTO = '../01-principal/imagenes/fondo-producto.png';

function montarGaleriaProducto(imgPrincipal, contenedor, medios, nombre) {
    if (!imgPrincipal || !contenedor) return;

    const lista = normalizarMediosGaleria(medios);
    const galeria = contenedor.closest('.galeria-producto');
    let actual = Math.max(0, lista.findIndex(m => m.tipo === 'imagen'));

    // El visor tambien sirve con una sola foto
    imgPrincipal.classList.add('galeria-ampliable');
    imgPrincipal.onclick = () => {
        const items = lista.length ? lista : [{ tipo: 'imagen', src: imgPrincipal.src }];
        abrirVisorFotos(items, lista.length ? actual : 0, nombre, lista.length > 1 ? mostrar : null);
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
    contenedor.setAttribute('aria-label', 'Fotos y videos del producto');
    contenedor.innerHTML = lista.map((m, i) => htmlMiniaturaGaleria(m, i, lista.length, 'galeria-miniatura')).join('');

    // Muestra en la tarjeta la foto i (los videos solo se ven en el visor)
    function mostrar(indice) {
        const i = (indice + lista.length) % lista.length;
        if (lista[i].tipo !== 'imagen') return;
        actual = i;
        imgPrincipal.src = lista[actual].src;
        imgPrincipal.alt = `${nombre} - foto ${actual + 1} de ${lista.length}`;
        contenedor.querySelectorAll('.galeria-miniatura').forEach((boton, j) => {
            boton.classList.toggle('activa', j === actual);
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

    // Siguiente/anterior foto (salta los videos)
    function fotoVecina(paso) {
        for (let k = 1; k <= lista.length; k++) {
            const i = (actual + paso * k + lista.length * k) % lista.length;
            if (lista[i].tipo === 'imagen') return i;
        }
        return actual;
    }

    contenedor.onclick = (e) => {
        const boton = e.target.closest('.galeria-miniatura');
        if (!boton) return;
        const i = Number(boton.dataset.indice);
        mostrar(i);
        abrirVisorFotos(lista, i, nombre, mostrar);
    };

    // Pasar el mouse por una miniatura de foto cambia la foto grande (solo con mouse)
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
            mostrar(fotoVecina(dx < 0 ? 1 : -1));
        }
    };

    mostrar(actual);
}

// Acepta [{tipo, archivo}] o ['archivo.webp', ...] y devuelve [{tipo, src}]
function normalizarMediosGaleria(medios) {
    return (medios || []).filter(Boolean).map(m => {
        const tipo = typeof m === 'object' && m.tipo === 'video' ? 'video' : 'imagen';
        const archivo = typeof m === 'object' ? m.archivo : m;
        return archivo ? { tipo, src: GALERIA_RUTA_IMAGENES + archivo } : null;
    }).filter(Boolean);
}

function htmlMiniaturaGaleria(m, i, total, clase) {
    const esVideo = m.tipo === 'video';
    const etiqueta = esVideo ? `Reproducir video (${i + 1} de ${total})` : `Ampliar foto ${i + 1} de ${total}`;
    // #t=0.1: muestra el primer cuadro del video como miniatura (tambien en iPhone)
    const vista = esVideo
        ? `<video src="${m.src}#t=0.1" muted playsinline preload="metadata" tabindex="-1"></video>
           <span class="galeria-play" aria-hidden="true"><i class="fas fa-play"></i></span>`
        : `<img src="${m.src}" alt="" loading="lazy" onerror="this.src='${GALERIA_IMAGEN_DEFECTO}'">`;
    return `
        <button type="button" class="${clase}${esVideo ? ' es-video' : ''}" data-indice="${i}" aria-label="${etiqueta}">
            ${vista}
        </button>
    `;
}

// ==== VISOR AMPLIADO (uno solo por pagina, se crea al primer uso) ====
const visorFotos = { el: null, items: [], indice: 0, nombre: '', alCambiar: null, foco: null };

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
            <button type="button" class="visor-flecha visor-anterior" aria-label="Anterior">
                <i class="fas fa-chevron-left"></i>
            </button>
            <img class="visor-imagen" alt="" onerror="this.src='${GALERIA_IMAGEN_DEFECTO}'">
            <video class="visor-video" controls playsinline preload="metadata" hidden></video>
            <button type="button" class="visor-flecha visor-siguiente" aria-label="Siguiente">
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
        if (e.key === 'Escape') { e.stopPropagation(); cerrarVisorFotos(); return; }
        // Con el video enfocado, las flechas adelantan/atrasan el video
        if (e.target && e.target.tagName === 'VIDEO') return;
        if (e.key === 'ArrowLeft') irAFotoVisor(visorFotos.indice - 1);
        else if (e.key === 'ArrowRight') irAFotoVisor(visorFotos.indice + 1);
    }, true);

    return el;
}

/**
 * items: [{tipo: 'imagen'|'video', src}] o rutas de fotos.
 * alCambiar(i): se llama al pasar a otro elemento (la tarjeta sigue a la foto).
 */
function abrirVisorFotos(items, indice, nombre, alCambiar) {
    if (!items || !items.length) return;
    if (!visorFotos.el) visorFotos.el = crearVisorFotos();

    items = items.map(it => typeof it === 'string' ? { tipo: 'imagen', src: it } : it);
    Object.assign(visorFotos, { items, nombre, alCambiar, foco: document.activeElement });
    const el = visorFotos.el;
    const varias = items.length > 1;
    el.classList.toggle('una-foto', !varias);
    el.querySelector('.visor-miniaturas').innerHTML = varias
        ? items.map((m, i) => htmlMiniaturaGaleria(m, i, items.length, 'visor-miniatura')).join('')
        : '';

    el.hidden = false;
    document.body.classList.add('visor-fotos-abierto');
    irAFotoVisor(indice || 0);
    el.querySelector('.visor-cerrar').focus();
}

function irAFotoVisor(indice) {
    const { el, items } = visorFotos;
    if (!el || !items.length) return;
    const i = (indice + items.length) % items.length;
    visorFotos.indice = i;
    const item = items[i];

    const img = el.querySelector('.visor-imagen');
    const video = el.querySelector('.visor-video');
    detenerVideoVisor();
    if (item.tipo === 'video') {
        img.hidden = true;
        video.hidden = false;
        video.src = item.src;
        video.setAttribute('aria-label', `${visorFotos.nombre} - video`);
        // Se abrio con un toque/clic: el navegador permite reproducir con sonido
        video.play().catch(() => { /* el usuario lo inicia con los controles */ });
    } else {
        video.hidden = true;
        img.hidden = false;
        img.src = item.src;
        img.alt = `${visorFotos.nombre} - foto ${i + 1} de ${items.length}`;
    }
    el.querySelector('.visor-contador').textContent = items.length > 1 ? `${i + 1} / ${items.length}` : '';

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

function detenerVideoVisor() {
    const video = visorFotos.el && visorFotos.el.querySelector('.visor-video');
    if (video && video.getAttribute('src')) {
        video.pause();
        video.removeAttribute('src');
        video.load();
    }
}

function cerrarVisorFotos() {
    const el = visorFotos.el;
    if (!el || el.hidden) return;
    detenerVideoVisor();
    el.hidden = true;
    document.body.classList.remove('visor-fotos-abierto');
    if (visorFotos.foco && visorFotos.foco.focus) visorFotos.foco.focus();
}
