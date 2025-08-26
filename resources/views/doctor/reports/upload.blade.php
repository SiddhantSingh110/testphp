@extends('doctor.layout')

@section('content')

<h2 class="text-2xl font-bold mb-4">Upload New Report</h2>

<form method="POST" action="{{ route('doctor.reports.upload.submit') }}" enctype="multipart/form-data" class="bg-white p-6 rounded shadow">
    @csrf
    <div class="mb-4">
        <label for="patient_id" class="block">Select Patient</label>
        <select name="patient_id" required class="w-full p-2 border rounded">
            @foreach($patients as $patient)
                <option value="{{ $patient->id }}">{{ $patient->name }}</option>
            @endforeach
        </select>
    </div>

    <div class="mb-4">
        <label for="file" class="block">Upload PDF or Image</label>
        <input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png" required class="w-full">
    </div>

    <div class="mb-4">
        <label for="notes" class="block">Notes</label>
        <textarea name="notes" class="w-full p-2 border rounded"></textarea>
    </div>

    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Upload</button>
</form>
@endsection
