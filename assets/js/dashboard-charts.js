'use strict';

(function () {
  const source = document.getElementById('dashboardChartData');
  const flowCanvas = document.getElementById('fundFlowChart');
  const statusCanvas = document.getElementById('requestStatusChart');
  if (!source || !flowCanvas || !statusCanvas || typeof Chart === 'undefined') return;

  const data = JSON.parse(source.textContent);
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const formatNumber = new Intl.NumberFormat('th-TH', { maximumFractionDigits: 2 });

  Chart.defaults.font.family = "'Sarabun', sans-serif";
  Chart.defaults.color = '#65746c';
  Chart.defaults.animation = reducedMotion ? false : { duration: 650 };

  new Chart(flowCanvas, {
    type: 'bar',
    data: {
      labels: data.fund_flow.labels,
      datasets: [
        {
          label: 'วงเงินอนุมัติ',
          data: data.fund_flow.approved,
          backgroundColor: '#33775c',
          borderColor: '#33775c',
          borderRadius: 7,
          borderSkipped: false,
          maxBarThickness: 38
        },
        {
          type: 'line',
          label: 'รับชำระเงินต้น',
          data: data.fund_flow.received,
          borderColor: '#d49a35',
          backgroundColor: '#d49a35',
          pointBackgroundColor: '#ffffff',
          pointBorderColor: '#d49a35',
          pointBorderWidth: 3,
          pointRadius: 4,
          pointHoverRadius: 6,
          borderWidth: 3,
          tension: 0.35
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { position: 'top', align: 'end', labels: { usePointStyle: true, boxWidth: 8, padding: 18 } },
        tooltip: { callbacks: { label: (context) => ' ' + context.dataset.label + ' ' + formatNumber.format(context.parsed.y) + ' ล้านบาท' } }
      },
      scales: {
        x: { grid: { display: false }, border: { display: false } },
        y: {
          beginAtZero: true,
          suggestedMax: 5,
          border: { display: false },
          grid: { color: '#e8eee9' },
          ticks: { callback: (value) => value + ' ลบ.' }
        }
      }
    }
  });

  const centerLabel = {
    id: 'centerLabel',
    afterDraw(chart) {
      const point = chart.getDatasetMeta(0).data[0];
      if (!point) return;
      const total = chart.data.datasets[0].data.reduce((sum, value) => sum + value, 0);
      const ctx = chart.ctx;
      ctx.save();
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      ctx.fillStyle = '#273d34';
      ctx.font = "700 30px 'Sarabun', sans-serif";
      ctx.fillText(formatNumber.format(total), point.x, point.y - 8);
      ctx.fillStyle = '#65746c';
      ctx.font = "400 13px 'Sarabun', sans-serif";
      ctx.fillText('คำขอทั้งหมด', point.x, point.y + 20);
      ctx.restore();
    }
  };

  new Chart(statusCanvas, {
    type: 'doughnut',
    data: {
      labels: data.request_status.labels,
      datasets: [{
        data: data.request_status.values,
        backgroundColor: ['#33775c', '#5378a2', '#d49a35', '#9aa8a0'],
        borderColor: '#ffffff',
        borderWidth: 4,
        hoverOffset: 5
      }]
    },
    plugins: [centerLabel],
    options: {
      responsive: true,
      maintainAspectRatio: false,
      cutout: '68%',
      plugins: {
        legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 9, padding: 16 } },
        tooltip: { callbacks: { label: (context) => ' ' + context.label + ' ' + context.parsed + ' คำขอ' } }
      }
    }
  });
}());
