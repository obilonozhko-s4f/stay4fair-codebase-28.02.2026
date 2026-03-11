<?php
/**
 * Owner Monthly PDF Template
 *
 * Variables available:
 * @var array $d (from $pdf_data)
 */

$e = static function ($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};

$logo = 'https://stay4fair.com/wp-content/uploads/2025/12/gorizontal-color-4.webp';
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<style>
body {
    font-family: DejaVu Sans, Arial, sans-serif;
    font-size: 11px;
    color: #212F54;
    line-height: 1.4;
}
table { border-collapse: collapse; width: 100%; }
.header td { vertical-align: top; }
.logo img { height: 36px; }

.contact { text-align: right; font-size: 10px; color: #555; }
h1 { font-size: 18px; margin: 12px 0 6px 0; color: #082567; border-bottom: 2px solid #E0B849; padding-bottom: 5px; }

.info-box { margin-bottom: 20px; width: 100%; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px; background: #f8fafc; }
.info-box td { padding: 3px 5px; }
.info-label { font-weight: bold; color: #64748b; font-size: 10px; text-transform: uppercase; width: 120px; }

.data-table { margin-bottom: 20px; border: 1px solid #cbd5e1; }
.data-table th { background: #082567; color: #E0B849; font-weight: bold; text-align: left; padding: 8px; font-size: 10px; text-transform: uppercase; border-right: 1px solid #cbd5e1; }
.data-table td { padding: 8px; border-bottom: 1px solid #e2e8f0; border-right: 1px solid #e2e8f0; font-size: 11px; vertical-align: top; }
.data-table tr:nth-child(even) { background: #fdfdfd; }
.text-right { text-align: right !important; }
.total-row td { background: #f1f5f9; font-weight: bold; font-size: 13px; border-top: 2px solid #082567; }

.footer-note { font-size: 10px; color: #555; margin-top: 20px; border-top: 1px dashed #cbd5e1; padding-top: 10px; }
.highlight { font-weight: bold; color: #082567; }
</style>
</head>

<body>

<table class="header" style="margin-bottom: 15px;">
    <tr>
        <td class="logo"><img src="<?php echo $e($logo); ?>" alt="Stay4Fair"></td>
        <td class="contact">
            <strong>Stay4Fair.com</strong><br>
            Tel / WhatsApp: +49 176 24615269<br>
            E-Mail: business@stay4fair.com<br>
            Owner Portal: stay4fair.com/owner-login/
        </td>
    </tr>
</table>

<h1>Monatsabrechnung – <?php echo $e($d['month'] . ' / ' . $d['year']); ?></h1>

<table class="info-box">
    <tr>
        <td class="info-label">Vermieter:</td>
        <td><strong><?php echo $e($d['owner_name']); ?></strong></td>
        <td class="info-label">Adresse:</td>
        <td><?php echo $e($d['owner_address']); ?></td>
    </tr>
    <tr>
        <td class="info-label">Steuer-ID (VAT/DAC7):</td>
        <td><?php echo $e($d['owner_tax'] ?: '—'); ?></td>
        <td class="info-label">Auszahlungskonto (IBAN):</td>
        <td><strong><?php echo $e($d['owner_iban'] ?: 'Nicht hinterlegt'); ?></strong></td>
    </tr>
</table>

<table class="data-table">
    <thead>
        <tr>
            <th>ID</th>
            <th>Apartment & Adresse</th>
            <th>Check-in / Check-out</th>
            <th class="text-right">Gäste</th>
            <th class="text-right">Buchungspreis<br><span style="font-weight:normal; font-size:8px;">(Brutto)</span></th>
            <th class="text-right">S4F Provision<br><span style="font-weight:normal; font-size:8px;">(inkl. 19% MwSt)</span></th>
            <th class="text-right" style="background:#E0B849; color:#082567;">Auszahlung<br><span style="font-weight:normal; font-size:8px;">(Netto)</span></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($d['items'] as $item): ?>
            <tr>
                <td>#<?php echo $e($item['booking_id']); ?></td>
                <td>
                    <strong><?php echo $e($item['apt_title']); ?></strong><br>
                    <span style="font-size: 9px; color: #64748b;"><?php echo $e($item['apt_address']); ?></span>
                </td>
                <td><?php echo $e(date('d.m.y', strtotime($item['check_in']))); ?> – <?php echo $e(date('d.m.y', strtotime($item['check_out']))); ?></td>
                <td class="text-right"><?php echo $e($item['guests']); ?></td>
                <td class="text-right"><?php echo $e(number_format((float)$item['gross'], 2, ',', '.')); ?> €</td>
                
                <?php if ($item['model'] === 'model_b'): ?>
                    <td class="text-right">
                        <strong><?php echo $e(number_format((float)$item['prov_gross'], 2, ',', '.')); ?> €</strong><br>
                        <?php if ((float)$item['prov_gross'] > 0): ?>
                        <span style="font-size:8px; color:#64748b; font-weight:normal; line-height: 1.2; display: inline-block; margin-top: 3px;">
                            Netto: <?php echo $e(number_format((float)$item['prov_net'], 2, ',', '.')); ?> €<br>
                            19% MwSt: <?php echo $e(number_format((float)$item['prov_vat'], 2, ',', '.')); ?> €
                        </span>
                        <?php endif; ?>
                    </td>
                <?php else: ?>
                    <td class="text-right" style="color:#94a3b8;">— (Modell A)</td>
                <?php endif; ?>

                <td class="text-right highlight"><?php echo $e(number_format((float)$item['payout'], 2, ',', '.')); ?> €</td>
            </tr>
        <?php endforeach; ?>
        
        <tr class="total-row">
            <td colspan="4" class="text-right">GESAMT FÜR <?php echo $e($d['month'] . '/' . $d['year']); ?>:</td>
            <td class="text-right"><?php echo $e(number_format((float)$d['total_gross'], 2, ',', '.')); ?> €</td>
            <td class="text-right">
                <?php echo $e(number_format((float)$d['total_prov'], 2, ',', '.')); ?> €<br>
                <?php if ($d['total_prov'] > 0): ?>
                <span style="font-size:8px; color:#64748b; font-weight:normal;">
                    Netto: <?php echo $e(number_format((float)$d['total_prov_net'], 2, ',', '.')); ?> €<br>
                    19% MwSt: <?php echo $e(number_format((float)$d['total_prov_vat'], 2, ',', '.')); ?> €
                </span>
                <?php endif; ?>
            </td>
            <td class="text-right" style="color: #082567;"><?php echo $e(number_format((float)$d['total_net'], 2, ',', '.')); ?> €</td>
        </tr>
    </tbody>
</table>

<div class="footer-note">
    <p>Die Auszahlung erfolgt in der Regel innerhalb von 3–7 Werktagen nach Abreise des Gastes.</p>
    
    <?php if ($d['has_model_a']): ?>
        <p><strong>Für Buchungen nach Modell A (Direkt):</strong> Die Abführung der Beherbergungsteuer (City-Tax) für diese Buchungen wurde von Stay4Fair übernommen. Für die Versteuerung Ihrer Einkünfte sind Sie selbst verantwortlich.</p>
    <?php endif; ?>

    <?php if ($d['has_model_b']): ?>
        <p><strong>Für Buchungen nach Modell B (Vermittlung):</strong> Bitte beachten Sie, dass die erzielten Einkünfte aus der kurzfristigen Vermietung steuerpflichtig sind. Die Verantwortung für die korrekte Versteuerung sowie die Einhaltung aller steuerlichen Meldepflichten liegt beim Vermieter. Bitte prüfen Sie eigenständig die lokalen Satzungen bezüglich Beherbergungssteuer (City Tax).</p>
    <?php endif; ?>
    
    <p style="margin-top: 10px; font-weight: bold;">Stay4Fair unterstützt Sie mit der Bereitstellung der Buchungsdaten, übernimmt jedoch keine steuerliche Beratung oder Haftung.</p>
</div>

</body>
</html>
