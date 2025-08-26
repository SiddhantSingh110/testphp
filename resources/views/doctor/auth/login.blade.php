<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Doctor Login</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tailwindcss@3.3.2/dist/tailwind.min.css">
</head>
<body class="bg-gray-100 h-screen flex items-center justify-center">

    <form id="loginForm" class="bg-white shadow-md rounded px-8 pt-6 pb-8 w-full max-w-sm">
        <h2 class="text-2xl font-bold mb-6 text-center">Doctor Login</h2>

        <div id="error" class="mb-4 text-red-500 text-sm hidden"></div>

        <div class="mb-4">
            <label>Email</label>
            <input type="email" name="email" required class="w-full p-2 border rounded">
        </div>

        <div class="mb-6">
            <label>Password</label>
            <input type="password" name="password" required class="w-full p-2 border rounded">
        </div>

        <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded w-full">
            Login
        </button>
    </form>

    <script>
        document.getElementById('loginForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const email = document.querySelector('[name="email"]').value;
            const password = document.querySelector('[name="password"]').value;

            const response = await fetch('/api/doctor/login', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ email, password })
            });

            const data = await response.json();

            if (response.ok && data.token) {
                localStorage.setItem('doctor_token', data.token);
                window.location.href = '/doctor/reports';
            } else {
                document.getElementById('error').classList.remove('hidden');
                document.getElementById('error').innerText = data.message || 'Login failed';
            }
        });
    </script>
</body>
</html>
