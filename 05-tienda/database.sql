-- ============================================
-- SCRIPT SQL PARA MÓDULO DE TIENDA VIRTUAL
-- Base de datos: tucultur_asociados
-- ============================================

-- Tabla de categorías de productos
CREATE TABLE IF NOT EXISTS categorias (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    descripcion TEXT,
    imagen VARCHAR(255),
    activo TINYINT(1) DEFAULT 1,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla de productos
CREATE TABLE IF NOT EXISTS productos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(255) NOT NULL,
    descripcion TEXT,
    descripcion_corta VARCHAR(500),
    precio DECIMAL(12, 2) NOT NULL,
    precio_oferta DECIMAL(12, 2) DEFAULT NULL,
    imagen VARCHAR(255),
    imagenes_adicionales TEXT, -- JSON con array de imágenes
    categoria_id INT,
    stock INT DEFAULT 0,
    sku VARCHAR(50),
    marca VARCHAR(100),
    destacado TINYINT(1) DEFAULT 0,
    activo TINYINT(1) DEFAULT 1,
    vistas INT DEFAULT 0,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    fecha_actualizacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (categoria_id) REFERENCES categorias(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla de pedidos
CREATE TABLE IF NOT EXISTS pedidos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    numero_pedido VARCHAR(20) UNIQUE NOT NULL,
    usuario_id INT NOT NULL, -- Relacionado con tabla 'registros'
    subtotal DECIMAL(12, 2) NOT NULL,
    descuento DECIMAL(12, 2) DEFAULT 0,
    impuestos DECIMAL(12, 2) DEFAULT 0,
    costo_envio DECIMAL(12, 2) DEFAULT 0,
    total DECIMAL(12, 2) NOT NULL,
    estado ENUM('pendiente', 'confirmado', 'procesando', 'enviado', 'entregado', 'cancelado') DEFAULT 'pendiente',
    metodo_pago VARCHAR(50),
    -- Datos de envío
    nombre_envio VARCHAR(100),
    apellido_envio VARCHAR(100),
    telefono_envio VARCHAR(20),
    email_envio VARCHAR(100),
    direccion_envio TEXT,
    ciudad_envio VARCHAR(100),
    departamento_envio VARCHAR(100),
    codigo_postal VARCHAR(20),
    notas TEXT,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    fecha_actualizacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla de detalle de pedidos
CREATE TABLE IF NOT EXISTS detalle_pedido (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pedido_id INT NOT NULL,
    producto_id INT NOT NULL,
    nombre_producto VARCHAR(255) NOT NULL, -- Guardar nombre en caso de que el producto sea eliminado
    cantidad INT NOT NULL,
    precio_unitario DECIMAL(12, 2) NOT NULL,
    subtotal DECIMAL(12, 2) NOT NULL,
    FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE CASCADE,
    FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla de carrito de compras
CREATE TABLE IF NOT EXISTS carrito (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT, -- NULL para usuarios no logueados (se usa session_id)
    session_id VARCHAR(255),
    producto_id INT NOT NULL,
    cantidad INT NOT NULL DEFAULT 1,
    fecha_agregado TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla de favoritos/wishlist
CREATE TABLE IF NOT EXISTS favoritos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    producto_id INT NOT NULL,
    fecha_agregado TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE,
    UNIQUE KEY unique_favorito (usuario_id, producto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla de reseñas de productos
CREATE TABLE IF NOT EXISTS resenas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    producto_id INT NOT NULL,
    usuario_id INT NOT NULL,
    calificacion INT NOT NULL CHECK (calificacion >= 1 AND calificacion <= 5),
    titulo VARCHAR(255),
    comentario TEXT,
    aprobado TINYINT(1) DEFAULT 0,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Índices para mejorar rendimiento
CREATE INDEX idx_productos_categoria ON productos(categoria_id);
CREATE INDEX idx_productos_activo ON productos(activo);
CREATE INDEX idx_productos_destacado ON productos(destacado);
CREATE INDEX idx_pedidos_usuario ON pedidos(usuario_id);
CREATE INDEX idx_pedidos_estado ON pedidos(estado);
CREATE INDEX idx_carrito_usuario ON carrito(usuario_id);
CREATE INDEX idx_carrito_session ON carrito(session_id);

-- ============================================
-- DATOS INICIALES (Categorías y productos de ejemplo)
-- ============================================

-- Insertar categorías
INSERT INTO categorias (nombre, descripcion, imagen) VALUES
('Electrónicos', 'Dispositivos electrónicos, gadgets y accesorios tecnológicos', 'electronicos.jpg'),
('Sistemas y Software', 'Software, licencias y soluciones tecnológicas', 'software.jpg'),
('Ropa', 'Prendas de vestir para hombres, mujeres y niños', 'ropa.jpg'),
('Calzado', 'Zapatos, tenis y todo tipo de calzado', 'calzado.jpg'),
('Hogar', 'Artículos para el hogar y decoración', 'hogar.jpg'),
('Otros', 'Productos varios y accesorios', 'otros.jpg');

-- Insertar productos de ejemplo
INSERT INTO productos (nombre, descripcion, descripcion_corta, precio, precio_oferta, categoria_id, stock, sku, marca, destacado) VALUES
-- Electrónicos
('Audífonos Bluetooth Premium', 'Audífonos inalámbricos con cancelación de ruido activa, batería de larga duración de hasta 30 horas, micrófono integrado para llamadas, estuche de carga incluido. Compatible con todos los dispositivos Bluetooth.', 'Audífonos inalámbricos con cancelación de ruido', 189000, 159000, 1, 50, 'AUD-BT-001', 'TechSound', 1),
('Smart Watch Deportivo', 'Reloj inteligente resistente al agua IP68, monitor de ritmo cardíaco, GPS integrado, más de 20 modos deportivos, notificaciones inteligentes, batería de 7 días.', 'Reloj inteligente con GPS y monitor cardíaco', 299000, NULL, 1, 35, 'SW-DEP-001', 'FitPro', 1),
('Parlante Bluetooth Portátil', 'Parlante inalámbrico con sonido 360°, resistente al agua IPX7, batería de 12 horas, luces LED RGB, micrófono para llamadas.', 'Parlante portátil con sonido envolvente', 145000, 129000, 1, 80, 'SPK-BT-001', 'SoundMax', 0),
('Cargador Inalámbrico Rápido', 'Base de carga inalámbrica compatible con Qi, carga rápida de 15W, diseño antideslizante, indicador LED.', 'Cargador inalámbrico compatible con Qi', 65000, NULL, 1, 100, 'CHG-WL-001', 'PowerPlus', 0),
('Proyector HD Portátil', 'Mini proyector LED con resolución 1080p nativa, brillo de 5000 lúmenes, conectividad WiFi y Bluetooth, compatible con HDMI/USB.', 'Proyector portátil Full HD con WiFi', 489000, 449000, 1, 20, 'PRJ-HD-001', 'ViewMax', 1),

-- Software
('Antivirus Premium 1 Año', 'Licencia de antivirus con protección en tiempo real, firewall avanzado, protección web, VPN incluida para 3 dispositivos.', 'Protección completa para tu equipo', 89000, 69000, 2, 999, 'SW-AV-001', 'SecureShield', 1),
('Office Suite Profesional', 'Suite ofimática completa con procesador de textos, hojas de cálculo, presentaciones y más. Licencia perpetua.', 'Suite ofimática profesional completa', 249000, NULL, 2, 999, 'SW-OFF-001', 'DocMaster', 0),
('Editor de Video Pro', 'Software profesional de edición de video con efectos especiales, corrección de color, exportación 4K. Licencia de 1 año.', 'Editor de video profesional', 179000, 149000, 2, 999, 'SW-VID-001', 'VideoEdit Pro', 0),

-- Ropa
('Camiseta Algodón Premium', 'Camiseta 100% algodón peinado, corte regular, costuras reforzadas. Disponible en varios colores. Tallas S-XXL.', 'Camiseta de algodón de alta calidad', 45000, 35000, 3, 200, 'CAM-ALG-001', 'StyleWear', 0),
('Chaqueta Deportiva Impermeable', 'Chaqueta con tecnología impermeable y transpirable, capucha ajustable, bolsillos con cremallera, ideal para outdoor.', 'Chaqueta impermeable para deportes', 189000, NULL, 3, 60, 'CHQ-DEP-001', 'SportMax', 1),
('Pantalón Jogger Urbano', 'Pantalón jogger de algodón french terry, cintura elástica con cordón, bolsillos laterales, puños elásticos.', 'Pantalón jogger cómodo y moderno', 79000, 65000, 3, 150, 'PNT-JOG-001', 'UrbanStyle', 0),
('Sudadera con Capucha', 'Sudadera hoodie de algodón perchado, bolsillo canguro, capucha con cordón ajustable. Disponible en varios colores.', 'Sudadera hoodie cómoda', 95000, NULL, 3, 120, 'SUD-CAP-001', 'StyleWear', 1),

-- Calzado
('Zapatillas Running Pro', 'Zapatillas deportivas con tecnología de amortiguación, suela de goma antideslizante, malla transpirable, plantilla memory foam.', 'Zapatillas para correr con amortiguación', 259000, 219000, 4, 75, 'ZAP-RUN-001', 'SpeedRun', 1),
('Botines Casuales', 'Botines de cuero sintético de alta calidad, suela antideslizante, forro interior suave, diseño moderno.', 'Botines elegantes y cómodos', 189000, NULL, 4, 45, 'BOT-CAS-001', 'UrbanStep', 0),
('Sandalias Deportivas', 'Sandalias con correas ajustables, suela ergonómica, material resistente al agua, ideal para actividades al aire libre.', 'Sandalias cómodas para outdoor', 89000, 75000, 4, 90, 'SAN-DEP-001', 'AdventureWalk', 0),

-- Hogar
('Lámpara LED Inteligente', 'Lámpara de escritorio con luz LED regulable, control táctil, puerto USB de carga, 5 niveles de brillo, luz cálida/fría.', 'Lámpara inteligente con control táctil', 79000, 65000, 5, 60, 'LAM-LED-001', 'LightHome', 0),
('Set de Organizadores', 'Set de 6 cajas organizadoras de tela resistente, diferentes tamaños, plegables, ideales para closet y cajones.', 'Organizadores de tela plegables', 55000, NULL, 5, 100, 'ORG-SET-001', 'HomeOrg', 0),

-- Otros
('Mochila Multifuncional', 'Mochila con compartimento para laptop de hasta 15.6", puerto USB externo, múltiples bolsillos, resistente al agua.', 'Mochila para laptop con USB', 125000, 99000, 6, 80, 'MCH-MUL-001', 'TravelPro', 1),
('Botella Térmica 750ml', 'Botella de acero inoxidable doble pared, mantiene bebidas frías 24h o calientes 12h, libre de BPA, tapa antigoteo.', 'Botella térmica de acero inoxidable', 49000, NULL, 6, 150, 'BOT-TER-001', 'EcoTherm', 0);
