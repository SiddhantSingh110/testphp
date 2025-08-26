<!DOCTYPE html>
<html>
<head>
    <title>All Reports</title>
</head>
<body>
    <h1>All Reports</h1>

    <div id="reportsList">
        Loading reports...
    </div>

    <script>
        async function fetchReports() {
            const token = localStorage.getItem('patientToken');
            const res = await fetch('/api/patient/reports', {
                headers: {
                    'Authorization': 'Bearer ' + token,
                    'Accept': 'application/json'
                }
            });

            const data = await res.json();
            const container = document.getElementById('reportsList');
            container.innerHTML = '';

            if (data.reports.length === 0) {
                container.innerHTML = '<p>No reports found.</p>';
                return;
            }

            data.reports.forEach(report => {
                container.innerHTML += `
                    <div style="border:1px solid #ccc; margin:10px; padding:10px;">
                        <h3>${report.title}</h3>
                        <p><strong>Uploaded On:</strong> ${report.uploaded_at}</p>
                        <p><strong>Report Date:</strong> ${report.report_date}</p>
                        <p><strong>Diagnosis:</strong> ${report.summary_diagnosis || 'N/A'}</p>
                        <p><a href="${report.file_url}" target="_blank">View File</a></p>
                        <p><a href="/patient/reports/${report.id}">View Details</a></p>
                    </div>
                `;
            });
        }

        fetchReports();
    </script>
</body>
</html>
