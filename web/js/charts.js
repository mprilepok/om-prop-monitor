window.gaugeCharts = [];

function makeGauge(id, value, max, label, color) {
  const num = parseFloat(value) || 0;
  const pct = Math.min(100, Math.round(num / max * 100));
  const isDark = document.documentElement.classList.contains('dark');
  const chart = new ApexCharts(document.getElementById(id), {
    series: [pct],
    chart: {
      type: 'radialBar',
      height: 110,
      sparkline: { enabled: true },
      background: 'transparent',
      animations: { enabled: false },
    },
    plotOptions: {
      radialBar: {
        hollow: { size: '52%' },
        track: { background: isDark ? '#1e293b' : '#e2e8f0' },
        dataLabels: {
          value: {
            show: true,
            fontSize: '17px',
            fontWeight: 700,
            color: color,
            offsetY: 6,
            formatter: () => (value != null && value !== '' ? value : '—'),
          },
          name: { show: false },
        },
      },
    },
    fill: { colors: [color] },
    stroke: { lineCap: 'round' },
    theme: { mode: isDark ? 'dark' : 'light' },
  });
  chart.render();
  window.gaugeCharts.push(chart);
}

function makeTrendChart(id, kpData, sfiData) {
  const isDark     = document.documentElement.classList.contains('dark');
  const labelColor = isDark ? '#64748b' : '#94a3b8';
  const gridColor  = isDark ? '#1e293b' : '#e2e8f0';

  const series = [];
  if (kpData.length)  series.push({ name: 'Kp',  data: kpData  });
  if (sfiData.length) series.push({ name: 'SFI', data: sfiData });
  if (!series.length) return;

  const yaxis = [
    { min: 0, max: 9, tickAmount: 3, title: { text: 'Kp',  style: { color: '#f87171' } }, labels: { style: { colors: '#f87171'  } } },
    { opposite: true, min: 50, max: 320, title: { text: 'SFI', style: { color: '#38bdf8' } }, labels: { style: { colors: '#38bdf8' } } },
  ];

  const chart = new ApexCharts(document.getElementById(id), {
    series,
    chart: { type: 'line', height: 180, background: 'transparent', toolbar: { show: false }, zoom: { enabled: false }, animations: { enabled: false } },
    stroke: { curve: 'smooth', width: [2, 2] },
    colors: ['#f87171', '#38bdf8'],
    xaxis: { type: 'datetime', labels: { style: { colors: labelColor }, datetimeUTC: false } },
    yaxis: kpData.length && sfiData.length ? yaxis : [yaxis[kpData.length ? 0 : 1]],
    grid: { borderColor: gridColor },
    legend: { labels: { colors: isDark ? '#94a3b8' : '#475569' } },
    theme: { mode: isDark ? 'dark' : 'light' },
    tooltip: { x: { format: 'dd MMM HH:mm' } },
  });
  chart.render();
  window.gaugeCharts.push(chart);
}

function updateGaugeThemes(isDark) {
  const track = isDark ? '#1e293b' : '#e2e8f0';
  window.gaugeCharts.forEach(c => {
    c.updateOptions({
      theme: { mode: isDark ? 'dark' : 'light' },
      plotOptions: { radialBar: { track: { background: track } } },
    });
  });
}
