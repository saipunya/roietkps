<aside class="app-sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="appSidebar" aria-labelledby="sidebarTitle">
  <div class="sidebar-brand">
    <a class="brand-link" href="index.php" aria-label="RoietKPS หน้าภาพรวม"><span
        class="brand-mark"><?= app_icon('building') ?></span><span><strong
          id="sidebarTitle">RoietKPS</strong><small>ระบบสารสนเทศ กพส.</small></span></a>
    <button class="btn-close d-lg-none" type="button" data-bs-dismiss="offcanvas" data-bs-target="#appSidebar"
      aria-label="ปิดเมนูหลัก"></button>
  </div>
  <div class="sidebar-body">
    <p class="nav-caption">พื้นที่ทำงาน</p>
    <nav class="sidebar-nav" aria-label="เมนูหลัก">
      <?php foreach ($pages as $key => $item): ?>
      <?php if (!empty($item['hidden'])) {
          continue;
      } ?>
      <?php if ($key !== 'dashboard' && (!$currentUser || !account_can($currentUser, $item['permission']))) {
          continue;
      } ?>
      <?php if ($key === 'dashboard' && $currentUser && !account_can($currentUser, $item['permission'])) {
          continue;
      } ?>
      <a class="sidebar-link<?= $page === $key ? ' active' : '' ?>" href="index.php?page=<?= e($key) ?>"
        <?= $page === $key ? ' aria-current="page"' : '' ?>><?= app_icon($item['icon']) ?><span><?= e($key === 'dashboard' ? 'ภาพรวม' : $item['title']) ?></span></a>
      <?php endforeach; ?>
    </nav>
    <?php if (!$currentUser): ?><a class="sidebar-link mt-3"
      href="register.php"><?= app_icon('user') ?><span>สมัครสมาชิก</span></a><?php endif; ?>
    <div class="sidebar-note"><span class="sidebar-note-icon"><?= app_icon('leaf') ?></span>
      <p>ข้อมูลเป็นระบบ<br><strong>ติดตามงานได้ง่ายขึ้น</strong></p>
    </div>
  </div>
  <div class="sidebar-footer"><?= app_icon('pin') ?><span>จังหวัดร้อยเอ็ด</span></div>
</aside>