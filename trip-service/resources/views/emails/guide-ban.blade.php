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
            background-color: #D32F2F;
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
    </style>
</head>
<body>
    <div class="email-container">
        <div class="header">
            <h1>Pemberitahuan Penonaktifan Akun</h1>
        </div>
        <div class="content">
            <h2>Halo {{ $guideName }},</h2>
            <p>Dengan berat hati kami informasikan bahwa akun guide Anda di SummitCess telah dinonaktifkan.</p>
            <p>Hal ini dapat terjadi karena beberapa alasan, termasuk namun tidak terbatas pada:</p>
            <ul>
                <li>Pelanggaran terhadap ketentuan layanan kami</li>
                <li>Umpan balik negatif yang signifikan dari pengguna</li>
                <li>Ketidakaktifan dalam jangka waktu yang lama</li>
                <li>Informasi akun yang tidak akurat atau menyesatkan</li>
            </ul>
            <p>Jika Anda merasa ini adalah kesalahan atau ingin mendapatkan informasi lebih lanjut, silakan hubungi tim dukungan kami.</p>
            <p>Terima kasih atas partisipasi Anda di SummitCess.</p>
        </div>
    </div>
</body>
</html>
