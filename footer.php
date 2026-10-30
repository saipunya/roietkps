  <?php if (!empty($isAuthPage)): ?>
    <footer class="auth-footer">สำนักงานสหกรณ์จังหวัดร้อยเอ็ด · ระบบสารสนเทศ กพส.</footer>
  <?php else: ?>
    <footer class="app-footer"><span>ระบบสารสนเทศกองทุนพัฒนาสหกรณ์</span><span>RoietKPS · สำนักงานสหกรณ์จังหวัดร้อยเอ็ด</span></footer>
  <?php endif; ?>
</div>
<?php if (empty($isAuthPage)): ?>
<div class="modal fade" id="recordModal" tabindex="-1" aria-labelledby="recordModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
    <div class="modal-header"><h2 class="modal-title" id="recordModalTitle">รายละเอียดรายการ</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิดรายละเอียด"></button></div>
    <div class="modal-body"><dl id="recordDetails" class="detail-list mb-0"></dl></div>
    <div class="modal-footer"><button type="button" class="btn btn-primary" data-bs-dismiss="modal">ปิดรายละเอียด</button></div>
  </div></div>
</div>
<?php endif; ?>
<?php if (empty($isAuthPage) && empty($currentUser)): ?>
  <?php require __DIR__ . '/includes/login-modal.php'; ?>
<?php endif; ?>
<div id="appMessage" class="visually-hidden" role="status" aria-live="polite"></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
<?php if (!empty($hasPublicCharts)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js"></script>
<script src="assets/js/dashboard-charts.js?v=2"></script>
<?php endif; ?>
<script src="assets/js/app.js?v=11"></script>
</body>

</html>
