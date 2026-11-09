/**
 * Menu del admin en celular (<= 768 px).
 * Las paginas del admin ocultan la barra lateral (.admin-sidebar) en pantallas
 * pequenas; este script agrega un boton (☰) junto al titulo que la abre como
 * panel deslizante sobre la pagina. En escritorio no cambia nada.
 */
(function () {
    const ANCHO_MOVIL = 768;

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
