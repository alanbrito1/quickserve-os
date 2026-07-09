<?php
/**
 * ventas/comprobante.php — Comprobante / recibo imprimible de una venta (Fase D, modo Interno).
 *
 * Genera un comprobante propio (no fiscal) de una venta, consciente del país (moneda, impuesto,
 * nombre del negocio). Es el modo **Interno** del subsistema de facturación; el modo **Legal**
 * (factura electrónica vía PAC del país) se enchufa como un driver aparte cuando esté integrado.
 */
require_once __DIR__ . '/../app/middleware/auth_check.php';
require_once __DIR__ . '/../app/models/facturacion/FacturacionModel.php';

$nav_activo = 'ventas';
permiso_requerir('ventas', 'solo_ver');

$id = (int)($_GET['id'] ?? 0);
$d  = $id > 0 ? FacturacionModel::datos_comprobante($id) : null;

$nav_sub = '';
include __DIR__ . '/../app/views/nav.php';

if (!$d) {
    echo '<div style="max-width:640px;margin:30px auto;padding:0 16px">'
       . '<div style="background:#fee2e2;color:#991b1b;border-radius:12px;padding:16px">No se encontró la venta solicitada.</div>'
       . '<p style="margin-top:12px"><a href="' . APP_BASE . '/ventas/historial.php">&larr; Volver al historial</a></p></div>';
    exit;
}

$v        = $d['venta'];
$imp      = $d['impuesto'];
$sim      = moneda_simbolo();
$fecha    = date('d/m/Y H:i', strtotime($v['fecha_venta']));
$bruto    = 0.0; foreach ($d['items'] as $it) $bruto += (float)$it['subtotal'];
$descuento = (float)($v['descuento_valor'] ?? 0);
$metodos = ['efectivo'=>'Efectivo','nequi'=>'Nequi','daviplata'=>'Daviplata','bancolombia'=>'Bancolombia',
            'fiado'=>'Fiado','obsequio'=>'Obsequio','transferencia'=>'Transferencia'];
$metodoLbl = $metodos[$v['metodo_pago']] ?? ucfirst((string)$v['metodo_pago']);
if (($v['metodo_pago'] ?? '') === 'fiado' && !empty($v['metodo_cobro'])) {
    $metodoLbl .= ' · cobrado con ' . ($metodos[$v['metodo_cobro']] ?? $v['metodo_cobro']);
}
$clienteNom = '';
if ($d['cliente']) {
    $clienteNom = trim(($d['cliente']['nombre'] ?? '') . ' ' . ($d['cliente']['apellido'] ?? ''));
    if (!empty($d['cliente']['empresa'])) $clienteNom .= ' (' . $d['cliente']['empresa'] . ')';
}
function h_($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<style>
  .cmp-wrap { max-width:560px; margin:20px auto 60px; padding:0 14px; }
  .cmp-bar  { display:flex; gap:8px; justify-content:flex-end; margin-bottom:12px; }
  .cmp-btn  { border:none; border-radius:8px; padding:9px 16px; font-size:13px; font-weight:600; cursor:pointer; text-decoration:none; }
  .cmp-print{ background:var(--brand,#e94f37); color:#fff; }
  .cmp-back { background:#e5e7eb; color:#111827; }
  .cmp { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:22px 22px 18px; box-shadow:0 1px 6px rgba(0,0,0,.06); }
  .cmp h1 { font-size:19px; font-weight:800; margin:0 0 2px; }
  .cmp .sub { font-size:12px; color:#6b7280; }
  .cmp .meta { display:flex; justify-content:space-between; flex-wrap:wrap; gap:6px; margin:14px 0; font-size:12.5px; color:#374151; }
  .cmp .meta b { color:#111827; }
  .cmp table { width:100%; border-collapse:collapse; margin:8px 0; font-size:13px; }
  .cmp th, .cmp td { text-align:left; padding:6px 4px; border-bottom:1px solid #f3f4f6; }
  .cmp th { font-size:11px; text-transform:uppercase; color:#6b7280; }
  .cmp td.num, .cmp th.num { text-align:right; }
  .cmp .tot { margin-top:10px; font-size:13px; }
  .cmp .tot .row { display:flex; justify-content:space-between; padding:3px 0; }
  .cmp .tot .grand { font-size:17px; font-weight:800; border-top:2px solid #111827; margin-top:6px; padding-top:8px; }
  .cmp .foot { margin-top:16px; font-size:11px; color:#9ca3af; text-align:center; }
  .cmp .aviso { background:#fffbeb; border:1px solid #fcd34d; color:#78350f; border-radius:8px; padding:9px 11px; font-size:12px; margin-bottom:12px; }
  @media print {
    body * { visibility:hidden; }
    .cmp, .cmp * { visibility:visible; }
    .cmp { position:absolute; left:0; top:0; width:100%; border:none; box-shadow:none; }
    .cmp-bar, nav, .nav, header { display:none !important; }
  }
</style>

<div class="cmp-wrap">
  <div class="cmp-bar">
    <a class="cmp-btn cmp-back" href="<?= APP_BASE ?>/ventas/historial.php">&larr; Historial</a>
    <button class="cmp-btn cmp-print" onclick="window.print()">🖨 Imprimir</button>
  </div>

  <div class="cmp">
    <?php if ($d['legal_pendiente']): ?>
      <div class="aviso">⚠️ <b>Modo legal seleccionado</b>, pero la factura electrónica aún no está integrada
        <?= $d['sistema_legal'] ? 'con ' . h_($d['sistema_legal']) : '' ?>. Se emite un comprobante interno mientras tanto.</div>
    <?php endif; ?>

    <h1><?= h_($d['negocio']) ?></h1>
    <div class="sub"><?= h_($d['tipo'] === 'legal' ? 'Factura' : 'Comprobante interno (no fiscal)') ?> · <?= h_($d['pais_nombre']) ?></div>

    <div class="meta">
      <div><b>N.º</b> <?= h_($d['numero']) ?></div>
      <div><b>Fecha</b> <?= h_($fecha) ?></div>
      <div><b>Venta</b> #<?= (int)$v['id'] ?></div>
    </div>
    <?php if ($clienteNom): ?><div class="meta" style="margin-top:-6px"><div><b>Cliente:</b> <?= h_($clienteNom) ?></div></div><?php endif; ?>

    <table>
      <tr><th>Producto</th><th class="num">Cant.</th><th class="num">Precio</th><th class="num">Subtotal</th></tr>
      <?php foreach ($d['items'] as $it): ?>
      <tr>
        <td><?= h_($it['nombre']) ?></td>
        <td class="num"><?= fmt_cantidad((float)$it['cantidad']) ?></td>
        <td class="num"><?= $sim . ' ' . fmt_moneda((float)$it['precio_unitario']) ?></td>
        <td class="num"><?= $sim . ' ' . fmt_moneda((float)$it['subtotal']) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>

    <div class="tot">
      <div class="row"><span>Subtotal</span><span><?= $sim . ' ' . fmt_moneda($bruto) ?></span></div>
      <?php if ($descuento > 0): ?>
        <div class="row"><span>Descuento</span><span>− <?= $sim . ' ' . fmt_moneda($descuento) ?></span></div>
      <?php endif; ?>
      <?php if ($imp['activo'] && $imp['tarifa'] > 0): ?>
        <div class="row"><span>Base gravable</span><span><?= $sim . ' ' . fmt_moneda($imp['base']) ?></span></div>
        <div class="row"><span><?= h_($imp['nombre']) ?> (<?= fmt_cantidad($imp['tarifa']) ?>%)</span><span><?= $sim . ' ' . fmt_moneda($imp['valor']) ?></span></div>
      <?php endif; ?>
      <div class="row grand"><span>Total</span><span><?= $sim . ' ' . fmt_moneda((float)$v['total']) ?></span></div>
      <div class="row" style="color:#6b7280"><span>Forma de pago</span><span><?= h_($metodoLbl) ?></span></div>
    </div>

    <div class="foot">
      <?= h_($d['negocio']) ?> · <?= h_(moneda_codigo()) ?> ·
      <?= $d['tipo'] === 'legal' ? 'Documento legal' : 'Comprobante interno — no válido como factura fiscal' ?>
    </div>
  </div>
</div>
</body>
</html>
