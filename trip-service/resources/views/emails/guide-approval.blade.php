<!DOCTYPE html>
<html>
<head>
    <style>
        .email-container {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            font-family: Arial, sans-serif;
        }
        .header {
            background-color: #1F4068;
            color: white;
            padding: 20px;
            text-align: center;
            border-radius: 5px;
        }
        .content {
            padding: 20px;
            background-color: #f8f9fa;
            border-radius: 5px;
            margin-top: 20px;
        }
        .button {
            background-color: #4A90E2;
            color: white;
            padding: 12px 24px;
            text-decoration: none;
            border-radius: 5px;
            display: inline-block;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="header">
            <h1>Selamat! Anda Telah Menjadi Guide</h1>
        </div>
        <div class="content">
            <h2>Halo {{ $guideName }},</h2>
            <p>Selamat! Akun Anda telah disetujui sebagai Guide di SummitCess. Anda sekarang dapat:</p>
            <ul>
                <li>Membuat dan mengelola trip pendakian</li>
                <li>Menerima pemesanan dari pendaki</li>
                <li>Mengelola jadwal pendakian</li>
            </ul>
            <p>Silakan login ke dashboard guide Anda untuk mulai membuat perjalanan pendakian yang menakjubkan!</p>
            <a href="{{ $loginUrl }}" class="button">Masuk ke Dashboard</a>
        </div>
    </div>
</body>
</html>
