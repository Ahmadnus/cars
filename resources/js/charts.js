import {
    Chart,
    BarController, BarElement,
    LineController, LineElement, PointElement,
    DoughnutController, ArcElement,
    CategoryScale, LinearScale,
    Tooltip, Legend, Filler,
} from 'chart.js';

Chart.register(
    BarController, BarElement,
    LineController, LineElement, PointElement,
    DoughnutController, ArcElement,
    CategoryScale, LinearScale,
    Tooltip, Legend, Filler,
);

Chart.defaults.font.family = "'Cairo', system-ui, sans-serif";
Chart.defaults.font.size = 12;
Chart.defaults.color = '#6b767d';
Chart.defaults.plugins.legend.labels.usePointStyle = true;
Chart.defaults.plugins.legend.labels.boxWidth = 8;
Chart.defaults.plugins.legend.labels.padding = 16;

/** Categorical series colors — distinguishable and consistent across every chart. */
export const PALETTE = ['#2f7f79', '#c2703d', '#4b6bb0', '#8a5aa8', '#b04b5f', '#5f8f3a'];

const GRID = { color: '#eef0f1', drawTicks: false };

/**
 * Declarative chart mounting: <canvas data-chart='{"type":...,"data":...}'></canvas>
 * Keeps chart config server-rendered while the drawing stays on the client.
 */
function mount(canvas) {
    if (canvas.__chart) return;

    let spec;
    try {
        spec = JSON.parse(canvas.dataset.chart);
    } catch {
        return;
    }

    const horizontal = spec.options?.indexAxis === 'y';

    spec.data.datasets = (spec.data.datasets || []).map((set, i) => ({
        borderColor: PALETTE[i % PALETTE.length],
        backgroundColor: spec.type === 'line'
            ? PALETTE[i % PALETTE.length] + '22'
            : (spec.type === 'doughnut' ? undefined : PALETTE[i % PALETTE.length]),
        borderWidth: spec.type === 'line' ? 2 : 0,
        borderRadius: spec.type === 'bar' ? 4 : undefined,
        tension: 0.3,
        pointRadius: 0,
        pointHoverRadius: 4,
        fill: spec.type === 'line',
        ...set,
    }));

    if (spec.type === 'doughnut') {
        spec.data.datasets.forEach((set) => {
            set.backgroundColor ||= spec.data.labels.map((_, i) => PALETTE[i % PALETTE.length]);
            set.borderColor = '#ffffff';
            set.borderWidth = 2;
        });
    }

    canvas.__chart = new Chart(canvas, {
        ...spec,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    display: spec.type === 'doughnut' || spec.data.datasets.length > 1,
                    position: spec.type === 'doughnut' ? 'bottom' : 'top',
                    align: 'end',
                },
                tooltip: {
                    rtl: true,
                    textDirection: 'rtl',
                    backgroundColor: '#181b1d',
                    padding: 10,
                    cornerRadius: 6,
                    displayColors: true,
                    boxWidth: 8,
                    boxHeight: 8,
                    usePointStyle: true,
                },
            },
            scales: spec.type === 'doughnut' ? {} : {
                x: {
                    reverse: !horizontal,
                    grid: { ...GRID, display: horizontal },
                    border: { display: false },
                },
                y: {
                    position: 'right',
                    beginAtZero: true,
                    grid: { ...GRID, display: !horizontal },
                    border: { display: false },
                    ticks: {
                        callback: (v) => (Math.abs(v) >= 1000 ? (v / 1000) + 'K' : v),
                    },
                },
            },
            ...spec.options,
        },
    });
}

function mountAll(root = document) {
    root.querySelectorAll('canvas[data-chart]').forEach(mount);
}

document.addEventListener('DOMContentLoaded', () => mountAll());
document.addEventListener('chart:refresh', (e) => mountAll(e.target));
