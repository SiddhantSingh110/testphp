<!DOCTYPE html>
<html>
<head>
    <title>Health Trends</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <h1>Health Trends</h1>

    <label for="metricType">Select Metric:</label>
    <select id="metricType">
        <option value="blood_pressure">Blood Pressure</option>
        <option value="blood_sugar">Blood Sugar</option>
        <option value="weight">Weight</option>
        <option value="temperature">Temperature</option>
        <option value="heart_rate" selected>Heart Rate</option>
        <option value="oxygen_level">Oxygen Level</option>
    </select>
    <canvas id="metricChart" width="600" height="300"></canvas>

    <script>
        const chartCanvas = document.getElementById('metricChart').getContext('2d');
        let chart;

        function renderChart(labels, values) {
            if (chart) chart.destroy();
            chart = new Chart(chartCanvas, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Health Metric',
                        data: values,
                        backgroundColor: 'rgba(59, 130, 246, 0.2)',
                        borderColor: 'rgba(59, 130, 246, 1)',
                        borderWidth: 2,
                        tension: 0.4
                    }]
                }
            });
        }

        async function fetchAndRenderMetric(type) {
            const token = localStorage.getItem('patientToken');
            const res = await fetch(`/api/patient/metrics/trends/${type}`, {
                headers: {
                    'Authorization': 'Bearer ' + token,
                    'Accept': 'application/json'
                }
            });

            const data = await res.json();
            if (!data.trend || data.trend.length === 0) {
    renderChart([], []);
    alert('No data found for this metric.');
    return;
}else if (data.trend.length > 0) {
    alert('Data fetched successfully.');
}
            const labels = data.trend.map(entry => entry.date);
            const values = data.trend.map(entry => parseFloat(entry.value));
            renderChart(labels, values);
        }

        document.getElementById('metricType').addEventListener('change', function () {
            fetchAndRenderMetric(this.value);
        });

        // Load default metric
        fetchAndRenderMetric('heart_rate');
    </script>
</body>
</html>

