<?php
// ==========================================
// 1. OTOMATİK KALICI SQLITE VERİTABANI AYARI
// ==========================================
try {
    // Verileri Render üzerinde kalıcı klasöre kaydediyoruz
    $db = new PDO("sqlite:/opt/render/project/src/stok.db");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Veritabanı bağlantı hatası: " . $e->getMessage());
}

// ==========================================
// 2. TABLOLARI OLUŞTURMA (SQLITE UYUMLU)
// ==========================================
$db->exec("CREATE TABLE IF NOT EXISTS urunler (
    seri_no TEXT PRIMARY KEY, 
    marka TEXT, 
    model TEXT, 
    kimden_alindi TEXT, 
    alis_fiyati REAL, 
    alis_tarihi TEXT, 
    durum TEXT DEFAULT 'Stokta'
)");

$db->exec("CREATE TABLE IF NOT EXISTS satislar (
    id INTEGER PRIMARY KEY AUTOINCREMENT, 
    seri_no TEXT, 
    kime_satildi TEXT, 
    satis_fiyati REAL, 
    satis_tarihi TEXT
)");

$db->exec("CREATE TABLE IF NOT EXISTS iadeler (
    id INTEGER PRIMARY KEY AUTOINCREMENT, 
    seri_no TEXT, 
    iade_turu TEXT, 
    iade_tarihi TEXT
)");

// ==========================================
// 3. API / ARKA PLAN İŞLEMLERİ
// ==========================================
$route = isset($_GET['action']) ? $_GET['action'] : '';

if ($route == 'sorgula') {
    header('Content-Type: application/json');
    $seri = isset($_GET['seri']) ? trim($_GET['seri']) : '';
    
    if (empty($seri)) {
        echo json_encode(['durum' => 'Yok']);
        exit;
    }
    
    $stmt = $db->prepare("SELECT * FROM urunler WHERE seri_no = ?");
    $stmt->execute([$seri]);
    $urun = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$urun) {
        echo json_encode(['durum' => 'Yok']);
    } else if ($urun['durum'] == 'Satildi') {
        // Ürün satıldıysa en son kime satıldığını çekiyoruz
        $stmtSatis = $db->prepare("SELECT * FROM satislar WHERE seri_no = ? ORDER BY id DESC LIMIT 1");
        $stmtSatis->execute([$seri]);
        $satis = $stmtSatis->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['durum' => 'Satildi', 'urun' => $urun, 'satis' => $satis]);
    } else {
        echo json_encode(['durum' => $urun['durum'], 'urun' => $urun]);
    }
    exit;
}

if ($route == 'islem' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    header('Content-Type: application/json');
    $data = json_decode(file_get_contents('php://input'), true);
    
    $aksiyon = $data['aksiyon'] ?? '';
    $seri_no = $data['seri_no'] ?? '';
    $val1 = $data['val1'] ?? '';
    $val2 = $data['val2'] ?? 0;
    $marka = $data['marka'] ?? '';
    $model = $data['model'] ?? '';
    $secilen_tarih = $data['secilen_tarih'] ?? '';
    
    if (!empty($secilen_tarih)) {
        $tarih = date('Y-m-d H:i:s', strtotime($secilen_tarih));
    } else {
        $tarih = date('Y-m-d H:i:s');
    }
    
    $mesaj = "";
    
    if ($aksiyon == 'alis_kaydet') {
        $stmt = $db->prepare("INSERT OR REPLACE INTO urunler (seri_no, marka, model, kimden_alindi, alis_fiyati, alis_tarihi, durum) VALUES (?, ?, ?, ?, ?, ?, 'Stokta')");
        $stmt->execute([$seri_no, $marka, $model, $val1, $val2, $tarih]);
        $mesaj = "📦 $marka $model, $val1 İsimli Tedarikçiden Stoğa Eklendi!";
    } elseif ($aksiyon == 'satis_kaydet') {
        $stmt = $db->prepare("INSERT INTO satislar (seri_no, kime_satildi, satis_fiyati, satis_tarihi) VALUES (?, ?, ?, ?)");
        $stmt->execute([$seri_no, $val1, $val2, $tarih]);
        
        $stmtUpdate = $db->prepare("UPDATE urunler SET durum = 'Satildi' WHERE seri_no = ?");
        $stmtUpdate->execute([$seri_no]);
        $mesaj = "💸 Ürün $val1 İsimli Müşteriye Satıldı!";
    } elseif ($aksiyon == 'iade_kaydet') {
        $stmt = $db->prepare("INSERT INTO iadeler (seri_no, iade_turu, iade_tarihi) VALUES (?, 'Satis_Iadesi', ?)");
        $stmt->execute([$seri_no, $tarih]);
        
        $stmtDel = $db->prepare("DELETE FROM satislar WHERE seri_no = ?");
        $stmtDel->execute([$seri_no]);
        
        $stmtUpdate = $db->prepare("UPDATE urunler SET durum = 'Stokta' WHERE seri_no = ?");
        $stmtUpdate->execute([$seri_no]);
        $mesaj = "🔄 Müşteri İadesi Alındı, Ürün Tekrar Stokta!";
    } elseif ($aksiyon == 'tedarikci_iade_kaydet') {
        $stmt = $db->prepare("INSERT INTO iadeler (seri_no, iade_turu, iade_tarihi) VALUES (?, 'Alis_Iadesi', ?)");
        $stmt->execute([$seri_no, $tarih]);
        
        $stmtUpdate = $db->prepare("UPDATE urunler SET durum = 'Tedarikciye_Iade' WHERE seri_no = ?");
        $stmtUpdate->execute([$seri_no]);
        $mesaj = "↩️ Ürün Tedarikçiye İade Edildi.";
    }
    
    echo json_encode(["status" => "success", "mesaj" => $mesaj]);
    exit;
}

if ($route == 'excel_indir') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=Rapor.csv');
    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'w');
    fputcsv($output, ["Barkod", "Marka", "Model", "Kimden Alındı (Tedarikçi)", "Alış Fiyatı", "Alış Tarihi", "Durum"], ";");
    $stmt = $db->query("SELECT * FROM urunler");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $row['seri_no'], $row['marka'], $row['model'], 
            $row['kimden_alindi'], $row['alis_fiyati'], 
            $row['alis_tarihi'], $row['durum']
        ], ";");
    }
    fclose($output);
    exit;
}

if (isset($_GET['page']) && $_GET['page'] == 'rapor') {
    $toplam_satis = $db->query("SELECT SUM(satis_fiyati) FROM satislar")->fetchColumn() ?: 0.0;
    $toplam_alis = $db->query("SELECT SUM(alis_fiyati) FROM urunler WHERE durum != 'Tedarikciye_Iade'")->fetchColumn() ?: 0.0;
    $net_kar = $toplam_satis - $toplam_alis;
    ?>
    <!DOCTYPE html>
    <html lang="tr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
        <title>Gelişmiş Muhasebe Paneli</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="container py-3">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3 class="fw-bold m-0">📊 MUHASEBE VE RAPOR</h3>
            <a href="index.php" class="btn btn-secondary btn-sm">↩️ Barkod Ekranı</a>
        </div>
        <div class="row g-2 mb-3">
            <div class="col-4"><div class="p-3 bg-success text-white rounded">Satış: <?php echo number_format($toplam_satis, 2); ?> ₺</div></div>
            <div class="col-4"><div class="p-3 bg-danger text-white rounded">Maliyet: <?php echo number_format($toplam_alis, 2); ?> ₺</div></div>
            <div class="col-4"><div class="p-3 bg-primary text-white rounded">Kâr: <?php echo number_format($net_kar, 2); ?> ₺</div></div>
        </div>
        <div class="mb-3"><a href="index.php?action=excel_indir" class="btn btn-success w-100 fw-bold">📥 DETAYLI EXCEL RAPORU</a></div>
    </body>
    </html>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>PRO-STOK BARKOD</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://unpkg.com/html5-qrcode"></script>
    <style>
        body { background-color: #f4f6f9; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .card { border-radius: 12px; border: none; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        #reader { 
            width: 100% !important; 
            max-width: 450px; 
            margin: 0 auto; 
            border: none !important;
            border-radius: 12px;
            overflow: hidden;
            background-color: #000;
        }
        #reader video {
            width: 100% !important;
            height: auto !important;
            border-radius: 12px;
            object-fit: cover;
        }
        .form-label { font-weight: 600; font-size: 0.85rem; color: #495057; margin-bottom: 3px; }
        .nav-bar { background: #fff; padding: 12px; border-bottom: 1px solid #e3e6f0; margin-bottom: 20px; }
    </style>
</head>
<body>
    <div class="nav-bar shadow-sm d-flex justify-content-between align-items-center">
        <h4 class="text-primary fw-bold m-0">📦 PRO-STOK v2.7</h4>
        <a href="index.php?page=rapor" class="btn btn-primary btn-sm fw-bold px-3">📊 Raporlar & Muhasebe</a>
    </div>
    <div class="container">
        <div class="card p-3 mb-3 text-center">
            <div id="kamera_kapsayici" style="display: none; margin-bottom: 15px;">
                <div id="reader"></div>
            </div>
            <div class="d-flex gap-2 mt-1">
                <button id="btn_ac" class="btn btn-primary fw-bold btn-lg w-100" onclick="kamerayiBaslat()">📷 Kamerayı Aç</button>
                <button id="btn_kapat" class="btn btn-danger fw-bold btn-lg w-100 d-none" onclick="kamerayiKapat()">❌ Kamerayı Kapat</button>
            </div>
        </div>
        <div class="card p-3 mb-4">
            <div class="mb-2">
                <label class="form-label text-uppercase">Barkod / Seri Numarası</label>
                <div class="input-group">
                    <input type="text" id="seri_no" class="form-control form-control-lg" placeholder="Barkod okutun" oninput="otomatikSorgula(this.value)">
                    <button class="btn btn-primary px-4" onclick="barkodSorgula()">Sorgula</button>
                </div>
            </div>
            <div id="durum_alani" class="alert text-center d-none fw-bold my-2 p-3"></div>
            <div id="form_alanlari"></div>
        </div>
    </div>
    <script>
        let html5QrcodeScanner = null;

        function defTarihGetir() {
            let simdi = new Date();
            return simdi.getFullYear() + '-' + String(simdi.getMonth() + 1).padStart(2, '0') + '-' + String(simdi.getDate()).padStart(2, '0') + 'T' + String(simdi.getHours()).padStart(2, '0') + ':' + String(simdi.getMinutes()).padStart(2, '0');
        }

        function otomatikSorgula(val) { if(val.trim().length >= 13) { barkodSorgula(); } }

        function onScanSuccess(decodedText, decodedResult) { 
            document.getElementById('seri_no').value = decodedText; 
            if (navigator.vibrate) navigator.vibrate(100); 
            kamerayiKapat(); 
            barkodSorgula(); 
        }

        function kamerayiBaslat() {
            document.getElementById('kamera_kapsayici').style.display = "block";
            document.getElementById('btn_ac').classList.add('d-none');
            document.getElementById('btn_kapat').classList.remove('d-none');
            
            if(html5QrcodeScanner !== null) { html5QrcodeScanner.clear(); }
            
            html5QrcodeScanner = new Html5Qrcode("reader");
            html5QrcodeScanner.start(
                { facingMode: "environment" }, 
                { fps: 15, qrbox: function(width, height) { return { width: 250, height: 200 }; } }, 
                onScanSuccess
            ).catch(err => {
                alert("Kamera açma hatası: Tarayıcı iznini kontrol edin.");
                KameraArayuzunuSifirla();
            });
        }

        function kamerayiKapat() {
            if(html5QrcodeScanner !== null) {
                html5QrcodeScanner.stop().then(() => { 
                    html5QrcodeScanner.clear(); 
                    KameraArayuzunuSifirla(); 
                }).catch(err => { KameraArayuzunuSifirla(); });
            } else { KameraArayuzunuSifirla(); }
        }

        function KameraArayuzunuSifirla() {
            document.getElementById('kamera_kapsayici').style.display = "none";
            document.getElementById('btn_ac').classList.remove('d-none');
            document.getElementById('btn_kapat').classList.add('d-none');
        }

        function barkodSorgula() {
            let seri = document.getElementById('seri_no').value.trim();
            if(!seri) return;
            fetch('index.php?action=sorgula&seri=' + seri)
            .then(res => res.json())
            .then(data => {
                let durumDiv = document.getElementById('durum_alani');
                let formDiv = document.getElementById('form_alanlari');
                durumDiv.classList.remove('d-none', 'alert-danger', 'alert-success', 'alert-info', 'alert-warning');
                formDiv.innerHTML = '';
                let anlikZaman = defTarihGetir();
                
                if(data.durum === 'Yok') {
                    durumDiv.classList.add('alert-danger'); durumDiv.innerText = "🔴 Ürün Kayıtlı Değil!";
                    formDiv.innerHTML = `
                        <div class="row g-2 mb-2"><div class="col-6"><label class="form-label">Marka</label><input type="text" id="inp_marka" class="form-control"></div><div class="col-6"><label class="form-label">Model</label><input type="text" id="inp_model" class="form-control"></div></div>
                        <div class="mb-2"><label class="form-label">Kimden Alındı (Tedarikçi)</label><input type="text" id="inp1" class="form-control" placeholder="Örn: Ahmet Toptan"></div>
                        <div class="mb-2"><label class="form-label">Alış Fiyatı (TL)</label><input type="number" step="0.01" id="inp2" class="form-control"></div>
                        <div class="mb-3"><label class="form-label">Alış Tarihi</label><input type="datetime-local" id="inp_tarih" class="form-control" value="${anlikZaman}"></div>
                        <button class="btn btn-success w-100 btn-lg fw-bold" onclick="islemYap('alis_kaydet')">STOĞA EKLE</button>`;
                } else if(data.durum === 'Stokta') {
                    let mrk = data.urun.marka ? data.urun.marka : '';
                    let mdl = data.urun.model ? data.urun.model : '';
                    let kimden = data.urun.kimden_alindi ? data.urun.kimden_alindi : 'Bilinmiyor';
                    durumDiv.classList.add('alert-success'); durumDiv.innerHTML = `🟢 STOKTA: ${mrk} ${mdl}<br><small>Kimden Alındı: ${kimden} | Alış: ${data.urun.alis_fiyati} TL</small>`;
                    formDiv.innerHTML = `
                        <div class="card p-3 bg-light mb-3 border">
                            <div class="mb-2"><label class="form-label">Kime Satıldı (Müşteri)</label><input type="text" id="inp1" class="form-control" placeholder="Örn: Mehmet Yılmaz"></div>
                            <div class="mb-2"><label class="form-label">Satış Fiyatı (TL)</label><input type="number" step="0.01" id="inp2" class="form-control"></div>
                            <div class="mb-3"><label class="form-label">Satış Tarihi</label><input type="datetime-local" id="inp_tarih" class="form-control" value="${anlikZaman}"></div>
                            <button class="btn btn-primary w-100 fw-bold" onclick="islemYap('satis_kaydet')">SATIŞI TAMAMLA</button>
                        </div><button class="btn btn-outline-danger w-100 btn-sm" onclick="islemYap('tedarikci_iade_kaydet')">↩️ Tedarikçiye İade Et</button>`;
                } else if(data.durum === 'Satildi') {
                    let mrk = data.urun.marka ? data.urun.marka : '';
                    let mdl = data.urun.model ? data.urun.model : '';
                    let kime = data.satis && data.satis.kime_satildi ? data.satis.kime_satildi : 'Bilinmiyor';
                    let fiyat = data.satis && data.satis.satis_fiyati ? data.satis.satis_fiyati : '0';
                    durumDiv.classList.add('alert-info'); durumDiv.innerHTML = `🔵 ÜRÜN SATILMIŞ<br><span class="text-dark fw-bold">${mrk} - ${mdl}</span><br><small>Kime Satıldı: ${kime} | Tutar: ${fiyat} TL</small>`;
                    formDiv.innerHTML = `<button class="btn btn-warning w-100 btn-lg fw-bold" onclick="islemYap('iade_kaydet')">🔄 MÜŞTERİDEN İADE AL</button>`;
                } else if(data.durum === 'Tedarikciye_Iade') {
                    durumDiv.classList.add('alert-warning'); durumDiv.innerHTML = `⚠️ TEDARİKÇİYE İADE EDİLMİŞ!`;
                    formDiv.innerHTML = `<button class="btn btn-dark w-100 fw-bold" onclick="islemYap('alis_kaydet')">↩️ TEKRAR STOĞA AL</button>`;
                }
            }).catch(() => { alert("Sorgulama hatası."); });
        }

        function islemYap(aksiyon) {
            let val1 = document.getElementById('inp1') ? document.getElementById('inp1').value : '';
            let val2 = document.getElementById('inp2') ? document.getElementById('inp2').value : '';
            let marka = document.getElementById('inp_marka') ? document.getElementById('inp_marka').value : '';
            let model = document.getElementById('inp_model') ? document.getElementById('inp_model').value : '';
            let tarih_secimi = document.getElementById('inp_tarih') ? document.getElementById('inp_tarih').value : '';
            let seri = document.getElementById('seri_no').value;
            fetch('index.php?action=islem', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({aksiyon: aksiyon, seri_no: seri, val1: val1, val2: val2, marka: marka, model: model, secilen_tarih: tarih_secimi})
            }).then(res => res.json()).then(data => {
                alert(data.mesaj); document.getElementById('seri_no').value = ''; document.getElementById('durum_alani').classList.add('d-none'); document.getElementById('form_alanlari').innerHTML = '';
            });
        }
    </script>
</body>
</html>
