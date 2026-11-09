/**
 * Admin en celular (<= 768 px). En escritorio no cambia nada.
 * - Menu: las paginas ocultan la barra lateral (.admin-sidebar) en pantallas
 *   pequenas; se agrega un boton (☰) junto al titulo que la abre como panel.
 * - Tablas de 5 o mas columnas (.admin-tabla) se ven como tarjetas: cada celda
 *   lleva el titulo de su columna (data-label) y la de acciones queda abajo con
 *   los botones separados. Se reetiquetan solas cuando la pagina redibuja la tabla.
 *   #tabla-proveedores tiene su propio diseno en proveedores.html.
 * - Ventanas de aviso (SweetAlert) al 80% del ancho; formularios como hoja inferior.
 */
(function () {
    const ANCHO_MOVIL = 768;
    const MIN_COLUMNAS_TARJETA = 5;

    const estilosMovil = document.createElement('style');
    estilosMovil.textContent = `
        @media (max-width: ${ANCHO_MOVIL}px) {
            .admin-main { padding: 1rem 1rem 5rem !important; }
            .admin-header-bar { margin: -1rem -1rem 1rem !important; padding: 0.75rem 1rem !important; position: sticky; top: 0; z-index: 900; }
            .admin-header-bar h1 { font-size: 1.25rem !important; margin: 0; display: flex; align-items: center; gap: 0.4rem; }

            /* Tablas -> tarjetas */
            .admin-tabla-container:has(> .tabla-movil) { background: none; box-shadow: none; overflow: visible; padding: 0; }
            .tabla-movil, .tabla-movil tbody { display: block; width: 100%; }
            .tabla-movil thead { display: none; }
            .tabla-movil tr {
                display: block; background: #fff; border-radius: 14px;
                box-shadow: 0 1px 4px rgba(0, 0, 0, 0.08);
                padding: 0.75rem 1rem; margin-bottom: 0.75rem;
            }
            .tabla-movil tr:hover { background: #fff; }
            .tabla-movil td {
                display: flex; justify-content: space-between; align-items: center; gap: 0.75rem;
                padding: 0.45rem 0 !important; border-bottom: 1px solid #f0f0f0; text-align: right !important;
                font-size: 0.92rem; word-break: break-word; min-width: 0;
            }
            .tabla-movil td::before {
                content: attr(data-label); flex-shrink: 0; text-align: left;
                font-weight: 600; color: #777; font-size: 0.82rem;
            }
            .tabla-movil td:last-child { border-bottom: none; }
            /* Contenido de la celda agrupado a la derecha (varios datos juntos, no repartidos) */
            .tabla-movil .td-valor { display: block; text-align: right; min-width: 0; }
            .tabla-movil .td-valor small, .tabla-movil .td-valor .medio-pago { display: block; }
            /* Tarjeta con foto: foto a la izquierda y nombre a su lado como encabezado */
            /* Espacio fijo entre la foto y el nombre, y entre el encabezado y los datos */
            .tabla-movil tr.con-imagen { display: grid; grid-template-columns: 56px 1fr; column-gap: 1rem; }
            .tabla-movil tr.con-imagen > td { grid-column: 1 / -1; }
            .tabla-movil tr.con-imagen > td.td-imagen { grid-column: 1; grid-row: 1; padding: 0 0 0.75rem !important; border: none; align-self: start; }
            .tabla-movil tr.con-imagen > td.td-titulo { padding: 0 0 0.75rem !important; align-self: center; }
            .tabla-movil tr.con-imagen > td:nth-child(3) { border-top: 1px solid #f0f0f0; }
            .tabla-movil tr.con-imagen > td.td-imagen::before { display: none; }
            .tabla-movil tr.con-imagen > td.td-imagen img { width: 56px !important; height: 56px !important; border-radius: 10px; object-fit: cover; }
            .tabla-movil tr.con-imagen > td.td-titulo { grid-column: 2; grid-row: 1; border: none; }
            .tabla-movil td.td-titulo .td-valor { text-align: left; }
            /* Primera celda: titulo de la tarjeta */
            .tabla-movil td.td-titulo { justify-content: flex-start; text-align: left !important; font-size: 1.02rem; padding-top: 0.1rem !important; }
            .tabla-movil td.td-titulo::before { display: none; }
            .tabla-movil td.td-titulo img { width: 56px !important; height: 56px !important; border-radius: 10px; }
            /* Celdas de mensaje (cargando, sin datos) */
            .tabla-movil td[colspan] { display: block; text-align: center !important; border: none; }
            .tabla-movil td[colspan]::before { display: none; }
            /* Acciones: fila inferior con los botones separados */
            .tabla-movil td.td-acciones { justify-content: flex-end; flex-wrap: wrap; gap: 0.6rem; padding-top: 0.7rem !important; }
            .tabla-movil td.td-acciones::before { display: none; }
            .tabla-movil td.td-acciones .td-valor { display: flex; flex-wrap: wrap; gap: 0.6rem; justify-content: flex-end; }
            .tabla-movil .td-valor select { max-width: 100%; }
            .tabla-movil td.td-acciones .td-valor > div { display: flex; flex-wrap: wrap; gap: 0.6rem; justify-content: flex-end; }
            .tabla-movil .btn-accion {
                min-width: 42px; height: 42px; margin: 0 !important;
                display: inline-flex; align-items: center; justify-content: center; gap: 0.3rem;
            }
            .tabla-movil select { font-size: 16px; padding: 0.4rem 0.5rem !important; max-width: 60%; }

            /* Botones de accion en general: separados, nunca pegados */
            .btn-accion + .btn-accion { margin-left: 0.5rem; }

            /* Avisos al 80% del ancho y mas compactos */
            .swal2-popup:not(.swal2-toast) { width: 80vw !important; max-width: 80vw !important; padding: 1rem 0.9rem 1.1rem !important; font-size: 0.9rem !important; }
            .swal2-popup:not(.swal2-toast) .swal2-icon { width: 3.6em; height: 3.6em; margin: 0.6em auto 0.3em; }
            .swal2-popup:not(.swal2-toast) .swal2-icon .swal2-icon-content { font-size: 2.4em; }
            .swal2-popup:not(.swal2-toast) .swal2-title { font-size: 1.2rem; padding: 0.4em 0.5em 0; }
            .swal2-popup:not(.swal2-toast) .swal2-html-container { font-size: 0.92rem; margin: 0.6em 0.4em 0.2em; }
            .swal2-popup:not(.swal2-toast) .swal2-actions { margin-top: 0.9em; gap: 0.4rem; }
            .swal2-popup:not(.swal2-toast) .swal2-styled { margin: 0; padding: 0.55em 1.2em; font-size: 0.95rem; }
            .swal2-popup:not(.swal2-toast) .swal2-input,
            .swal2-popup:not(.swal2-toast) .swal2-textarea { width: auto; margin: 0.8em 0.2em 0.2em; font-size: 16px; }

            /* Formularios (modales) como hoja que sube desde abajo */
            .modal.activo { align-items: flex-end !important; padding: 0 !important; }
            .modal .modal-contenido {
                max-width: 100% !important; width: 100% !important; margin: 0 !important;
                max-height: 92vh; overflow-y: auto; border-radius: 18px 18px 0 0 !important;
                padding-bottom: calc(1rem + env(safe-area-inset-bottom)) !important;
            }
            /* Titulo del formulario fijo arriba, sin dejar ver el contenido que pasa por detras */
            .modal .modal-contenido { padding: 0 1.25rem 1.25rem !important; }
            .modal .modal-header {
                position: sticky; top: 0; z-index: 5; background: var(--color-fondo-claro, #fff);
                margin: 0 -1.25rem 1rem !important; padding: 1rem 1.25rem !important;
                border-bottom: 1px solid #eee;
            }
            .modal .modal-header h2 { font-size: 1.2rem; margin: 0; }
            .modal .form-fila { display: block; }
            .modal input, .modal select, .modal textarea { font-size: 16px; }
        }
    `;
    document.head.appendChild(estilosMovil);

    /** Pone el titulo de su columna en cada celda y marca titulo y acciones. */
    function etiquetarTabla(tabla) {
        const titulos = Array.from(tabla.querySelectorAll('thead th')).map(th => th.textContent.trim());
        tabla.querySelectorAll('tbody tr').forEach(tr => {
            const celdas = Array.from(tr.children).filter(c => c.tagName === 'TD');
            // Si la primera celda es solo una foto, el titulo de la tarjeta es la segunda
            const primeraEsFoto = celdas.length > 1 && !!celdas[0].querySelector('img') &&
                celdas[0].textContent.trim() === '';
            tr.classList.toggle('con-imagen', primeraEsFoto);
            const indiceTitulo = primeraEsFoto ? 1 : 0;
            celdas.forEach((td, i) => {
                if (td.hasAttribute('colspan')) return;
                td.setAttribute('data-label', titulos[i] || '');
                td.classList.toggle('td-imagen', primeraEsFoto && i === 0);
                td.classList.toggle('td-titulo', i === indiceTitulo);
                // Agrupa el contenido en un solo bloque (se mueven los nodos: no se pierden eventos)
                if (!td.querySelector(':scope > .td-valor') && !(primeraEsFoto && i === 0)) {
                    const valor = document.createElement('span');
                    valor.className = 'td-valor';
                    while (td.firstChild) valor.appendChild(td.firstChild);
                    td.appendChild(valor);
                }
                const soloBotones = td.querySelector('button, .btn-accion') &&
                    !td.querySelector('select, input') && td.textContent.replace(/\s/g, '').length < 25;
                const columnaAcciones = /acci[oó]n|^ver$/i.test(titulos[i] || '');
                td.classList.toggle('td-acciones', i === celdas.length - 1 && i > 0 &&
                    !!td.querySelector('button, .btn-accion') && (columnaAcciones || !!soloBotones));
            });
        });
    }

    function prepararTablas() {
        document.querySelectorAll('table.admin-tabla').forEach(tabla => {
            if (tabla.id === 'tabla-proveedores' || tabla.dataset.movil) return;
            if (tabla.querySelectorAll('thead th').length < MIN_COLUMNAS_TARJETA) return;
            tabla.dataset.movil = '1';
            tabla.classList.add('tabla-movil');
            etiquetarTabla(tabla);
            const cuerpo = tabla.querySelector('tbody') || tabla;
            new MutationObserver(() => etiquetarTabla(tabla)).observe(cuerpo, { childList: true });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', prepararTablas);
    } else {
        prepararTablas();
    }

    const estilos = document.createElement('style');
    estilos.textContent = `
        .btn-menu-admin { display: none; }
        .fondo-menu-admin { display: none; }
        @media (max-width: ${ANCHO_MOVIL}px) {
            .btn-menu-admin {
                display: inline-flex; align-items: center; justify-content: center;
                width: 40px; height: 40px; margin-right: 0.6rem; vertical-align: middle;
                border: none; border-radius: 8px; background: var(--color-primario, #1e3a5f);
                color: #fff; font-size: 1.15rem; cursor: pointer;
            }
            body.menu-admin-abierto .admin-sidebar {
                display: block !important; overflow-y: auto;
                width: 260px; max-width: 85vw; z-index: 2000;
                box-shadow: 4px 0 18px rgba(0, 0, 0, 0.3);
                animation: entrarMenuAdmin 0.2s ease-out;
            }
            body.menu-admin-abierto .fondo-menu-admin {
                display: block; position: fixed; inset: 0; z-index: 1999;
                background: rgba(0, 0, 0, 0.45);
            }
            body.menu-admin-abierto { overflow: hidden; }
            .btn-cerrar-menu-admin {
                position: absolute; top: 0.6rem; right: 0.6rem;
                background: none; border: none; color: #fff; font-size: 1.5rem; cursor: pointer;
            }
        }
        @media (min-width: ${ANCHO_MOVIL + 1}px) { .btn-cerrar-menu-admin { display: none; } }
        @keyframes entrarMenuAdmin { from { transform: translateX(-100%); } to { transform: translateX(0); } }
        @media (prefers-reduced-motion: reduce) {
            body.menu-admin-abierto .admin-sidebar { animation: none; }
        }
    `;
    document.head.appendChild(estilos);

    function iniciar() {
        const barra = document.querySelector('.admin-sidebar');
        if (!barra) return;

        const titulo = document.querySelector('.admin-header-bar h1') || document.querySelector('.admin-header-bar');
        if (!titulo) return;

        const boton = document.createElement('button');
        boton.type = 'button';
        boton.className = 'btn-menu-admin';
        boton.setAttribute('aria-label', 'Abrir menú del administrador');
        boton.setAttribute('aria-expanded', 'false');
        boton.innerHTML = '<i class="fas fa-bars"></i>';
        titulo.insertBefore(boton, titulo.firstChild);

        const cerrarBtn = document.createElement('button');
        cerrarBtn.type = 'button';
        cerrarBtn.className = 'btn-cerrar-menu-admin';
        cerrarBtn.setAttribute('aria-label', 'Cerrar menú');
        cerrarBtn.innerHTML = '&times;';
        barra.appendChild(cerrarBtn);

        const fondo = document.createElement('div');
        fondo.className = 'fondo-menu-admin';
        document.body.appendChild(fondo);

        function abrir() {
            document.body.classList.add('menu-admin-abierto');
            boton.setAttribute('aria-expanded', 'true');
        }
        function cerrar() {
            document.body.classList.remove('menu-admin-abierto');
            boton.setAttribute('aria-expanded', 'false');
        }

        boton.addEventListener('click', abrir);
        cerrarBtn.addEventListener('click', cerrar);
        fondo.addEventListener('click', cerrar);
        document.addEventListener('keydown', e => { if (e.key === 'Escape') cerrar(); });
        // Al elegir una opcion se cierra (si la opcion pide confirmacion y se cancela, sigue abierto)
        barra.querySelectorAll('a').forEach(a => a.addEventListener('click', e => {
            if (!e.defaultPrevented) cerrar();
        }));
        window.addEventListener('resize', () => { if (window.innerWidth > ANCHO_MOVIL) cerrar(); });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})();
