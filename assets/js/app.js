'use strict';

(function () {
  document.querySelectorAll('[data-toggle-password]').forEach(function (button) {
    const input = document.getElementById(button.dataset.togglePassword);
    if (!input) return;
    button.hidden = false;
    button.addEventListener('click', function () {
      const reveal = input.type === 'password';
      input.type = reveal ? 'text' : 'password';
      button.setAttribute('aria-label', reveal ? 'ซ่อนรหัสผ่าน' : 'แสดงรหัสผ่าน');
      button.setAttribute('aria-pressed', String(reveal));
    });
  });
  const approvalRole = document.getElementById('approvalRole');
  if (approvalRole) {
    const province = document.getElementById('approvalProvince');
    const cooperative = document.getElementById('approvalCooperative');
    function updateApprovalScope() {
      const needsProvince = approvalRole.value !== 'central_officer';
      const needsCooperative = approvalRole.value === 'cooperative_user';
      document.getElementById('approvalProvinceField').hidden = !needsProvince;
      document.getElementById('approvalCooperativeField').hidden = !needsCooperative;
      province.disabled = !needsProvince;
      province.required = needsProvince;
      cooperative.disabled = !needsCooperative;
      cooperative.required = needsCooperative;
      Array.from(cooperative.options).forEach(function (option) {
        const available = !option.value || option.dataset.province === province.value;
        option.hidden = !available;
        option.disabled = !available;
        if (!available && option.selected) cooperative.value = '';
      });
    }
    approvalRole.addEventListener('change', updateApprovalScope);
    province.addEventListener('change', updateApprovalScope);
    updateApprovalScope();
  }
  const passwordInput = document.getElementById('loginPassword');
  const passwordToggle = document.getElementById('passwordToggle');
  if (passwordInput && passwordToggle) {
    passwordToggle.hidden = false;
    passwordToggle.addEventListener('click', function () {
      const showPassword = passwordInput.type === 'password';
      passwordInput.type = showPassword ? 'text' : 'password';
      passwordToggle.setAttribute('aria-label', showPassword ? 'ซ่อนรหัสผ่าน' : 'แสดงรหัสผ่าน');
      passwordToggle.setAttribute('aria-pressed', String(showPassword));
      passwordToggle.querySelector('[data-password-show]').hidden = showPassword;
      passwordToggle.querySelector('[data-password-hide]').hidden = !showPassword;
    });
  }

  const invalidLoginField = document.querySelector('.auth-form [aria-invalid="true"]');
  if (invalidLoginField) invalidLoginField.focus();

  const table = document.getElementById('recordsTable');
  const search = document.getElementById('recordSearch');
  const status = document.getElementById('statusFilter');

  if (table && search && status) {
    const rows = Array.from(table.querySelectorAll('tbody [data-record]'));
    const count = document.getElementById('resultCount');
    const empty = document.getElementById('emptyState');
    const reset = document.getElementById('resetFilters');

    function filterRecords() {
      const query = search.value.trim().normalize('NFC').toLocaleLowerCase('th');
      let visibleCount = 0;
      rows.forEach(function (row) {
        const text = row.textContent.normalize('NFC').toLocaleLowerCase('th');
        row.hidden = !text.includes(query) || (status.value !== '' && row.dataset.status !== status.value);
        if (!row.hidden) visibleCount += 1;
      });
      count.textContent = 'แสดง ' + visibleCount + ' จาก ' + rows.length + ' รายการ';
      empty.hidden = visibleCount > 0;
      reset.hidden = query === '' && status.value === '';
      document.querySelectorAll('[data-export-table]').forEach(function (button) {
        button.disabled = visibleCount === 0;
      });
    }

    function resetFilters() {
      search.value = '';
      status.value = '';
      filterRecords();
      search.focus();
    }

    search.addEventListener('input', filterRecords);
    status.addEventListener('change', filterRecords);
    reset.addEventListener('click', resetFilters);
    document.querySelectorAll('[data-reset-filters]').forEach(function (button) {
      button.addEventListener('click', resetFilters);
    });
    filterRecords();
  }

  const modal = document.getElementById('recordModal');
  if (modal) {
    modal.addEventListener('show.bs.modal', function (event) {
      const button = event.relatedTarget;
      if (!button || !button.dataset.details) return;
      const details = JSON.parse(button.dataset.details);
      const list = document.getElementById('recordDetails');
      list.replaceChildren();
      Object.entries(details).forEach(function (entry) {
        const label = document.createElement('dt');
        const value = document.createElement('dd');
        label.textContent = entry[0];
        value.textContent = entry[1];
        list.append(label, value);
      });
    });
  }

  // Quote CSV cells and neutralize spreadsheet formulas before exporting.
  function csvCell(value) {
    let text = value.replace(/\s+/g, ' ').trim();
    if (/^[=+@-]/.test(text)) text = "'" + text;
    return '"' + text.replace(/"/g, '""') + '"';
  }

  document.querySelectorAll('[data-export-table]').forEach(function (button) {
    button.addEventListener('click', function () {
      const target = document.getElementById(button.dataset.exportTable);
      if (!target) return;
      const rows = Array.from(target.querySelectorAll('tr')).filter(function (row) { return !row.hidden; });
      const lines = rows.map(function (row) {
        return Array.from(row.querySelectorAll('th:not([data-no-export]), td:not([data-no-export])')).map(function (cell) {
          const copy = cell.cloneNode(true);
          copy.querySelectorAll('.mobile-unit').forEach(function (unit) { unit.remove(); });
          return csvCell(Array.from(copy.childNodes).map(function (node) { return node.textContent; }).join(' '));
        }).join(',');
      });
      const blob = new Blob(['\uFEFF' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = button.dataset.exportName;
      document.body.appendChild(link);
      link.click();
      link.remove();
      window.setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
      document.getElementById('appMessage').textContent = 'ส่งออกข้อมูล CSV แล้ว';
    });
  });
})();
