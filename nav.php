<aside class="app-sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="appSidebar" aria-labelledby="sidebarTitle">
  <div class="sidebar-brand">
    <a class="brand-link" href="<?= !empty($isWorkspace) ? 'dashboard.php' : 'index.php' ?>" aria-label="RoietKPS <?= !empty($isWorkspace) ? 'แดชบอร์ด' : 'หน้าแรก' ?>"><span
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
      <?php if (!empty($isWorkspace) && $item['permission'] !== null && !account_can($currentUser, $item['permission'])) {
          continue;
      } ?>
      <?php $navigationUrl = (!empty($isWorkspace) ? 'dashboard.php' : 'index.php') . ($key === 'dashboard' ? '' : '?page=' . rawurlencode($key)); ?>
      <a class="sidebar-link<?= $page === $key ? ' active' : '' ?>" href="<?= e($navigationUrl) ?>"
        <?= $page === $key ? ' aria-current="page"' : '' ?>><?= app_icon($item['icon']) ?><span><?= e($key === 'dashboard' ? (!empty($isWorkspace) ? 'แดชบอร์ด' : 'หน้าแรก') : $item['title']) ?></span></a>
      <?php endforeach; ?>
    </nav>
    <?php if (!empty($isWorkspace)): ?><a class="sidebar-link mt-3" href="index.php"><?= app_icon('building') ?><span>หน้าเว็บไซต์</span></a>
    <?php elseif ($currentUser): ?><a class="sidebar-link mt-3" href="dashboard.php"><?= app_icon('dashboard') ?><span>เข้าสู่ Dashboard</span></a>
    <?php else: ?><a class="sidebar-link mt-3" href="register.php"><?= app_icon('user') ?><span>สมัครสมาชิก</span></a><?php endif; ?>
    <div class="sidebar-note"><span class="sidebar-note-icon"><?= app_icon('leaf') ?></span>
      <p>ข้อมูลเป็นระบบ<br><strong>ติดตามงานได้ง่ายขึ้น</strong></p>
    </div>
  </div>
  <div class="sidebar-footer"><?= app_icon('pin') ?><span>จังหวัดร้อยเอ็ด</span></div>
</aside>
