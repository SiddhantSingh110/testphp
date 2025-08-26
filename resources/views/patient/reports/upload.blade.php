<!-- resources/views/patient/reports/upload.blade.php -->

<!DOCTYPE html>
<html>
<head>
    <title>Upload Report</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tailwindcss@3.3.2/dist/tailwind.min.css">
</head>
<body class="bg-gray-50 p-6">

    @if(session('success'))
        <div class="bg-green-100 border border-green-300 text-green-700 px-4 py-2 rounded mb-4">
            {{ session('success') }}
        </div>
    @endif

    <h1 class="text-xl font-bold mb-4">Upload Report (Patient)</h1>

    <form method="POST" action="{{ route('patient.reports.upload') }}" enctype="multipart/form-data" class="space-y-4">
        @csrf

        <div>
            <label for="file" class="block font-medium">Choose PDF/Image:</label>
            <input type="file" name="file" id="file" required class="mt-1 block w-full border border-gray-300 rounded px-3 py-2">
        </div>

        <div>
            <label for="report_title" class="block font-medium">Title:</label>
            <input type="text" name="report_title" id="report_title" required class="mt-1 block w-full border border-gray-300 rounded px-3 py-2">
        </div>

        <div>
            <label for="report_date" class="block font-medium">Report Date:</label>
            <input type="date" name="report_date" id="report_date" required class="mt-1 block w-full border border-gray-300 rounded px-3 py-2">
        </div>

        <div>
            <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Upload</button>
        </div>
    </form>

</body>
</html>
