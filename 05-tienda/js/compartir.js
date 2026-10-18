/**
 * Compartir un producto con un contacto (estilo Mercado Libre)
 * Tu Cultura es Progreso - Tienda Virtual
 *
 * Se comparte php/compartir.php?id=..., que entrega a WhatsApp, Facebook, etc.
 * el nombre, precio y foto del producto para la vista previa, y lleva al contacto
 * a producto.html?id=..., donde puede verlo y comprarlo.
 *
 * Uso: compartirProducto({ id, nombre })
 * - Celular: abre el menu de compartir del telefono (WhatsApp, Instagram, etc.).
 * - Computador: ventana con las redes y el enlace para copiar.
 */

function urlCompartirProducto(id) {
    const base = window.location.href.split(/[?#]/)[0].replace(/[^/]*$/, '');
    return `${base}php/compartir.php?id=${encodeURIComponent(id)}`;
}

async function compartirProducto(producto) {
    if (!producto || !producto.id) return;

    const url = urlCompartirProducto(producto.id);
    const texto = `Mira este producto en Tu Cultura es Progreso: ${producto.nombre}`;

    const esCelular = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
    if (esCelular && navigator.share) {
        try {
            await navigator.share({ title: producto.nombre, text: texto, url });
            return;
        } catch (error) {
            // AbortError = el usuario cerro el menu; con otro error se usa la ventana
            if (error && error.name === 'AbortError') return;
        }
    }

    mostrarVentanaCompartir(producto, url, texto);
}

function mostrarVentanaCompartir(producto, url, texto) {
    const u = encodeURIComponent(url);
    const t = encodeURIComponent(texto);
    const redes = [
        { clase: 'whatsapp', icono: '<i class="fab fa-whatsapp"></i>', nombre: 'WhatsApp', href: `https://wa.me/?text=${t}%20${u}` },
        { clase: 'facebook', icono: '<i class="fab fa-facebook-f"></i>', nombre: 'Facebook', href: `https://www.facebook.com/sharer/sharer.php?u=${u}` },
        { clase: 'x', icono: '<span aria-hidden="true">X</span>', nombre: 'X', href: `https://twitter.com/intent/tweet?text=${t}&url=${u}` },
        { clase: 'telegram', icono: '<i class="fab fa-telegram-plane"></i>', nombre: 'Telegram', href: `https://t.me/share/url?url=${u}&text=${t}` },
        { clase: 'correo', icono: '<i class="fas fa-envelope"></i>', nombre: 'Correo', href: `mailto:?subject=${encodeURIComponent(producto.nombre)}&body=${t}%0A${u}` }
    ];

    const nombreSeguro = String(producto.nombre)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

    Swal.fire({
        title: 'Compartir producto',
        html: `
            <p class="compartir-producto-nombre">${nombreSeguro}</p>
            <div class="compartir-redes">
                ${redes.map(r => `
                    <a class="compartir-red" href="${r.href}" target="_blank" rel="noopener">
                        <span class="btn-compartir btn-compartir-${r.clase}">${r.icono}</span>
                        <span>${r.nombre}</span>
                    </a>
                `).join('')}
            </div>
            <div class="compartir-enlace">
                <input type="text" id="compartir-enlace-input" value="${url}" readonly aria-label="Enlace del producto">
                <button type="button" id="compartir-copiar">Copiar</button>
            </div>
        `,
        showConfirmButton: false,
        showCloseButton: true,
        didOpen: () => {
            const input = document.getElementById('compartir-enlace-input');
            const boton = document.getElementById('compartir-copiar');
            input.addEventListener('click', () => input.select());
            boton.addEventListener('click', async () => {
                let copiado = false;
                try {
                    await navigator.clipboard.writeText(url);
                    copiado = true;
                } catch (error) {
                    // Sin acceso al portapapeles (http o navegador antiguo)
                    input.select();
                    copiado = document.execCommand && document.execCommand('copy');
                }
                boton.textContent = copiado ? '¡Copiado!' : 'Copia el enlace';
                if (copiado) boton.classList.add('copiado');
            });
        }
    });
}
