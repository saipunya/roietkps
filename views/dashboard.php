<?php
$approved = array_filter($loans, function ($loan) { return $loan['status'] === 'อนุมัติ'; });
$pending = count($loans) - count($approved);
$overdue = count(array_filter($debts, function ($debt) { return $debt['status'] === 'ค้างชำระ'; }));
$dueSoon = count(array_filter($debts, function ($debt) { return $debt['status'] === 'ใกล้ครบกำหนด'; }));
$waitingDocuments = count(array_filter($loans, function ($loan) { return $loan['status'] === 'รอเอกสาร'; }));
$stats = [
    ['label' => 'สหกรณ์ในทะเบียน', 'value' => count($cooperatives), 'unit' => 'แห่ง', 'note' => 'ข้อมูลในพื้นที่รับผิดชอบ', 'icon' => 'building', 'tone' => 'green'],
    ['label' => 'วงเงินอนุมัติ', 'value' => number_format(array_sum(array_column($approved, 'amount')) / 1000000, 2), 'unit' => 'ล้านบาท', 'note' => count($approved) . ' คำขอที่อนุมัติแล้ว', 'icon' => 'check', 'tone' => 'green'],
    ['label' => 'รับชำระเงินต้นแล้ว', 'value' => number_format(array_sum(array_column($debts, 'paid')) / 1000000, 2), 'unit' => 'ล้านบาท', 'note' => 'ยอดรวมจากรายการตัวอย่าง', 'icon' => 'wallet', 'tone' => 'blue'],
    ['label' => 'บัญชีที่ต้องติดตาม', 'value' => $overdue + $dueSoon, 'unit' => 'บัญชี', 'note' => 'ค้างชำระ ' . $overdue . ' · ใกล้ครบกำหนด ' . $dueSoon, 'icon' => 'clock', 'tone' => 'amber'],
];
?>
<div class="section-label"><span>สรุปข้อมูล</span><span class="snapshot-date">ข้อมูลตัวอย่าง ณ 26 ตุลาคม 2569</span></div>
<section class="stats-grid" aria-label="สรุปข้อมูลกองทุน">
  <?php foreach ($stats as $stat): ?>
    <article class="stat-card">
      <div class="stat-top"><h2><?= e($stat['label']) ?></h2><span class="stat-icon tone-<?= e($stat['tone']) ?>"><?= app_icon($stat['icon']) ?></span></div>
      <p class="stat-value"><?= e($stat['value']) ?><span><?= e($stat['unit']) ?></span></p>
      <p class="stat-note"><?= e($stat['note']) ?></p>
    </article>
  <?php endforeach; ?>
</section>

<div class="dashboard-grid">
  <section class="surface">
    <div class="surface-heading"><div><h2>งานที่ต้องติดตาม</h2><p>เริ่มจากรายการที่ต้องดำเนินการก่อน</p></div><span class="subtle-icon"><?= app_icon('clock') ?></span></div>
    <div class="task-list">
      <a class="task-item" href="index.php?page=debt&amp;status=<?= e(rawurlencode('ค้างชำระ')) ?>"><span class="task-icon tone-red"><?= app_icon('alert') ?></span><span class="task-copy"><strong>บัญชีค้างชำระ</strong><small>ตรวจสอบและบันทึกผลการติดตาม</small></span><span class="task-count"><?= e($overdue) ?><small>บัญชี</small></span><?= app_icon('chevron') ?></a>
      <a class="task-item" href="index.php?page=debt&amp;status=<?= e(rawurlencode('ใกล้ครบกำหนด')) ?>"><span class="task-icon tone-amber"><?= app_icon('clock') ?></span><span class="task-copy"><strong>ใกล้ครบกำหนดใน 30 วัน</strong><small>เตรียมติดตามการชำระเงิน</small></span><span class="task-count"><?= e($dueSoon) ?><small>บัญชี</small></span><?= app_icon('chevron') ?></a>
      <a class="task-item" href="index.php?page=loans&amp;status=<?= e(rawurlencode('รอเอกสาร')) ?>"><span class="task-icon tone-blue"><?= app_icon('document') ?></span><span class="task-copy"><strong>คำขอที่รอเอกสาร</strong><small>ตรวจสอบเอกสารประกอบคำขอ</small></span><span class="task-count"><?= e($waitingDocuments) ?><small>คำขอ</small></span><?= app_icon('chevron') ?></a>
    </div>
  </section>
  <section class="surface quick-panel">
    <div class="surface-heading"><div><h2>เข้าถึงงานหลัก</h2><p>เลือกงานที่คุณต้องการทำ</p></div></div>
    <a class="quick-link" href="index.php?page=registry"><span class="quick-icon"><?= app_icon('building') ?></span><span><strong>ค้นหาสหกรณ์</strong><small>ข้อมูลพื้นฐานและสถานะ</small></span><?= app_icon('arrow') ?></a>
    <a class="quick-link" href="index.php?page=loans"><span class="quick-icon"><?= app_icon('document') ?></span><span><strong>ตรวจสอบคำขอกู้</strong><small>รอดำเนินการ <?= e($pending) ?> คำขอ</small></span><?= app_icon('arrow') ?></a>
    <a class="quick-link" href="index.php?page=reports"><span class="quick-icon"><?= app_icon('chart') ?></span><span><strong>ดูรายงาน</strong><small>ตรวจสอบและส่งออกข้อมูล</small></span><?= app_icon('arrow') ?></a>
  </section>
</div>

<section class="surface recent-section">
  <div class="surface-heading"><div><h2>คำขอเงินกู้ล่าสุด</h2><p>รายการล่าสุดจากข้อมูลตัวอย่าง</p></div><a class="text-link" href="index.php?page=loans">ดูทั้งหมด <?= app_icon('arrow') ?></a></div>
  <div class="records-wrap">
    <table class="table records-table mb-0">
      <caption class="visually-hidden">คำขอเงินกู้ล่าสุด 4 รายการ ข้อมูลสมมติ</caption>
      <thead><tr><th scope="col">สหกรณ์ / เลขที่คำขอ</th><th scope="col" class="amount-cell">วงเงินที่ขอ (บาท)</th><th scope="col">สถานะ</th><th scope="col"><span class="visually-hidden">รายละเอียด</span></th></tr></thead>
      <tbody>
        <?php foreach (array_slice($loans, 0, 4) as $loan): ?>
          <tr>
            <td class="record-primary"><strong><?= e($cooperatives[$loan['coop']]['name']) ?></strong><small><?= e($loan['id']) ?></small></td>
            <td data-label="วงเงินที่ขอ" class="amount-cell"><?= e(number_format($loan['amount'], 2)) ?><span class="mobile-unit"> บาท</span></td>
            <td data-label="สถานะ"><?= status_badge($loan['status']) ?></td>
            <td class="record-action"><?= detail_button(['เลขที่คำขอ' => $loan['id'], 'สหกรณ์' => $cooperatives[$loan['coop']]['name'], 'วงเงินที่ขอ (บาท)' => number_format($loan['amount'], 2), 'วันที่รับคำขอ' => $loan['date'], 'สถานะ' => $loan['status']]) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
