<!DOCTYPE html>
<html>
<head>
    <title>Patient Login</title>
</head>
<body>
    <h1>Patient Login</h1>

    @if ($errors->any())
        <div style="color: red;">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('patient.login.submit') }}">
        @csrf
        <div>
            <label>Phone:</label>
            <input type="text" name="phone" required>
        </div>

        <div>
            <label>Password:</label>
            <input type="password" name="password" required>
        </div>

        <button type="submit">Login</button>
    </form>

    <p>Don't have an account? <a href="{{ route('patient.register') }}">Register</a></p>
</body>
</html>
