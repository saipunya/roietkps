<?php
$usesWorkspaceSidebar = !empty($isWorkspace) || !empty($currentUser);
$sidebarPages = $usesWorkspaceSidebar ? workspace_page_definitions() : $pages;
?>
<aside class="app-sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="appSidebar" aria-labelledby="sidebarTitle">
  <div class="sidebar-brand">
    <a class="brand-link" href="<?= $usesWorkspaceSidebar ? 'dashboard.php' : 'index.php' ?>" aria-label="RoietKPS <?= $usesWorkspaceSidebar ? 'แดชบอร์ด' : 'หน้าแรก' ?>"><span
        class="brand-mark"><?= app_icon('building') ?></span><span><strong
          id="sidebarTitle">RoietKPS</strong><small>ระบบสารสนเทศ กพส.</small></span></a>
    <button class="btn-close d-lg-none" type="button" data-bs-dismiss="offcanvas" data-bs-target="#appSidebar"
      aria-label="ปิดเมนูหลัก"></button>
  </div>
  <div class="sidebar-body">
    <p class="nav-caption">พื้นที่ทำงาน</p>
    <nav class="sidebar-nav" aria-label="เมนูหลัก">
      <?php if ($usesWorkspaceSidebar): ?>
      <?php $homeIsActive = empty($isWorkspace) && $page === 'dashboard'; ?>
      <a class="sidebar-link<?= $homeIsActive ? ' active' : '' ?>" href="index.php"<?= $homeIsActive ? ' aria-current="page"' : '' ?>><?= app_icon('home') ?><span>หน้าแรก</span></a>
      <?php endif; ?>
      <?php foreach ($sidebarPages as $key => $item): ?>
      <?php if (!empty($item['hidden'])) {
          continue;
      } ?>
      <?php if ($usesWorkspaceSidebar && $item['permission'] !== null && !account_can($currentUser, $item['permission'])) {
          continue;
      } ?>
      <?php
      $navigationUrl = ($usesWorkspaceSidebar ? 'dashboard.php' : 'index.php') . ($key === 'dashboard' ? '' : '?page=' . rawurlencode($key));
      $itemIsActive = !empty($isWorkspace) ? $page === $key : (!$usesWorkspaceSidebar ? $page === $key : $page === 'documents' && $key === 'documents');
      ?>
      <a class="sidebar-link<?= $itemIsActive ? ' active' : '' ?>" href="<?= e($navigationUrl) ?>"
        <?= $itemIsActive ? ' aria-current="page"' : '' ?>><?= app_icon($item['icon']) ?><span><?= e($key === 'dashboard' ? ($usesWorkspaceSidebar ? 'แดชบอร์ด' : 'หน้าแรก') : $item['title']) ?></span></a>
      <?php endforeach; ?>
    </nav>
    <?php if (!$usesWorkspaceSidebar): ?><a class="sidebar-link mt-3" href="register.php"><?= app_icon('user') ?><span>สมัครสมาชิก</span></a><?php endif; ?>
    <div class="sidebar-note"><span class="sidebar-note-icon"><?= app_icon('leaf') ?></span>
      <p>ข้อมูลเป็นระบบ<br><strong>ติดตามงานได้ง่ายขึ้น</strong></p>
    </div>
  </div>
  <div class="sidebar-footer"><?= app_icon('location') ?><span>จังหวัดร้อยเอ็ด</span></div>
</aside>
