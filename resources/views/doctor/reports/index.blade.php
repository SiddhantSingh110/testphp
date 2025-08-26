@extends('doctor.layout')

@section('content')
    <h2 class="text-2xl font-bold mb-4">My Uploaded Reports</h2>

    <div id="reportList">
        <p>Loading...</p>
    </div>

    <script>
    fetch("/api/doctor/reports", {
        headers: {
            'Authorization': 'Bearer ' + localStorage.getItem('doctor_token'),
            'Accept': 'application/json'
        }
    })
    .then(res => res.json())
    .then(data => {
        const container = document.getElementById('reportList');
        container.innerHTML = '';

        data.reports.forEach(report => {
            container.innerHTML += `
                <div class="bg-white shadow p-4 mb-3 rounded">
                    <p><strong>Patient:</strong> ${report.patient_name}</p>
                    <p><strong>Diagnosis:</strong> ${report.diagnosis ?? 'N/A'}</p>
                    <p><strong>Date:</strong> ${report.uploaded_at}</p>
                   <a href="/doctor/reports/${report.report_id}" class="font-bold text-blue-600">Report #${report.report_id}</a>
                   <a href="${report.file_url}" target="_blank" class="text-blue-500 underline">View File</a>
                </div>
            `;
        });
    })
    .catch(error => {
        const container = document.getElementById('reportList');
        container.innerHTML = '<p class="text-red-500">Failed to load reports.</p>';
        console.error('Error:', error);
    });
</script>
@endsection
