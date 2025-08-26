<!DOCTYPE html>
<html>
<head>
    <title>Patient Registration</title>
</head>
<body>
    <h1>Patient Registration</h1>
    
    <div id="message"></div>
    
    <form id="registerForm">
        <div>
            <label>Name:</label>
            <input type="text" name="name" required>
        </div>
        
        <div>
            <label>Phone:</label>
            <input type="text" name="phone" required>
        </div>
        
        <div>
            <label>Email (optional):</label>
            <input type="email" name="email">
        </div>
        
        <div>
            <label>Gender:</label>
            <select name="gender">
                <option value="">Select</option>
                <option value="male">Male</option>
                <option value="female">Female</option>
                <option value="other">Other</option>
            </select>
        </div>
        
        <div>
            <label>Date of Birth:</label>
            <input type="date" name="dob">
        </div>
        
        <div>
            <label>Password:</label>
            <input type="password" name="password" required>
        </div>
        
        <button type="submit">Register</button>
    </form>
    
    <p>Already have an account? <a href="/patient/login">Login</a></p>
    
    <script>
        document.getElementById('registerForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const data = {};
            formData.forEach((value, key) => {
                data[key] = value;
            });
            
            try {
                const response = await fetch('/api/patient/register', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify(data)
                });
                
                const result = await response.json();
                
                if (response.ok) {
                    document.getElementById('message').innerHTML = '<div style="color: green">Registration successful! Redirecting to dashboard...</div>';
                    // Store token
                    localStorage.setItem('patientToken', result.token);
                    // Redirect to dashboard
                    setTimeout(() => {
                        window.location.href = '/patient/dashboard';
                    }, 2000);
                } else {
                    let errorMsg = 'Registration failed. ';
                    if (result.errors) {
                        Object.values(result.errors).forEach(error => {
                            errorMsg += error.join(' ');
                        });
                    }
                    document.getElementById('message').innerHTML = `<div style="color: red">${errorMsg}</div>`;
                }
            } catch (error) {
                document.getElementById('message').innerHTML = '<div style="color: red">An error occurred during registration.</div>';
            }
        });
    </script>
</body>
</html>