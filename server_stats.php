<?php
require_once 'auth.php';
require_once 'config.php';
require_once 'games.php';
require_once __DIR__ . '/lang.php';
require_once __DIR__ . '/stats/stats_lib.php';

$userId = (int)$_SESSION['user_id'];
$serverId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isAdmin = (($_SESSION['role'] ?? '') === 'admin');

$stmt = $pdo->prepare("SELECT s.*, b.name AS box_name, b.ip_address AS node_ip, b.ssh_port AS api_port, b.ssh_password AS api_token FROM servers s JOIN boxes b ON s.box_id = b.id WHERE s.id = ?" . ($isAdmin ? "" : " AND s.user_id = ?"));
$stmt->execute($isAdmin ? [$serverId] : [$serverId, $userId]);
$server = $stmt->fetch();
if (!$server) { header("Location: dashboard.php"); exit; }
session_write_close();

$game = (string)$server['game'];
$supported = statsGameSupported($game);
$hasMaps = statsGameHasMaps($game);
$range = in_array($_GET['range'] ?? '', ['24h', '7d', '30d'], true) ? $_GET['range'] : '24h';

function statsJson($data, int $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(swiftLangJsonData($data), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (($_GET['ajax'] ?? '') === 'data') {
    if (!$supported) { statsJson(['ok' => false, 'error' => 'unsupported'], 200); }
    try {
        statsEnsureSchema($pdo);
        statsJson(['ok' => true] + statsReport($pdo, (int)$server['id'], $range));
    } catch (Throwable $e) {
        statsJson(['ok' => false, 'error' => 'Statistics are not available yet.'], 200);
    }
}

if (($_GET['ajax'] ?? '') === 'live') {
    if (!$supported) { statsJson(['ok' => false, 'error' => 'unsupported']); }
    $r = statsCallNode($server, '/api/servers/query', [
        'game' => $game, 'screen_name' => $server['screen_name'], 'ip_address' => $server['ip_address'],
        'port' => (int)$server['port'], 'resources' => true,
    ], 8);
    if (!$r || empty($r['ok'])) { statsJson(['ok' => false, 'error' => 'The server node did not answer. It may be updating.']); }
    unset($r['ok']);
    statsJson(['ok' => true, 'live' => $r, 'now' => time()]);
}

$title = htmlspecialchars((string)$server['name']);
?>
<?php require_once __DIR__ . '/lang.php'; swiftLangStart(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Statistics - <?php echo $title; ?></title>
<link rel="stylesheet" href="assets/style.css?v=3">
<style>
:root{--s1:#3987e5;--s2:#d95926;--s3:#199e70;--s4:#c98500;--s5:#d55181;--s6:#0ca30c;--s7:#9085e9;--s8:#e66767;--ink:#e6edf3;--ink2:#aab4c3;--muted:#7d8899;--card:#141820;--line:#262c3a;--grid:#222836;--surf:#0f131a}
.container{max-width:1180px;margin:24px auto;padding:0 18px}
.top{display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;margin-bottom:16px}
.top h1{font-size:20px;margin:0;color:#fff}
.top .sub{color:var(--muted);font-size:13px;margin-top:3px}
.seg{display:inline-flex;border:1px solid var(--line);border-radius:8px;overflow:hidden}
.seg a{padding:7px 16px;font-size:13px;color:var(--ink2);text-decoration:none;background:var(--card);border-right:1px solid var(--line)}
.seg a:last-child{border-right:0}.seg a.on{background:#1d2a40;color:#fff;font-weight:600}
.tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:16px}
.tile{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:14px 16px}
.tile .k{font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em}
.tile .v{font-size:26px;font-weight:700;color:#fff;margin-top:4px;font-variant-numeric:tabular-nums}
.tile .v small{font-size:13px;color:var(--ink2);font-weight:400}
.tile .d{font-size:12px;color:var(--muted);margin-top:2px}
.grid{display:grid;grid-template-columns:repeat(12,1fr);gap:14px}
.card{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:16px;min-width:0}
.card h2{font-size:14px;margin:0 0 2px;color:#fff}.card .hint{font-size:12px;color:var(--muted);margin-bottom:10px}
.c12{grid-column:span 12}.c8{grid-column:span 8}.c7{grid-column:span 7}.c5{grid-column:span 5}.c4{grid-column:span 4}.c6{grid-column:span 6}
@media(max-width:900px){.c8,.c7,.c5,.c4,.c6{grid-column:span 12}}
svg text{font-family:inherit;fill:var(--muted);font-size:11px}
.legend{display:flex;flex-wrap:wrap;gap:6px 14px;font-size:12px;color:var(--ink2);margin-top:8px}
.legend i{display:inline-block;width:10px;height:10px;border-radius:2px;margin-right:6px;vertical-align:-1px}
.chart{position:relative}
.tip{position:absolute;pointer-events:none;background:#0b0e14;border:1px solid #33405a;border-radius:8px;padding:8px 10px;font-size:12px;color:var(--ink);white-space:nowrap;z-index:5;display:none;box-shadow:0 6px 20px rgba(0,0,0,.45)}
.tip b{color:#fff}.tip .r{color:var(--ink2)}
table.t{width:100%;border-collapse:collapse;font-size:13px}
table.t th{text-align:left;color:var(--muted);font-weight:500;font-size:12px;padding:6px 8px;border-bottom:1px solid var(--line)}
table.t td{padding:7px 8px;border-bottom:1px solid #1b2130;color:var(--ink);font-variant-numeric:tabular-nums}
table.t td.n,table.t th.n{text-align:right}
.dot{display:inline-block;width:9px;height:9px;border-radius:2px;margin-right:8px}
.live{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--ink2)}
.pulse{width:9px;height:9px;border-radius:50%;background:#2ecc71;box-shadow:0 0 8px #2ecc71}.pulse.off{background:#e74c3c;box-shadow:none}
.empty{color:var(--muted);font-size:13px;padding:26px 6px;text-align:center}
.note{background:#1a2230;border:1px solid #2a3550;border-radius:8px;padding:12px 14px;color:var(--ink2);font-size:13px;margin-bottom:14px}
.viewtbl{font-size:12px;color:var(--muted);cursor:pointer;background:none;border:0;padding:0;margin-top:6px;text-decoration:underline}
</style>
</head>
<body>
<div id="header">
    <div class="logo">Swift<span>Panel</span> <small style="font-size:11px;color:#aaa;">Springs Docker Edition</small></div>
    <div class="user-info">Logged in as: <strong><?php echo htmlspecialchars($_SESSION['username'] ?? 'client'); ?></strong> | <a href="logout.php">Logout</a></div>
</div>
<div id="navbar"><ul>
    <li><a href="dashboard.php" class="active">Game Servers</a></li>
    <li><a href="dashboard.php?page=order">Order Server</a></li>
    <li><a href="dashboard.php?page=invoices">Invoices</a></li>
    <li><a href="mods/index.php">Mods &amp; Plugins</a></li>
    <li><a href="tickets.php">Support Tickets</a></li>
    <li><a href="profile.php">My Profile</a></li>
</ul></div>

<div class="container">
    <div class="top">
        <div>
            <h1><?php echo $title; ?> &middot; Statistics</h1>
            <div class="sub"><code><?php echo htmlspecialchars($server['ip_address'] . ':' . $server['port']); ?></code> &middot; <?php echo htmlspecialchars($server['box_name']); ?>
                &middot; <a href="server.php?id=<?php echo (int)$server['id']; ?>">Console</a> &middot; <a href="dashboard.php">Back to servers</a></div>
        </div>
        <div class="seg" id="rangeSeg">
            <?php foreach (['24h' => 'Last 24 hours', '7d' => '7 days', '30d' => '30 days'] as $k => $l): ?>
                <a href="?id=<?php echo (int)$server['id']; ?>&amp;range=<?php echo $k; ?>" data-r="<?php echo $k; ?>" class="<?php echo $k === $range ? 'on' : ''; ?>"><?php echo $l; ?></a>
            <?php endforeach; ?>
        </div>
    </div>

<?php if (!$supported): ?>
    <div class="note">Live statistics are not available for this game yet. Player counts need a query protocol, which Counter-Strike 1.6, CS2, Garry's Mod, Team Fortress 2, Left 4 Dead 2, Rust, Valheim, 7 Days to Die and Minecraft provide.</div>
<?php else: ?>
    <div class="tiles" id="tiles"></div>
    <div class="grid">
        <div class="card c12"><h2>Players online</h2><div class="hint">Average and busiest moment in each period. Hover for details.</div>
            <div class="chart" id="ch-players"></div><div class="legend" id="lg-players"></div><div id="tb-players"></div></div>

        <div class="card c5"><h2>Right now</h2><div class="hint" id="liveHint">Asking the server...</div>
            <div id="live"></div></div>
        <div class="card c7"><h2>Peak hours</h2><div class="hint">Average players by weekday and hour, in your local time.</div>
            <div class="chart" id="ch-heat"></div></div>

<?php if ($hasMaps): ?>
        <div class="card c5"><h2>Map popularity</h2><div class="hint">Share of online time spent on each map.</div>
            <div class="chart" id="ch-maps"></div><div class="legend" id="lg-maps"></div></div>
        <div class="card c7"><h2>Map rotation</h2><div class="hint">Which map was running when. Wider means longer.</div>
            <div class="chart" id="ch-rot"></div><div id="rot-list"></div></div>
        <div class="card c12"><h2>Map details</h2><div class="hint">How often each map came up and how well it held players.</div><div id="map-table"></div></div>
<?php endif; ?>

        <div class="card c6"><h2>CPU usage</h2><div class="hint">Percent of one core used by the game server.</div><div class="chart" id="ch-cpu"></div></div>
        <div class="card c6"><h2>Memory</h2><div class="hint">RAM used by the game server, in MB.</div><div class="chart" id="ch-mem"></div></div>
    </div>
<?php endif; ?>
</div>

<?php if ($supported): ?>
<script>
(function(){
var SID=<?php echo (int)$server['id']; ?>, RANGE=<?php echo json_encode($range); ?>, HASMAPS=<?php echo $hasMaps ? 'true' : 'false'; ?>;
var COL=['#3987e5','#d95926','#199e70','#c98500','#d55181','#0ca30c','#9085e9','#e66767'], OTHER='#5b667a';
var NS='http://www.w3.org/2000/svg';
function $(i){return document.getElementById(i)}
function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]})}
function el(n,a,p){var e=document.createElementNS(NS,n);for(var k in a)e.setAttribute(k,a[k]);if(p)p.appendChild(e);return e}
function dur(s){s=Math.round(s);if(s<60)return s+'s';var m=Math.floor(s/60);if(m<60)return m+' min';var h=Math.floor(m/60);m=m%60;if(h<48)return h+' h'+(m?' '+m+' min':'');return Math.floor(h/24)+' d '+(h%24)+' h'}
function fmtT(t,withDay){var d=new Date(t*1000);var hm=('0'+d.getHours()).slice(-2)+':'+('0'+d.getMinutes()).slice(-2);return withDay?d.toLocaleDateString(undefined,{month:'short',day:'numeric'})+' '+hm:hm}
function niceMax(v){if(v<=4)return 4;var p=Math.pow(10,Math.floor(Math.log10(v)));var f=v/p;var st=[1,1.5,2,3,4,5,6,8,10];for(var i=0;i<st.length;i++)if(f<=st[i])return st[i]*p;return 10*p}
function tip(box){var t=document.createElement('div');t.className='tip';box.appendChild(t);return t}
function place(t,box,x,y){t.style.display='block';var w=t.offsetWidth,bw=box.clientWidth;var l=x+14;if(l+w>bw)l=x-w-14;t.style.left=Math.max(0,l)+'px';t.style.top=Math.max(0,y-10)+'px'}
function empty(box,msg){box.innerHTML='<div class="empty">'+esc(msg)+'</div>'}
function mapColors(maps){var m={};maps.forEach(function(x,i){m[x.map]=i<7?COL[i]:OTHER});return m}

/* ---- line / area chart with crosshair ---- */
function lineChart(box,pts,o){
  box.innerHTML='';
  if(!pts.length){empty(box,o.empty||'No data for this period yet.');return}
  var W=box.clientWidth||600,H=o.h||250,L=40,R=12,T=10,B=26,iw=W-L-R,ih=H-T-B;
  var svg=el('svg',{width:W,height:H,viewBox:'0 0 '+W+' '+H,role:'img','aria-label':o.label},null);
  var t0=o.from,t1=o.to,ymax=niceMax(Math.max.apply(null,pts.map(function(p){return Math.max(p.y,p.y2||0)}))*1.08||1);
  function X(t){return L+(t-t0)/(t1-t0)*iw} function Y(v){return T+ih-v/ymax*ih}
  for(var i=0;i<=4;i++){var v=ymax*i/4,y=Y(v);el('line',{x1:L,x2:W-R,y1:y,y2:y,stroke:'#222836'},svg);var tx=el('text',{x:L-6,y:y+4,'text-anchor':'end'},svg);tx.textContent=o.yfmt?o.yfmt(v):(Math.round(v*10)/10)}
  var span=t1-t0,step=span<=90000?3600*4:span<=700000?86400:86400*5,ft=Math.ceil(t0/step)*step;
  for(var t=ft;t<=t1;t+=step){var x=X(t);el('line',{x1:x,x2:x,y1:T+ih,y2:T+ih+4,stroke:'#33405a'},svg);var tl=el('text',{x:x,y:H-8,'text-anchor':'middle'},svg);var d=new Date(t*1000);tl.textContent=span<=90000?fmtT(t):d.toLocaleDateString(undefined,{month:'short',day:'numeric'})}
  /* gaps: break the line where there is no data for more than 3 buckets */
  var gap=o.bucket*3.2,segs=[],cur=[];
  pts.forEach(function(p,i){if(i&&p.t-pts[i-1].t>gap){segs.push(cur);cur=[]}cur.push(p)});segs.push(cur);
  segs.forEach(function(s){
    if(o.max2){var d2='';s.forEach(function(p,i){d2+=(i?'L':'M')+X(p.t+o.bucket/2).toFixed(1)+' '+Y(p.y2).toFixed(1)});el('path',{d:d2,fill:'none',stroke:o.c2,'stroke-width':1.5,'stroke-dasharray':'4 3'},svg)}
    var d='',a='';s.forEach(function(p,i){var x=X(p.t+o.bucket/2).toFixed(1),y=Y(p.y).toFixed(1);d+=(i?'L':'M')+x+' '+y});
    if(s.length>1){a=d+'L'+X(s[s.length-1].t+o.bucket/2).toFixed(1)+' '+(T+ih)+'L'+X(s[0].t+o.bucket/2).toFixed(1)+' '+(T+ih)+'Z';el('path',{d:a,fill:o.c1,'fill-opacity':.14,stroke:'none'},svg)}
    el('path',{d:d,fill:'none',stroke:o.c1,'stroke-width':2,'stroke-linejoin':'round'},svg);
    if(s.length===1)el('circle',{cx:X(s[0].t+o.bucket/2),cy:Y(s[0].y),r:3,fill:o.c1},svg)
  });
  var cross=el('line',{y1:T,y2:T+ih,stroke:'#556,',opacity:0,'stroke-width':1},svg);cross.setAttribute('stroke','#6b7a93');
  var dot=el('circle',{r:5,fill:o.c1,stroke:'#141820','stroke-width':2,opacity:0},svg);
  var hit=el('rect',{x:L,y:T,width:iw,height:ih,fill:'transparent'},svg);
  box.appendChild(svg);var tp=tip(box);
  hit.addEventListener('mousemove',function(ev){var r=svg.getBoundingClientRect(),mx=(ev.clientX-r.left)*(W/r.width),t=t0+(mx-L)/iw*(t1-t0),best=null,bd=1e18;
    pts.forEach(function(p){var dd=Math.abs(p.t+o.bucket/2-t);if(dd<bd){bd=dd;best=p}});
    if(!best||bd>o.bucket*2){tp.style.display='none';cross.setAttribute('opacity',0);dot.setAttribute('opacity',0);return}
    var x=X(best.t+o.bucket/2);cross.setAttribute('x1',x);cross.setAttribute('x2',x);cross.setAttribute('opacity',1);dot.setAttribute('cx',x);dot.setAttribute('cy',Y(best.y));dot.setAttribute('opacity',1);
    tp.innerHTML='<b>'+fmtT(best.t,span>90000)+'</b><br><span class="r">'+esc(o.n1)+':</span> '+o.tfmt(best.y)+(o.max2?'<br><span class="r">'+esc(o.n2)+':</span> '+o.tfmt(best.y2):'')+(best.extra?'<br><span class="r">'+best.extra+'</span>':'');
    place(tp,box,x*(r.width/W),Y(best.y)*(r.height/H))});
  hit.addEventListener('mouseleave',function(){tp.style.display='none';cross.setAttribute('opacity',0);dot.setAttribute('opacity',0)})
}

/* ---- peak-hours heat map: weekday x hour, drawn in the viewer's time zone ---- */
function heat(box,hourly){
  box.innerHTML='';
  if(RANGE==='24h'){empty(box,'Pick 7 or 30 days to see weekly patterns.');return}
  var sum=[],cnt=[],i,j;for(i=0;i<7;i++){sum.push(new Array(24).fill(0));cnt.push(new Array(24).fill(0))}
  hourly.forEach(function(h){var d=new Date(h.t*1000),wd=(d.getDay()+6)%7,hr=d.getHours();sum[wd][hr]+=h.avg;cnt[wd][hr]++});
  var mx=0,avg=[];for(i=0;i<7;i++){avg.push([]);for(j=0;j<24;j++){var v=cnt[i][j]?sum[i][j]/cnt[i][j]:null;avg[i].push(v);if(v!==null&&v>mx)mx=v}}
  if(!hourly.length){empty(box,'No data for this period yet.');return}
  var W=box.clientWidth||520,L=36,T=4,cw=(W-L-4)/24,ch=26,H=T+7*ch+22;
  var svg=el('svg',{width:W,height:H,viewBox:'0 0 '+W+' '+H,role:'img','aria-label':'Average players by weekday and hour'},null);
  var days=['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
  function shade(v){if(v===null)return'#1a2030';var f=mx>0?v/mx:0;/* one hue, light to dark steps on the dark surface */var a=0.12+0.88*f;return'rgba(57,135,229,'+a.toFixed(3)+')'}
  var tp;
  for(i=0;i<7;i++){var tx=el('text',{x:L-6,y:T+i*ch+ch/2+4,'text-anchor':'end'},svg);tx.textContent=days[i];
    for(j=0;j<24;j++){(function(i,j){var r=el('rect',{x:L+j*cw+1,y:T+i*ch+1,width:cw-2,height:ch-2,rx:3,fill:shade(avg[i][j])},svg);
      r.addEventListener('mousemove',function(ev){var b=box.getBoundingClientRect();tp.innerHTML='<b>'+days[i]+' '+('0'+j).slice(-2)+':00</b><br>'+(avg[i][j]===null?'<span class="r">no data</span>':'<span class="r">avg</span> '+(Math.round(avg[i][j]*10)/10)+' players');place(tp,box,ev.clientX-b.left,ev.clientY-b.top)});
      r.addEventListener('mouseleave',function(){tp.style.display='none'})})(i,j)}}
  for(j=0;j<24;j+=3){var hx=el('text',{x:L+j*cw+cw/2,y:H-6,'text-anchor':'middle'},svg);hx.textContent=('0'+j).slice(-2)}
  box.appendChild(svg);tp=tip(box)
}

/* ---- map share: horizontal bars (many categories, so bars not a pie) ---- */
function mapBars(box,lg,maps){
  box.innerHTML='';lg.innerHTML='';
  if(!maps.length){empty(box,'No map data yet.');return}
  var total=maps.reduce(function(a,m){return a+m.sec},0),show=maps.slice(0,7),rest=maps.slice(7),other=rest.reduce(function(a,m){return a+m.sec},0);
  var rows=show.map(function(m,i){return{name:m.map,sec:m.sec,c:COL[i]}});if(other>0)rows.push({name:'Other ('+rest.length+')',sec:other,c:OTHER});
  var W=box.clientWidth||420,rh=28,H=rows.length*rh+4,L=Math.min(130,W*.38),R=48;
  var svg=el('svg',{width:W,height:H,viewBox:'0 0 '+W+' '+H,role:'img','aria-label':'Share of time by map'},null);
  var mx=Math.max.apply(null,rows.map(function(r){return r.sec}));box.appendChild(svg);var tp=tip(box);
  rows.forEach(function(r,i){var y=i*rh+2,w=Math.max(3,(W-L-R)*r.sec/mx);
    var t=el('text',{x:L-8,y:y+rh/2+1,'text-anchor':'end'},svg);t.textContent=r.name.length>18?r.name.slice(0,17)+'…':r.name;t.style.fill='#c3cad6';
    var b=el('rect',{x:L,y:y+4,width:w,height:rh-10,rx:4,fill:r.c},svg);
    var p=el('text',{x:L+w+6,y:y+rh/2+1},svg);p.textContent=(100*r.sec/total).toFixed(r.sec/total<.1?1:0)+'%';
    b.addEventListener('mousemove',function(ev){var bb=box.getBoundingClientRect();tp.innerHTML='<b>'+esc(r.name)+'</b><br><span class="r">'+dur(r.sec)+' · '+(100*r.sec/total).toFixed(1)+'%</span>';place(tp,box,ev.clientX-bb.left,ev.clientY-bb.top)});
    b.addEventListener('mouseleave',function(){tp.style.display='none'})})
}

/* ---- rotation timeline: one coloured block per run of a map ---- */
function rotation(box,list,segs,maps,from,to){
  box.innerHTML='';list.innerHTML='';
  if(!segs.length){empty(box,'No map data yet.');return}
  var colors=mapColors(maps),W=box.clientWidth||620,H=64,L=4,R=4,iw=W-L-R;from=Math.max(from,segs[0].start);
  var svg=el('svg',{width:W,height:H,viewBox:'0 0 '+W+' '+H,role:'img','aria-label':'Map rotation timeline'},null);
  function X(t){return L+(Math.max(from,Math.min(to,t))-from)/(to-from)*iw}
  el('rect',{x:L,y:12,width:iw,height:30,rx:4,fill:'#1a2030'},svg);
  box.appendChild(svg);var tp=tip(box);
  segs.forEach(function(s){if(s.end<from)return;var x0=X(s.start),x1=X(s.end),w=Math.max(2,x1-x0-2);
    var r=el('rect',{x:x0,y:12,width:w,height:30,rx:3,fill:colors[s.map]||OTHER},svg);
    r.addEventListener('mousemove',function(ev){var bb=box.getBoundingClientRect();tp.innerHTML='<b>'+esc(s.map)+'</b><br><span class="r">'+fmtT(s.start,true)+' → '+fmtT(s.end,true)+'</span><br><span class="r">'+dur(s.end-s.start)+' · avg '+s.avg+' · peak '+s.peak+'</span>';place(tp,box,ev.clientX-bb.left,ev.clientY-bb.top)});
    r.addEventListener('mouseleave',function(){tp.style.display='none'})});
  var span=to-from,step=span<=90000?3600*4:span<=700000?86400:86400*5;
  for(var t=Math.ceil(from/step)*step;t<=to;t+=step){var tx=el('text',{x:X(t),y:H-8,'text-anchor':'middle'},svg),d=new Date(t*1000);tx.textContent=span<=90000?fmtT(t):d.toLocaleDateString(undefined,{month:'short',day:'numeric'})}
  var last=segs.slice(-6).reverse(),h='<table class="t" style="margin-top:8px"><tr><th>Latest maps</th><th>Started</th><th class="n">Ran for</th><th class="n">Avg players</th></tr>';
  last.forEach(function(s){h+='<tr><td><span class="dot" style="background:'+(colors[s.map]||OTHER)+'"></span>'+esc(s.map)+'</td><td>'+fmtT(s.start,true)+'</td><td class="n">'+dur(s.end-s.start)+'</td><td class="n">'+s.avg+'</td></tr>'});
  list.innerHTML=h+'</table>'
}

function mapTable(maps){var b=$('map-table');if(!b)return;if(!maps.length){empty(b,'No map data yet.');return}
  var total=maps.reduce(function(a,m){return a+m.sec},0),colors=mapColors(maps),h='<table class="t"><tr><th>Map</th><th class="n">Times played</th><th class="n">Total time</th><th class="n">Share</th><th class="n">Avg players</th><th class="n">Peak</th></tr>';
  maps.forEach(function(m){h+='<tr><td><span class="dot" style="background:'+(colors[m.map]||OTHER)+'"></span>'+esc(m.map)+'</td><td class="n">'+m.plays+'</td><td class="n">'+dur(m.sec)+'</td><td class="n">'+(100*m.sec/total).toFixed(1)+'%</td><td class="n">'+m.avg+'</td><td class="n">'+m.peak+'</td></tr>'});
  b.innerHTML=h+'</table>'}

function tiles(s,d){
  function T(k,v,x){return'<div class="tile"><div class="k">'+k+'</div><div class="v">'+v+'</div>'+(x?'<div class="d">'+x+'</div>':'')+'</div>'}
  var h='';
  h+=T('Peak players',s.peak+(s.slots?' <small>/ '+s.slots+'</small>':''),s.peak_at?'at '+fmtT(s.peak_at,true):'');
  h+=T('Average players',s.avg,'while the server was online');
  h+=T('Uptime',s.uptime===null?'–':s.uptime+'<small>%</small>',d.samples+' samples');
  if(HASMAPS)h+=T('Maps played',s.unique_maps,s.map_changes+' map changes');
  if(s.cpu_avg!==null)h+=T('Avg CPU',s.cpu_avg+'<small>%</small>',s.mem_avg!==null?s.mem_avg+' MB RAM':'');
  $('tiles').innerHTML=h
}

function draw(d){
  if(!d.ok){['tiles'].forEach(function(i){$(i).innerHTML=''});var m=d.error||'No data';document.querySelectorAll('.chart').forEach(function(b){empty(b,m)});return}
  tiles(d.summary,d);
  var pts=d.series.map(function(p){return{t:p.t,y:p.avg,y2:p.max}});
  lineChart($('ch-players'),pts,{from:d.from,to:d.to,bucket:d.bucket,c1:'#3987e5',c2:'#9085e9',max2:true,n1:'Average',n2:'Busiest',tfmt:function(v){return(Math.round(v*10)/10)+' players'},label:'Players online over time',empty:d.samples?'':'No data yet. Samples are collected every minute or two once the server is running.'});
  $('lg-players').innerHTML='<span><i style="background:#3987e5"></i>Average players</span><span><i style="background:#9085e9"></i>Busiest moment (dashed)</span>';
  heat($('ch-heat'),d.hourly);
  if(HASMAPS){mapBars($('ch-maps'),$('lg-maps'),d.maps);rotation($('ch-rot'),$('rot-list'),d.segments,d.maps,d.from,d.to);mapTable(d.maps)}
  var cp=d.series.filter(function(p){return p.cpu!==null}).map(function(p){return{t:p.t,y:p.cpu}});
  lineChart($('ch-cpu'),cp,{h:190,from:d.from,to:d.to,bucket:d.bucket,c1:'#199e70',n1:'CPU',tfmt:function(v){return v+'%'},yfmt:function(v){return Math.round(v)+'%'},label:'CPU usage',empty:'No CPU data yet.'});
  var mp=d.series.filter(function(p){return p.mem!==null}).map(function(p){return{t:p.t,y:p.mem}});
  lineChart($('ch-mem'),mp,{h:190,from:d.from,to:d.to,bucket:d.bucket,c1:'#d95926',n1:'Memory',tfmt:function(v){return v+' MB'},yfmt:function(v){return Math.round(v)},label:'Memory use',empty:'No memory data yet.'})
}

var last=null;
function load(){fetch('server_stats.php?id='+SID+'&range='+RANGE+'&ajax=data',{credentials:'same-origin'}).then(function(r){return r.json()}).then(function(d){last=d;draw(d)}).catch(function(){draw({ok:false,error:'Could not load statistics.'})})}
function liveLoad(){fetch('server_stats.php?id='+SID+'&ajax=live',{credentials:'same-origin'}).then(function(r){return r.json()}).then(function(d){
  var box=$('live'),hint=$('liveHint');
  if(!d.ok){hint.innerHTML='<span class="live"><span class="pulse off"></span>'+esc(d.error||'Unavailable')+'</span>';box.innerHTML='';return}
  var q=d.live;
  if(!q.running){hint.innerHTML='<span class="live"><span class="pulse off"></span>Server is stopped</span>';box.innerHTML='';return}
  if(!q.online){hint.innerHTML='<span class="live"><span class="pulse off"></span>Running, not answering queries yet (starting up?)</span>';box.innerHTML='';return}
  hint.innerHTML='<span class="live"><span class="pulse"></span><b style="color:#fff">'+q.players+' / '+q.max+'</b> players'+(q.map?' on <b style="color:#fff">'+esc(q.map)+'</b>':'')+(q.version?' · '+esc(q.version):'')+'</span>';
  var pl=q.player_list||[],h='';
  if(!pl.length){h='<div class="empty">'+(q.players?'Player names are not available for this server.':'Nobody is playing right now.')+'</div>'}
  else{h='<table class="t"><tr><th>Player</th>'+(pl[0].score!==null?'<th class="n">Score</th><th class="n">Time</th>':'')+'</tr>';pl.forEach(function(p){h+='<tr><td>'+esc(p.name||'(connecting)')+'</td>'+(p.score!==null?'<td class="n">'+p.score+'</td><td class="n">'+dur(p.time)+'</td>':'')+'</tr>'});h+='</table>'}
  box.innerHTML=h}).catch(function(){})}
load();liveLoad();setInterval(liveLoad,10000);setInterval(load,60000);
var rt;window.addEventListener('resize',function(){clearTimeout(rt);rt=setTimeout(function(){if(last)draw(last)},150)})
})();
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/legal_lib.php'; echo swiftFooterHtml(); ?>
</body>
</html>
