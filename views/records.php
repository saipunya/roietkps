<?php
$definitions = [
    'registry' => ['rows' => $cooperatives, 'statuses' => ['ดำเนินงาน', 'รอตรวจสอบ'], 'placeholder' => 'ชื่อ รหัสสหกรณ์ หรืออำเภอ'],
    'loans' => ['rows' => $loans, 'statuses' => ['รับคำขอ', 'รอเอกสาร', 'อยู่ระหว่างพิจารณา', 'อนุมัติ'], 'placeholder' => 'ชื่อสหกรณ์ หรือเลขที่คำขอ'],
    'debt' => ['rows' => $debts, 'statuses' => ['ใกล้ครบกำหนด', 'ค้างชำระ', 'ปกติ'], 'placeholder' => 'ชื่อสหกรณ์ หรือเลขที่สัญญา'],
];
$definition = $definitions[$page];
$requestedStatus = isset($_GET['status']) && is_string($_GET['status']) ? $_GET['status'] : '';
$initialStatus = in_array($requestedStatus, $definition['statuses'], true) ? $requestedStatus : '';
?>
<section class="surface records-section" aria-label="<?= e($pageTitle) ?>">
  <div class="records-toolbar">
    <div class="search-field"><label class="visually-hidden" for="recordSearch">ค้นหา<?= e($pageTitle) ?></label><?= app_icon('search') ?><input class="form-control" type="search" id="recordSearch" placeholder="<?= e($definition['placeholder']) ?>" autocomplete="off"></div>
    <div class="status-filter"><label class="visually-hidden" for="statusFilter">กรองสถานะ</label><select class="form-select" id="statusFilter"><option value="">ทุกสถานะ</option><?php foreach ($definition['statuses'] as $status): ?><option value="<?= e($status) ?>"<?= $initialStatus === $status ? ' selected' : '' ?>><?= e($status) ?></option><?php endforeach; ?></select></div>
  </div>
  <div class="results-summary"><span id="resultCount" role="status" aria-live="polite">ทั้งหมด <?= count($definition['rows']) ?> รายการ</span><button class="btn reset-filter" type="button" id="resetFilters" hidden>ล้างตัวกรอง</button></div>
  <div class="records-wrap">
    <table class="table records-table mb-0" id="recordsTable">
      <caption class="visually-hidden"><?= e($pageTitle) ?> ข้อมูลสมมติ</caption>
      <thead><tr>
        <th scope="col"><?= $page === 'registry' ? 'ชื่อสหกรณ์ / รหัส' : 'สหกรณ์ / เลขที่' . ($page === 'loans' ? 'คำขอ' : 'สัญญา') ?></th>
        <?php if ($page === 'registry'): ?><th scope="col">อำเภอ</th><th scope="col">ประเภท</th>
        <?php elseif ($page === 'loans'): ?><th scope="col" class="amount-cell">วงเงินที่ขอ (บาท)</th><th scope="col">วันที่รับคำขอ</th>
        <?php else: ?><th scope="col" class="amount-cell">เงินต้นคงเหลือ (บาท)</th><th scope="col">ครบกำหนดชำระ</th><?php endif; ?>
        <th scope="col">สถานะ</th><th scope="col" data-no-export><span class="visually-hidden">รายละเอียด</span></th>
      </tr></thead>
      <tbody>
        <?php foreach ($definition['rows'] as $record):
            $coop = $page === 'registry' ? $record : $cooperatives[$record['coop']];
            $details = ['สหกรณ์' => $coop['name'], 'รหัสสหกรณ์' => $coop['id'], 'จังหวัด' => $coop['province'], 'อำเภอ' => $coop['district']];
            if ($page === 'registry') {
                $details['ประเภท'] = $record['type'];
            } elseif ($page === 'loans') {
                $details['เลขที่คำขอ'] = $record['id'];
                $details['วงเงินที่ขอ (บาท)'] = number_format($record['amount'], 2);
                $details['วันที่รับคำขอ'] = $record['date'];
            } else {
                $details['เลขที่สัญญา'] = $record['id'];
                $details['เงินต้นตามสัญญา (บาท)'] = number_format($record['principal'], 2);
                $details['รับชำระเงินต้นแล้ว (บาท)'] = number_format($record['paid'], 2);
                $details['เงินต้นคงเหลือ (บาท)'] = number_format($record['balance'], 2);
                $details['ครบกำหนดชำระ'] = $record['due'];
            }
            $details['สถานะ'] = $record['status'];
        ?>
        <tr data-record data-status="<?= e($record['status']) ?>"<?= $initialStatus !== '' && $initialStatus !== $record['status'] ? ' hidden' : '' ?>>
          <td class="record-primary"><strong><?= e($coop['name']) ?></strong><small><?= e($record['id']) ?></small></td>
          <?php if ($page === 'registry'): ?><td data-label="อำเภอ"><?= e($record['district']) ?></td><td data-label="ประเภท"><?= e($record['type']) ?></td>
          <?php elseif ($page === 'loans'): ?><td data-label="วงเงินที่ขอ" class="amount-cell"><?= e(number_format($record['amount'], 2)) ?><span class="mobile-unit"> บาท</span></td><td data-label="รับคำขอ"><?= e($record['date']) ?></td>
          <?php else: ?><td data-label="เงินต้นคงเหลือ" class="amount-cell"><?= e(number_format($record['balance'], 2)) ?><span class="mobile-unit"> บาท</span></td><td data-label="ครบกำหนด"><?= e($record['due']) ?></td><?php endif; ?>
          <td data-label="สถานะ"><?= status_badge($record['status']) ?></td>
          <td class="record-action" data-no-export><?= detail_button($details) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <div class="empty-state" id="emptyState" hidden><?= app_icon('search') ?><h2>ไม่พบรายการที่ค้นหา</h2><p>ลองเปลี่ยนคำค้นหาหรือเลือกสถานะอื่น</p><button class="btn btn-outline-primary" type="button" data-reset-filters>ล้างตัวกรอง</button></div>
  </div>
</section>
<?php if ($page === 'debt'): ?><p class="data-footnote"><?= app_icon('info') ?> ตัวอย่างแสดงเฉพาะเงินต้น ยังไม่รวมการคำนวณดอกเบี้ยและค่าปรับ</p><?php endif; ?>
