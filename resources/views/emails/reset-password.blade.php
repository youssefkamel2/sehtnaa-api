<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset Code</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
            margin: 0;
            padding: 0;
            background-color: #f9f9f9;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .email-wrapper {
            background-color: #ffffff;
            border-radius: 8px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }
        .email-header {
            background: linear-gradient(135deg, #4F46E5 0%, #7C3AED 100%);
            padding: 30px 20px;
            text-align: center;
        }
        .email-header img {
            max-height: 50px;
            margin-bottom: 10px;
        }
        .email-header h1 {
            color: white;
            font-size: 24px;
            margin: 0;
            font-weight: 600;
        }
        .email-body {
            padding: 30px 40px;
        }
        .email-body p {
            margin-bottom: 20px;
            font-size: 16px;
        }
        .reset-code {
            background-color: #f0f4f8;
            border-radius: 6px;
            border-left: 4px solid #4F46E5;
            padding: 20px;
            margin: 25px 0;
            text-align: center;
        }
        .reset-code h2 {
            font-size: 32px;
            letter-spacing: 5px;
            color: #4F46E5;
            margin: 0;
            font-weight: 700;
        }
        .reset-note {
            font-size: 14px;
            color: #666;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }
        .button {
            display: inline-block;
            background-color: #4F46E5;
            color: white;
            padding: 12px 30px;
            border-radius: 5px;
            text-decoration: none;
            font-weight: 600;
            margin-top: 10px;
            text-align: center;
        }
        .email-footer {
            background-color: #f9f9f9;
            padding: 20px;
            text-align: center;
            font-size: 14px;
            color: #666;
        }
        .email-footer p {
            margin: 5px 0;
        }
        @media only screen and (max-width: 600px) {
            .email-body {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="email-wrapper">
            <div class="email-header">
                <!-- Replace with your logo -->
                @if(config('app.logo'))
                    <img src="{{ config('app.logo') }}" alt="{{ config('app.name') }}" />
                @else
                    <h1>{{ config('app.name', 'Your App Name') }}</h1>
                @endif
            </div>
            
            <div class="email-body">
                <p>Hello,</p>
                
                <p>We received a request to reset your password. Enter the code below to reset your password:</p>
                
                <div class="reset-code">
                    <h2>{{ $code }}</h2>
                </div>
                
                <p>If you didn't request a password reset, you can safely ignore this email.</p>
                
                <p>This code will expire in 15 minutes for security reasons.</p>
                
                <p>If you have any questions or need assistance, please don't hesitate to contact our support team.</p>
                
                <p>Best regards,<br>{{ config('app.name', 'Your App Name') }} Team</p>
                
                {{-- <div class="reset-note">
                    <p>If you're having trouble entering the code, you can also reset your password by clicking the button below:</p>
                    <center>
                        <a href="{{ url('password/reset/'.$code) }}" class="button">Reset Password</a>
                    </center>
                </div> --}}
            </div>
            
            <div class="email-footer">
                <p>&copy; {{ date('Y') }} {{ config('app.name', 'Your App Name') }}. All rights reserved.</p>
                <p>This is an automated email, please do not reply.</p>
            </div>
        </div>
    </div>
</body>
</html>