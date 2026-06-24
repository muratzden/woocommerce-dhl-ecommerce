<?php
if (!defined('ABSPATH')) { exit; }

final class DHLWC_Label {
    public function register_hooks() {
        add_action('admin_post_dhlwc_print_label', array($this, 'handle_print_label'));
        add_action('admin_post_dhlwc_download_zpl', array($this, 'handle_download_zpl'));
    }

    public static function print_button(WC_Order $order) {
        $url = wp_nonce_url(
            add_query_arg(array('action' => 'dhlwc_print_label', 'order_id' => $order->get_id()), admin_url('admin-post.php')),
            'dhlwc_print_label_' . $order->get_id()
        );
        return '<a class="button dhlwc-action-button" target="_blank" href="' . esc_url($url) . '">Etiket Yazdır</a>';
    }

    public static function zpl_download_button(WC_Order $order) {
        if (!$order->get_meta(DHLWC_Constants::META_BARCODE_ZPL)) { return ''; }
        $url = wp_nonce_url(
            add_query_arg(array('action' => 'dhlwc_download_zpl', 'order_id' => $order->get_id()), admin_url('admin-post.php')),
            'dhlwc_download_zpl_' . $order->get_id()
        );
        return '<a class="button dhlwc-action-button" href="' . esc_url($url) . '">ZPL İndir</a>';
    }

    public function handle_download_zpl() {
        $order = $this->get_order_from_request('dhlwc_download_zpl');
        $zpl = (string) $order->get_meta(DHLWC_Constants::META_BARCODE_ZPL);
        if ($zpl === '') { wp_die('Bu sipariş için ZPL etiketi yok.'); }
        $filename = 'dhl-label-' . sanitize_file_name($order->get_order_number()) . '.zpl';
        nocache_headers();
        header('Content-Type: text/plain; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $zpl; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    public function handle_print_label() {
        $order = $this->get_order_from_request('dhlwc_print_label');
        $settings = DHLWC_Settings::get();
        nocache_headers();
        header('Content-Type: text/html; charset=UTF-8');
        echo $this->render_label_page($order, $settings); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    private function get_order_from_request($action) {
        if (!current_user_can('manage_woocommerce')) { wp_die('Yetkisiz işlem.'); }
        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        if (!$order_id || !check_admin_referer($action . '_' . $order_id)) { wp_die('Güvenlik doğrulaması başarısız.'); }
        $order = wc_get_order($order_id);
        if (!$order) { wp_die('Sipariş bulunamadı.'); }
        return $order;
    }

    private function render_label_page(WC_Order $order, array $settings) {
        $reference = $order->get_meta(DHLWC_Constants::META_REFERENCE_ID) ?: $this->make_reference_id($order);
        $piece_barcode = $order->get_meta(DHLWC_Constants::META_PIECE_BARCODE) ?: ($reference . '_P1');
        $shipment_id = $order->get_meta(DHLWC_Constants::META_SHIPMENT_ID);
        $invoice_id = $order->get_meta(DHLWC_Constants::META_INVOICE_ID);
        $barcode_type = $order->get_meta(DHLWC_Constants::META_BARCODE_TYPE);
        $barcode_title = $barcode_type === 'shipment' ? 'DHL ECOM GÖNDERİ BARKODU' : 'DHL ECOM SİPARİŞ BARKODU';
        $accent = !empty($settings['label_accent_color']) ? $settings['label_accent_color'] : '#ffcc00';
        $recipient_name = $this->recipient_name($order);
        $recipient_address = $this->recipient_address($order);
        $recipient_phone = $this->normalize_phone($order->get_billing_phone());
        $date = date_i18n('d/m/Y H:i', current_time('timestamp'));
        $sender = trim((string) $settings['label_sender_name']);
        if ($sender === '') { $sender = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES); }
        $sender_address = trim((string) $settings['label_sender_address']);
        $sender_phone = trim((string) $settings['label_sender_phone']);
        $logo = trim((string) $settings['label_logo_url']);
        $note = trim((string) $settings['label_note']);
        $zpl = (string) $order->get_meta(DHLWC_Constants::META_BARCODE_ZPL);
        $kg = max(1, (int) $settings['default_kg']);
        $desi = max(1, (int) $settings['default_desi']);

        ob_start();
        ?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>DHL Etiketi - <?php echo esc_html($reference); ?></title>
<style>
@page { size: A5 landscape; margin: 4mm; }
*{box-sizing:border-box} :root{--accent:<?php echo esc_html($accent); ?>;--ink:#111;--line:#111;--paper:#fff;--bg:#f3f4f6}
body{margin:0;background:var(--bg);color:var(--ink);font-family:Arial,Helvetica,sans-serif;font-size:12px}
.topbar{position:sticky;top:0;z-index:10;background:#0b1220;color:#fff;display:flex;justify-content:space-between;align-items:center;padding:9px 18px;box-shadow:0 2px 12px rgba(0,0,0,.20)}
.topbar h1{font-size:18px;margin:0;font-weight:800}.topbar .actions{display:flex;gap:8px}button{border:0;border-radius:7px;padding:9px 14px;font-weight:800;cursor:pointer}.primary{background:var(--accent);color:#111}.dark{background:#303744;color:#fff}.light{background:#fff;border:1px solid #d1d5db;color:#111}
.app{display:grid;grid-template-columns:310px 1fr;min-height:calc(100vh - 54px)}.sidebar{background:#fff;border-right:1px solid #e5e7eb;padding:20px}.panel{border:1px solid #e5e7eb;border-radius:10px;padding:17px;box-shadow:0 8px 26px rgba(15,23,42,.08)}.field{margin-bottom:18px}.field>label,.panel-title{display:block;font-size:12px;font-weight:900;letter-spacing:.5px;margin-bottom:8px}.select{width:100%;height:40px;border:1px solid #d1d5db;border-radius:8px;padding:0 10px;background:#fff}.option-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}.choice{background:#fff;border:1px solid #d1d5db}.choice.active{background:var(--accent);border-color:var(--accent)}.checks label{display:flex;gap:8px;align-items:center;margin:10px 0;font-weight:500}.checks input{width:16px;height:16px}.zoom-control{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px}.zoom-control button,.zoom-control span{height:40px;border:1px solid #d1d5db;border-radius:8px;display:flex;align-items:center;justify-content:center;background:#fff;font-weight:800}.full{width:100%;margin:6px 0}.info{background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;border-radius:8px;padding:10px;font-size:12px;line-height:1.35;margin-top:10px}.stage{padding:22px;overflow:auto}.stage-toolbar{height:40px;display:flex;gap:8px;justify-content:center;align-items:center;margin-bottom:10px}.stage-toolbar button,.stage-toolbar span{height:34px;min-width:38px;border:1px solid #d1d5db;background:#fff;border-radius:6px;display:flex;align-items:center;justify-content:center}
.sheet{margin:0 auto;background:#fff;padding:4mm;box-shadow:0 10px 35px rgba(0,0,0,.16);transform-origin:top center}.label{background:#fff;border:1.4px solid var(--line);overflow:hidden;display:grid}.paper-a5.landscape .sheet{width:210mm;height:148mm}.paper-a5.landscape .label{width:202mm;height:140mm;grid-template-rows:16mm 28mm 37mm 17mm 23mm 19mm}.paper-a5.portrait .sheet{width:148mm;height:210mm}.paper-a5.portrait .label{width:136mm;height:198mm;grid-template-rows:22mm 38mm 54mm 22mm 36mm 26mm}.paper-a4.landscape .sheet{width:297mm;height:210mm}.paper-a4.landscape .label{width:285mm;height:198mm;grid-template-rows:24mm 42mm 58mm 23mm 32mm 19mm}.paper-a4.portrait .sheet{width:210mm;height:297mm}.paper-a4.portrait .label{width:198mm;height:285mm;grid-template-rows:30mm 54mm 80mm 34mm 50mm 37mm}
.header{background:var(--accent);border-bottom:1px solid var(--line);display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px;align-items:center;padding:0 6mm}.brand{font-size:16px;font-weight:900;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.title{font-size:16px;font-weight:900;text-align:right;white-space:nowrap}.logo{max-width:45mm;max-height:12mm;object-fit:contain}.grid-two{display:grid;grid-template-columns:1fr 1fr;border-bottom:1px solid var(--line)}.box{padding:3mm 4mm;border-right:1px solid var(--line);overflow:hidden}.box:last-child{border-right:0}.box h3{margin:0 0 2mm;font-size:8px;letter-spacing:.3px}.box .name{font-size:12px;font-weight:900;margin-bottom:1.5mm}.box p{font-size:9px;line-height:1.18;margin:.8mm 0;word-break:break-word}.barcode-row{display:grid;grid-template-columns:minmax(0,1fr) 48mm;border-bottom:1px solid var(--line);min-width:0}.barcode-main{display:flex;flex-direction:column;align-items:center;justify-content:center;border-right:1px solid var(--line);padding:2mm 4mm;overflow:hidden;min-width:0}.barcode-main h2{font-size:11px;margin:0 0 2mm}.barcode-holder{width:100%;max-width:100%;height:18mm;display:flex;align-items:center;justify-content:center;overflow:hidden}.barcode-holder svg{width:100%;height:100%;display:block;max-width:100%}.barcode-text{font-size:18px;font-weight:900;margin-top:2mm;line-height:1}.side-info{display:grid;grid-template-rows:1fr 1fr 1fr;min-width:0}.side-info div{border-bottom:1px solid var(--line);padding:2mm;overflow:hidden}.side-info div:last-child{border-bottom:0}.side-info strong{display:block;font-size:6.4px;line-height:1.08;margin-bottom:1mm}.side-info span{display:block;font-size:9px;font-weight:900;line-height:1.1;overflow-wrap:anywhere}.metrics{display:grid;grid-template-columns:1fr 1fr 1fr;border-bottom:1px solid var(--line)}.metric{display:flex;flex-direction:column;align-items:center;justify-content:center;border-right:1px solid var(--line)}.metric:last-child{border-right:0}.metric strong{font-size:7px;margin-bottom:1.5mm}.metric span{font-size:15px;font-weight:900}.content{padding:3mm 4mm;border-bottom:1px dashed var(--line);overflow:hidden}.content strong{font-size:9px}.content p{font-size:9px;margin:1.4mm 0 0}.footer{display:grid;grid-template-columns:18mm minmax(0,1fr) 48mm;gap:4mm;align-items:center;padding:3mm 4mm;overflow:hidden}.footer svg{width:16mm;height:16mm}.footer p{font-size:8px;margin:.8mm 0;line-height:1.1}.notice{background:var(--accent);padding:3mm;border-radius:4px;font-size:8px;font-weight:800;line-height:1.25}.zpl{display:none}.hide-logo .logo,.hide-logo .brand{display:none}.hide-sender .grid-two .box:first-child{display:none}.hide-recipient .grid-two .box:last-child{display:none}.hide-sender .grid-two,.hide-recipient .grid-two{grid-template-columns:1fr}.hide-barcode .barcode-row{display:none}.hide-footer .footer{display:none}.hide-qr .footer{grid-template-columns:minmax(0,1fr) 48mm}.hide-qr .footer>div:first-child{display:none}
.paper-a5.portrait .barcode-holder{height:25mm}.paper-a5.portrait .barcode-text{font-size:20px}.paper-a4 .box p{font-size:11px}.paper-a4 .box .name{font-size:15px}.paper-a4 .barcode-main h2{font-size:13px}.paper-a4 .barcode-holder{height:32mm}.paper-a4 .barcode-text{font-size:24px}.paper-a4 .side-info span{font-size:13px}.paper-a4 .metric span{font-size:20px}.paper-a4 .content p,.paper-a4 .footer p{font-size:11px}.paper-a4 .notice{font-size:10px}.paper-a4 .footer svg{width:23mm;height:23mm}
@media print{body{background:#fff}.topbar,.sidebar,.stage-toolbar,.zpl{display:none!important}.app{display:block}.stage{padding:0;overflow:visible}.sheet{box-shadow:none;margin:0;padding:0;transform:none!important}.paper-a5.landscape .sheet{width:202mm;height:140mm}.paper-a5.landscape .label{width:202mm;height:140mm}.paper-a5.portrait .sheet{width:136mm;height:198mm}.paper-a5.portrait .label{width:136mm;height:198mm}.paper-a4.landscape .sheet{width:285mm;height:198mm}.paper-a4.landscape .label{width:285mm;height:198mm}.paper-a4.portrait .sheet{width:198mm;height:285mm}.paper-a4.portrait .label{width:198mm;height:285mm}}
@media(max-width:900px){.app{grid-template-columns:1fr}.sidebar{border-right:0;border-bottom:1px solid #e5e7eb}}
</style>
</head>
<body class="paper-a5 landscape">
<div class="topbar"><h1>DHL Etiketi Yazdır</h1><div class="actions"><button type="button" class="dark" onclick="copyZpl()">ZPL Kopyala</button><button type="button" class="dark" onclick="downloadZpl()">ZPL İndir</button><button type="button" class="primary" onclick="printLabel()">Yazdır</button></div></div>
<div class="app">
<aside class="sidebar"><div class="panel">
<div class="field"><label>KAĞIT BOYUTU</label><select class="select" id="paper-size" onchange="setPaper(this.value)"><option value="a5" selected>A5 (148 × 210 mm)</option><option value="a4">A4 (210 × 297 mm)</option></select><div class="info">Önerilen: A5 yatay. Yazdırma penceresinde kağıt boyutunu A5 ve kenar boşluklarını “Dar” seç.</div></div>
<div class="field"><label>YAZDIRMA YÖNÜ</label><div class="option-grid"><button type="button" id="orientation-portrait" class="choice" onclick="setOrientation('portrait')">Dikey</button><button type="button" id="orientation-landscape" class="choice active" onclick="setOrientation('landscape')">Yatay</button></div></div>
<div class="field checks"><span class="panel-title">GÖRÜNÜM</span><label><input type="checkbox" checked data-toggle="hide-logo"> Logo</label><label><input type="checkbox" checked data-toggle="hide-sender"> Gönderen Bilgileri</label><label><input type="checkbox" checked data-toggle="hide-recipient"> Alıcı Bilgileri</label><label><input type="checkbox" checked data-toggle="hide-barcode"> Sipariş Barkodu Bilgileri</label><label><input type="checkbox" checked data-toggle="hide-footer"> Alt Bilgiler</label><label><input type="checkbox" checked data-toggle="hide-qr"> QR Kod</label></div>
<div class="field"><label>ZOOM</label><div class="zoom-control"><button type="button" onclick="setZoom(-10)">−</button><span id="zoom-label">100%</span><button type="button" onclick="setZoom(10)">+</button></div></div>
<button type="button" class="primary full" onclick="printLabel()">Yazdır</button><button type="button" class="light full" onclick="downloadPdf()">PDF İndir</button><button type="button" class="light full" onclick="downloadPng()">Görsel Olarak İndir (PNG)</button>
</div></aside>
<main class="stage"><div class="stage-toolbar"><button type="button">‹</button><button type="button">›</button><span>1 / 1</span><button type="button" onclick="setZoom(-10)">−</button><span id="zoom-label-top">100%</span><button type="button" onclick="setZoom(10)">+</button></div>
<div class="sheet" id="sheet"><div class="label" id="label">
<div class="header"><div><?php if ($logo) : ?><img class="logo" src="<?php echo esc_url($logo); ?>" alt="Logo"><?php else : ?><div class="brand"><?php echo esc_html($sender); ?></div><?php endif; ?></div><div class="title"><?php echo esc_html($barcode_title); ?></div></div>
<div class="grid-two"><div class="box"><h3>GÖNDEREN</h3><div class="name"><?php echo esc_html($sender); ?></div><?php if ($sender_address) : ?><p><?php echo nl2br(esc_html($sender_address)); ?></p><?php endif; ?><?php if ($sender_phone) : ?><p><strong>Tel:</strong> <?php echo esc_html($sender_phone); ?></p><?php endif; ?><p><strong>Müşteri No:</strong> <?php echo esc_html($settings['customer_number']); ?></p></div><div class="box"><h3>ALICI</h3><div class="name"><?php echo esc_html($recipient_name); ?></div><p><?php echo nl2br(esc_html($recipient_address)); ?></p><?php if ($recipient_phone) : ?><p><strong>Tel:</strong> <?php echo esc_html($recipient_phone); ?></p><?php endif; ?></div></div>
<div class="barcode-row"><div class="barcode-main"><h2>SİPARİŞ BARKODU</h2><div class="barcode-holder"><?php echo $this->code39_svg($reference, 640, 100); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div><div class="barcode-text">&gt;:<?php echo esc_html($reference); ?></div></div><div class="side-info"><div><strong>REFERENCE ID</strong><span><?php echo esc_html($reference); ?></span></div><div><strong>BILL OF LANDING ID</strong><span><?php echo esc_html('WC-' . $order->get_order_number()); ?></span></div><div><strong>TARİH / SAAT</strong><span><?php echo esc_html($date); ?></span></div></div></div>
<div class="metrics"><div class="metric"><strong>PARÇA</strong><span>1 / 1</span></div><div class="metric"><strong>KG/DESİ</strong><span><?php echo esc_html($kg); ?> / <?php echo esc_html($desi); ?></span></div><div class="metric"><strong>GÖNDERİ NO</strong><span><?php echo esc_html($shipment_id ?: '-'); ?></span></div></div>
<div class="content"><strong>İÇERİK</strong><p><?php echo esc_html($settings['content_text']); ?></p><p><strong>PARÇA BARKODU:</strong> <?php echo esc_html($piece_barcode); ?><?php if ($invoice_id) : ?> &nbsp; <strong>FATURA NO:</strong> <?php echo esc_html($invoice_id); ?><?php endif; ?></p></div>
<div class="footer"><div><?php echo $this->qr_placeholder_svg($reference, 80); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div><div><p><strong>Sipariş No:</strong> <?php echo esc_html($order->get_order_number()); ?></p><p><strong>Referans:</strong> <?php echo esc_html($reference); ?></p><p><strong>Oluşturma:</strong> <?php echo esc_html($date); ?></p><p><strong>Tip:</strong> <?php echo esc_html($barcode_type === 'shipment' ? 'Gönderi Barkodu' : 'Referans Sipariş Barkodu'); ?></p></div><div class="notice"><?php echo nl2br(esc_html($note)); ?></div></div>
</div></div><textarea id="dhlwc-zpl" class="zpl" readonly><?php echo esc_textarea($zpl); ?></textarea></main></div>
<script>
var labelExportData=<?php echo wp_json_encode(array('reference'=>$reference,'pieceBarcode'=>$piece_barcode,'shipmentId'=>$shipment_id?:'-','invoiceId'=>$invoice_id?:'','orderNumber'=>$order->get_order_number(),'billOfLandingId'=>'WC-'.$order->get_order_number(),'date'=>$date,'sender'=>$sender,'senderAddress'=>$sender_address,'senderPhone'=>$sender_phone,'customerNumber'=>$settings['customer_number'],'recipient'=>$recipient_name,'recipientAddress'=>$recipient_address,'recipientPhone'=>$recipient_phone,'content'=>$settings['content_text'],'kg'=>$kg,'desi'=>$desi,'title'=>$barcode_title,'type'=>$barcode_type==='shipment'?'Gönderi Barkodu':'Referans Sipariş Barkodu','note'=>$note,'accent'=>$accent)); ?>;
var zoom=100,paper='a5',orientation='landscape';
function setPaper(v){paper=(v==='a4')?'a4':'a5';applyLayout()}function setOrientation(v){orientation=(v==='portrait')?'portrait':'landscape';applyLayout()}function applyLayout(){document.body.classList.remove('paper-a5','paper-a4','portrait','landscape');document.body.classList.add('paper-'+paper,orientation);document.getElementById('orientation-portrait').classList.toggle('active',orientation==='portrait');document.getElementById('orientation-landscape').classList.toggle('active',orientation==='landscape');var s=document.getElementById('dyn-page');if(!s){s=document.createElement('style');s.id='dyn-page';document.head.appendChild(s)}s.textContent='@page{size:'+paper.toUpperCase()+' '+orientation+';margin:4mm;}';}
function setZoom(delta){zoom=Math.max(50,Math.min(150,zoom+delta));updateZoom()}function updateZoom(){var sheet=document.getElementById('sheet');sheet.style.transform='scale('+(zoom/100)+')';document.getElementById('zoom-label').textContent=zoom+'%';document.getElementById('zoom-label-top').textContent=zoom+'%'}function printLabel(){window.print()}
document.querySelectorAll('[data-toggle]').forEach(function(i){i.addEventListener('change',function(){document.getElementById('label').classList.toggle(i.getAttribute('data-toggle'),!i.checked)})});
function copyZpl(){var el=document.getElementById('dhlwc-zpl');if(!el.value){alert('Bu sipariş için ZPL yok.');return}el.style.display='block';el.select();document.execCommand('copy');alert('ZPL kopyalandı.')}function downloadZpl(){var el=document.getElementById('dhlwc-zpl');if(!el.value){alert('Bu sipariş için ZPL yok.');return}downloadBlob(new Blob([el.value],{type:'text/plain;charset=utf-8'}),'dhl-label-<?php echo esc_js($reference); ?>.zpl')}
function mm(v){return Math.round(v*11.811)}function wrap(ctx,text,x,y,w,line,max){var words=String(text||'').replace(/\n/g,' ').split(' '),lineText='',lines=0;for(var n=0;n<words.length;n++){var test=lineText+words[n]+' ';if(ctx.measureText(test).width>w&&n>0){ctx.fillText(lineText,x,y);lineText=words[n]+' ';y+=line;lines++;if(max&&lines>=max-1){break}}else{lineText=test}}if(lineText){ctx.fillText(lineText.trim(),x,y)}}function drawBars(ctx,text,x,y,w,h){var s='*'+String(text||'').toUpperCase()+'*',bits='';for(var i=0;i<s.length;i++){var c=s.charCodeAt(i);for(var b=0;b<7;b++){bits+=((c>>b)&1)?'1110':'10'}bits+='000'}var bw=w/bits.length;ctx.fillStyle='#000';for(var j=0;j<bits.length;j++){if(bits[j]==='1'){ctx.fillRect(x+j*bw,y,Math.max(1,bw),h)}}}
function drawQr(ctx,x,y,size,seed){ctx.strokeStyle='#111';ctx.lineWidth=3;ctx.strokeRect(x,y,size,size);var cell=size/21;ctx.fillStyle='#111';function finder(px,py){ctx.fillRect(x+px*cell,y+py*cell,7*cell,7*cell);ctx.fillStyle='#fff';ctx.fillRect(x+(px+1)*cell,y+(py+1)*cell,5*cell,5*cell);ctx.fillStyle='#111';ctx.fillRect(x+(px+2)*cell,y+(py+2)*cell,3*cell,3*cell)}finder(1,1);finder(13,1);finder(1,13);var v=0;for(var i=0;i<String(seed).length;i++){v+=String(seed).charCodeAt(i)}for(var r=0;r<21;r++){for(var c=0;c<21;c++){if(((r*c+v+c)%5)===0&&r>8&&c>8){ctx.fillRect(x+c*cell,y+r*cell,cell,cell)}}}}
function drawCanvas(){var landscape=orientation==='landscape',a4=paper==='a4',W=landscape?(a4?1600:1200):(a4?1120:840),H=landscape?(a4?1120:840):(a4?1600:1200),d=labelExportData,accent=d.accent||'#ffcc00';var canvas=document.createElement('canvas');canvas.width=W;canvas.height=H;var ctx=canvas.getContext('2d');ctx.fillStyle='#fff';ctx.fillRect(0,0,W,H);ctx.strokeStyle='#111';ctx.lineWidth=2;var x=24,y=24,w=W-48,h=H-48;ctx.strokeRect(x,y,w,h);var header=landscape?h*.12:h*.11,addr=landscape?h*.20:h*.19,bar=landscape?h*.28:h*.27,metrics=landscape?h*.13:h*.12,content=landscape?h*.17:h*.20,footer=h-header-addr-bar-metrics-content;ctx.fillStyle=accent;ctx.fillRect(x,y,w,header);ctx.strokeRect(x,y,w,header);ctx.fillStyle='#111';ctx.font='bold '+Math.round(header*.25)+'px Arial';ctx.fillText(d.sender||'',x+w*.03,y+header*.58);ctx.textAlign='right';ctx.fillText(d.title||'',x+w*.97,y+header*.58);ctx.textAlign='left';y+=header;ctx.strokeRect(x,y,w,addr);ctx.beginPath();ctx.moveTo(x+w/2,y);ctx.lineTo(x+w/2,y+addr);ctx.stroke();ctx.font='bold '+Math.round(addr*.08)+'px Arial';ctx.fillText('GÖNDEREN',x+w*.02,y+addr*.18);ctx.fillText('ALICI',x+w*.52,y+addr*.18);ctx.font='bold '+Math.round(addr*.14)+'px Arial';ctx.fillText(d.sender||'',x+w*.02,y+addr*.38);ctx.fillText(d.recipient||'',x+w*.52,y+addr*.38);ctx.font=Math.round(addr*.085)+'px Arial';wrap(ctx,d.senderAddress,x+w*.02,y+addr*.58,w*.42,addr*.11,2);wrap(ctx,d.recipientAddress,x+w*.52,y+addr*.58,w*.42,addr*.11,2);ctx.font='bold '+Math.round(addr*.08)+'px Arial';ctx.fillText('Tel: '+(d.senderPhone||''),x+w*.02,y+addr*.88);ctx.fillText('Tel: '+(d.recipientPhone||''),x+w*.52,y+addr*.88);y+=addr;var side=w*.27,main=w-side;ctx.strokeRect(x,y,w,bar);ctx.beginPath();ctx.moveTo(x+main,y);ctx.lineTo(x+main,y+bar);for(var rr=1;rr<3;rr++){ctx.moveTo(x+main,y+bar/3*rr);ctx.lineTo(x+w,y+bar/3*rr)}ctx.stroke();ctx.textAlign='center';ctx.font='bold '+Math.round(bar*.09)+'px Arial';ctx.fillText('SİPARİŞ BARKODU',x+main/2,y+bar*.18);drawBars(ctx,d.reference,x+main*.06,y+bar*.32,main*.88,bar*.34);ctx.font='bold '+Math.round(bar*.14)+'px Arial';ctx.fillText('>:'+(d.reference||''),x+main/2,y+bar*.82);ctx.textAlign='left';ctx.font='bold '+Math.round(bar*.055)+'px Arial';ctx.fillText('REFERENCE ID',x+main+side*.08,y+bar*.17);ctx.fillText('BILL OF LANDING ID',x+main+side*.08,y+bar*.50);ctx.fillText('TARİH / SAAT',x+main+side*.08,y+bar*.83);ctx.font='bold '+Math.round(bar*.08)+'px Arial';ctx.fillText(d.reference||'',x+main+side*.08,y+bar*.28);ctx.fillText(d.billOfLandingId||'',x+main+side*.08,y+bar*.61);ctx.fillText(d.date||'',x+main+side*.08,y+bar*.94);y+=bar;ctx.strokeRect(x,y,w,metrics);ctx.beginPath();ctx.moveTo(x+w/3,y);ctx.lineTo(x+w/3,y+metrics);ctx.moveTo(x+2*w/3,y);ctx.lineTo(x+2*w/3,y+metrics);ctx.stroke();ctx.textAlign='center';ctx.font='bold '+Math.round(metrics*.13)+'px Arial';ctx.fillText('PARÇA',x+w/6,y+metrics*.35);ctx.fillText('KG/DESİ',x+w/2,y+metrics*.35);ctx.fillText('GÖNDERİ NO',x+5*w/6,y+metrics*.35);ctx.font='bold '+Math.round(metrics*.28)+'px Arial';ctx.fillText('1 / 1',x+w/6,y+metrics*.72);ctx.fillText((d.kg||1)+' / '+(d.desi||1),x+w/2,y+metrics*.72);ctx.fillText(d.shipmentId||'-',x+5*w/6,y+metrics*.72);ctx.textAlign='left';y+=metrics;ctx.strokeRect(x,y,w,content);ctx.font='bold '+Math.round(content*.10)+'px Arial';ctx.fillText('İÇERİK',x+w*.02,y+content*.22);ctx.font=Math.round(content*.10)+'px Arial';ctx.fillText(d.content||'Ürün',x+w*.02,y+content*.44);ctx.font='bold '+Math.round(content*.10)+'px Arial';ctx.fillText('PARÇA BARKODU:',x+w*.02,y+content*.72);ctx.font=Math.round(content*.10)+'px Arial';ctx.fillText(d.pieceBarcode||'',x+w*.02,y+content*.88);y+=content;ctx.strokeRect(x,y,w,footer);var q=Math.min(footer*.72,w*.10);drawQr(ctx,x+w*.02,y+(footer-q)/2,q,d.reference);ctx.font='bold '+Math.round(footer*.10)+'px Arial';var tx=x+w*.02+q+w*.02;ctx.fillText('Sipariş No: '+(d.orderNumber||''),tx,y+footer*.25);ctx.fillText('Referans: '+(d.reference||''),tx,y+footer*.45);ctx.fillText('Oluşturma: '+(d.date||''),tx,y+footer*.65);ctx.fillText('Tip: '+(d.type||''),tx,y+footer*.85);var nw=w*.30,nh=footer*.58;ctx.fillStyle=accent;ctx.fillRect(x+w-nw-w*.02,y+(footer-nh)/2,nw,nh);ctx.fillStyle='#111';ctx.font='bold '+Math.round(nh*.16)+'px Arial';wrap(ctx,d.note,x+w-nw-w*.005,y+(footer-nh)/2+nh*.34,nw-w*.03,nh*.22,3);return canvas}
function downloadPng(){var c=drawCanvas(),a=document.createElement('a');a.href=c.toDataURL('image/png');a.download='dhl-label-<?php echo esc_js($reference); ?>.png';document.body.appendChild(a);a.click();document.body.removeChild(a)}function b64bytes(data){var bin=atob(data.split(',')[1]),arr=new Uint8Array(bin.length);for(var i=0;i<bin.length;i++){arr[i]=bin.charCodeAt(i)}return arr}function ascii(s){var a=new Uint8Array(s.length);for(var i=0;i<s.length;i++){a[i]=s.charCodeAt(i)&255}return a}function join(parts){var l=0;parts.forEach(function(p){l+=p.length});var out=new Uint8Array(l),o=0;parts.forEach(function(p){out.set(p,o);o+=p.length});return out}function pdfFromJpeg(jpg,iw,ih){var pw=paper==='a4'?595:420,ph=paper==='a4'?842:595;if(orientation==='landscape'){var t=pw;pw=ph;ph=t}var m=18,mw=pw-m*2,mh=ph-m*2,r=Math.min(mw/iw,mh/ih),dw=iw*r,dh=ih*r,px=(pw-dw)/2,py=(ph-dh)/2,content='q\n'+dw.toFixed(2)+' 0 0 '+dh.toFixed(2)+' '+px.toFixed(2)+' '+py.toFixed(2)+' cm\n/Im0 Do\nQ\n',objs=[];objs.push(ascii('1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n'));objs.push(ascii('2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n'));objs.push(ascii('3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '+pw+' '+ph+'] /Resources << /XObject << /Im0 4 0 R >> >> /Contents 5 0 R >>\nendobj\n'));objs.push(join([ascii('4 0 obj\n<< /Type /XObject /Subtype /Image /Width '+iw+' /Height '+ih+' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '+jpg.length+' >>\nstream\n'),jpg,ascii('\nendstream\nendobj\n')]));objs.push(ascii('5 0 obj\n<< /Length '+content.length+' >>\nstream\n'+content+'endstream\nendobj\n'));var head=ascii('%PDF-1.4\n%\xE2\xE3\xCF\xD3\n'),off=[0],cur=head.length;objs.forEach(function(o){off.push(cur);cur+=o.length});var xref='xref\n0 6\n0000000000 65535 f \n';for(var i=1;i<off.length;i++){xref+=String(off[i]).padStart(10,'0')+' 00000 n \n'}return join([head].concat(objs).concat([ascii(xref+'trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n'+cur+'\n%%EOF')]))}function downloadPdf(){var c=drawCanvas(),jpg=b64bytes(c.toDataURL('image/jpeg',.92)),blob=new Blob([pdfFromJpeg(jpg,c.width,c.height)],{type:'application/pdf'});downloadBlob(blob,'dhl-label-<?php echo esc_js($reference); ?>.pdf')}function downloadBlob(blob,name){var a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download=name;document.body.appendChild(a);a.click();document.body.removeChild(a);setTimeout(function(){URL.revokeObjectURL(a.href)},1000)}applyLayout();updateZoom();
</script>
</body></html>
        <?php
        return ob_get_clean();
    }

    private function recipient_name(WC_Order $order) {
        $name = trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name());
        if ($name === '') { $name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()); }
        return $name ?: 'Alıcı';
    }

    private function recipient_address(WC_Order $order) {
        $address = trim(($order->get_shipping_address_1() ?: $order->get_billing_address_1()) . ' ' . ($order->get_shipping_address_2() ?: $order->get_billing_address_2()));
        $city = $order->get_shipping_city() ?: $order->get_billing_city();
        $state = $order->get_shipping_state() ?: $order->get_billing_state();
        $postcode = $order->get_shipping_postcode() ?: $order->get_billing_postcode();
        return trim($address . "\n" . $city . ' ' . $state . ' ' . $postcode);
    }
    private function normalize_phone($phone) {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if (strlen($digits) > 10 && substr($digits, 0, 2) === '90') { $digits = substr($digits, 2); }
        if (strlen($digits) > 10 && substr($digits, 0, 1) === '0') { $digits = substr($digits, 1); }
        return substr($digits, -10);
    }

    private function make_reference_id(WC_Order $order) {
        return substr(strtoupper(preg_replace('/[^A-Z0-9]/', '', remove_accents('WC' . $order->get_order_number()))), 0, 20);
    }

    private function code39_svg($text, $width = 520, $height = 110) {
        $patterns = array(
            '0'=>'101001101101','1'=>'110100101011','2'=>'101100101011','3'=>'110110010101','4'=>'101001101011','5'=>'110100110101','6'=>'101100110101','7'=>'101001011011','8'=>'110100101101','9'=>'101100101101',
            'A'=>'110101001011','B'=>'101101001011','C'=>'110110100101','D'=>'101011001011','E'=>'110101100101','F'=>'101101100101','G'=>'101010011011','H'=>'110101001101','I'=>'101101001101','J'=>'101011001101',
            'K'=>'110101010011','L'=>'101101010011','M'=>'110110101001','N'=>'101011010011','O'=>'110101101001','P'=>'101101101001','Q'=>'101010110011','R'=>'110101011001','S'=>'101101011001','T'=>'101011011001',
            'U'=>'110010101011','V'=>'100110101011','W'=>'110011010101','X'=>'100101101011','Y'=>'110010110101','Z'=>'100110110101','-'=>'100101011011','.'=>'110010101101',' '=>'100110101101','$'=>'100100100101','/'=>'100100101001','+'=>'100101001001','%'=>'101001001001','*'=>'100101101101','_'=>'100100101101'
        );
        $text = strtoupper((string) $text);
        $text = '*' . preg_replace('/[^A-Z0-9\-\. \$\/\+%_]/', '', $text) . '*';
        $bits = '';
        for ($i = 0; $i < strlen($text); $i++) {
            $char = $text[$i];
            $bits .= isset($patterns[$char]) ? $patterns[$char] . '0' : '';
        }
        $unit = max(1, $width / max(1, strlen($bits)));
        $x = 0;
        $rects = '';
        for ($i = 0; $i < strlen($bits); $i++) {
            if ($bits[$i] === '1') {
                $rects .= '<rect x="' . esc_attr(round($x, 2)) . '" y="0" width="' . esc_attr(round($unit, 2)) . '" height="' . esc_attr($height) . '"/>';
            }
            $x += $unit;
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . esc_attr($width) . ' ' . esc_attr($height) . '" preserveAspectRatio="none" role="img" aria-label="Barcode"><g fill="#000">' . $rects . '</g></svg>';
    }

    private function qr_placeholder_svg($text, $size = 80) {
        $seed = crc32((string) $text);
        $cell = $size / 21;
        $out = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . esc_attr($size) . ' ' . esc_attr($size) . '" role="img" aria-label="QR"><rect width="' . esc_attr($size) . '" height="' . esc_attr($size) . '" fill="#fff"/><g fill="#111">';
        $finders = array(array(1,1), array(13,1), array(1,13));
        foreach ($finders as $f) {
            $out .= '<rect x="' . esc_attr($f[0] * $cell) . '" y="' . esc_attr($f[1] * $cell) . '" width="' . esc_attr(7 * $cell) . '" height="' . esc_attr(7 * $cell) . '"/>';
            $out .= '<rect fill="#fff" x="' . esc_attr(($f[0] + 1) * $cell) . '" y="' . esc_attr(($f[1] + 1) * $cell) . '" width="' . esc_attr(5 * $cell) . '" height="' . esc_attr(5 * $cell) . '"/>';
            $out .= '<rect x="' . esc_attr(($f[0] + 2) * $cell) . '" y="' . esc_attr(($f[1] + 2) * $cell) . '" width="' . esc_attr(3 * $cell) . '" height="' . esc_attr(3 * $cell) . '"/>';
        }
        for ($r = 0; $r < 21; $r++) {
            for ($c = 0; $c < 21; $c++) {
                if ($r < 8 && $c < 8) { continue; }
                if ($r < 8 && $c > 12) { continue; }
                if ($r > 12 && $c < 8) { continue; }
                if ((($r * $c + $seed + $c) % 5) === 0) {
                    $out .= '<rect x="' . esc_attr($c * $cell) . '" y="' . esc_attr($r * $cell) . '" width="' . esc_attr($cell) . '" height="' . esc_attr($cell) . '"/>';
                }
            }
        }
        return $out . '</g><rect x="0.5" y="0.5" width="' . esc_attr($size - 1) . '" height="' . esc_attr($size - 1) . '" fill="none" stroke="#111"/></svg>';
    }
}
