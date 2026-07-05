-- Recalcular costo_calculado en todos los productos (ejecutar tras migración 004)
UPDATE productos p
SET p.costo_calculado = (
    SELECT IFNULL(SUM(i.costo_actual * r.cantidad_requerida), 0)
    FROM recetas r JOIN insumos i ON i.id = r.insumo_id
    WHERE r.producto_id = p.id
)
WHERE p.activo = 1;

-- Verificar resultado
SELECT nombre, precio_venta, costo_calculado,
       ROUND(precio_venta - costo_calculado, 0) AS margen_bruto_ingredientes
FROM productos WHERE activo = 1;
