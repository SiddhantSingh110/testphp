<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Login Code - Webshark My Health</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background-color: #f6f6f6;
        }
        
        .email-container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }
        
        .header {
            background: linear-gradient(135deg, #2C7BE5 0%, #38BFA7 100%);
            padding: 40px 30px;
            text-align: center;
        }
        
        .logo {
            color: #ffffff;
            font-size: 32px;
            font-weight: bold;
            margin-bottom: 10px;
            letter-spacing: -0.5px;
        }
        
        .tagline {
            color: #ffffff;
            font-size: 16px;
            opacity: 0.9;
            margin: 0;
        }
        
        .content {
            padding: 50px 40px;
            text-align: center;
        }
        
        .greeting {
            font-size: 28px;
            font-weight: 600;
            color: #1a1a1a;
            margin-bottom: 20px;
            line-height: 1.3;
        }
        
        .message {
            font-size: 16px;
            color: #666666;
            line-height: 1.6;
            margin-bottom: 40px;
        }
        
        .otp-container {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border: 2px solid #38BFA7;
            border-radius: 16px;
            padding: 30px;
            margin: 40px 0;
            display: inline-block;
        }
        
        .otp-label {
            font-size: 14px;
            color: #666666;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 15px;
            font-weight: 600;
        }
        
        .otp-code {
            font-size: 48px;
            font-weight: bold;
            color: #2C7BE5;
            letter-spacing: 8px;
            font-family: 'Courier New', Monaco, monospace;
            margin: 0;
            text-shadow: 0 2px 4px rgba(44, 123, 229, 0.1);
        }
        
        .expiry {
            font-size: 14px;
            color: #e74c3c;
            margin-top: 30px;
            font-weight: 500;
        }
        
        .security-note {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 8px;
            padding: 20px;
            margin: 30px 0;
            font-size: 14px;
            color: #856404;
        }
        
        .footer {
            background-color: #f8f9fa;
            padding: 30px;
            text-align: center;
            border-top: 1px solid #e9ecef;
        }
        
        .footer-text {
            font-size: 14px;
            color: #6c757d;
            margin: 5px 0;
        }
        
        .company-name {
            color: #2C7BE5;
            font-weight: 600;
        }
        
        @media (max-width: 600px) {
            .content {
                padding: 30px 20px;
            }
            
            .greeting {
                font-size: 24px;
            }
            
            .otp-code {
                font-size: 36px;
                letter-spacing: 4px;
            }
            
            .otp-container {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="email-container">
        <!-- Header -->
        <div class="header">
            <div class="logo">Webshark My Health</div>
            <div class="tagline">AI-Powered Care. Personalized for You.</div>
        </div>
        
        <!-- Content -->
        <div class="content">
            <h1 class="greeting">Hi {{ $userName }},</h1>
            
            <p class="message">
                Welcome to Webshark My Health! To complete your login, please use the verification code below on your mobile device.
            </p>
            
            <div class="otp-container">
                <div class="otp-label">Your Verification Code</div>
                <div class="otp-code">{{ $otp }}</div>
            </div>
            
            <p class="expiry">⏰ This code will expire in 3 minutes</p>
            
            <div class="security-note">
                <strong>🔒 Security Note:</strong> Never share this code with anyone. Webshark My Health will never ask for your verification code via phone or email.
            </div>
        </div>
        
        <!-- Footer -->
        <div class="footer">
            <p class="footer-text">This email was sent from <span class="company-name">Webshark My Health</span></p>
            <p class="footer-text">If you didn't request this code, please ignore this email.</p>
            <p class="footer-text">© 2025 Webshark My Health. All rights reserved.</p>
        </div>
    </div>
</body>
</html>