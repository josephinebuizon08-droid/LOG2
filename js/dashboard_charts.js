
    document.addEventListener('DOMContentLoaded', () => {
    const dataEl = document.getElementById('cost-savings-data');
    const canvas = document.getElementById('costSavingsChart');
    if (!dataEl || !canvas) return;

    const data = JSON.parse(dataEl.textContent);

    new Chart(canvas.getContext('2d'), {
        type: 'bar',
        data: {
            labels: ['This Month'],
            datasets: [
                {
                    label: 'Transport Cost',
                    data: [data.cost],
                    backgroundColor: '#ff6a39',
                    borderRadius: 6,
                    maxBarThickness: 48
                },
                {
                    label: 'Potential Savings',
                    data: [data.savings],
                    backgroundColor: '#22c55e',
                    borderRadius: 6,
                    maxBarThickness: 48
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (ctx) => `${ctx.dataset.label}: ₱${ctx.parsed.y.toLocaleString(undefined, {minimumFractionDigits: 2})}`
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: (val) => '₱' + val.toLocaleString()
                    },
                    grid: { color: '#f1f5f9' }
                },
                x: {
                    grid: { display: false }
                }
            }
        }
    });
});