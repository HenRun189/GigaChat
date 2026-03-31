<?php
session_start();

if(!isset($_SESSION["user_id"])){
    header("Location: ../login.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<title>GigaChat r/place</title>
<style>
:root{
    --bg:#050509;
    --panel:#14141b;
    --panel-soft:rgba(12,12,18,0.9);
    --accent:#4f46e5;
    --accent-soft:rgba(79,70,229,0.25);
    --border:#27272f;
    --text:#f9fafb;
    --muted:#9ca3af;
    --danger:#f97373;
}

*{
    box-sizing:border-box;
}

body{
    margin:0;
    background:#020617;
    font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
    color:#f9fafb;
    height:100vh;
    overflow:hidden;
    display:flex;
    flex-direction:column;
}
/* Top Bar */
.header{
    height:56px;
    padding:0 20px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    background:rgba(3,7,18,0.9);
    border-bottom:1px solid var(--border);
    backdrop-filter:blur(18px);
}

.header-left{
    display:flex;
    align-items:center;
    gap:10px;
}

.logo-dot{
    width:26px;
    height:26px;
    border-radius:8px;
    background:conic-gradient(from 180deg,#6366f1,#22c55e,#eab308,#6366f1);
    box-shadow:0 0 18px rgba(99,102,241,0.75);
}

.header-title{
    font-weight:600;
    letter-spacing:0.03em;
    font-size:16px;
}

.header-sub{
    font-size:12px;
    color:var(--muted);
}

/* main layout */
.main{
    flex:1;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:16px;
}

.card{
    width:100%;
    height:100%;
    border-radius:0;
    background:radial-gradient(circle at top left,rgba(79,70,229,0.3),transparent 46%),
               radial-gradient(circle at bottom right,rgba(16,185,129,0.2),transparent 50%),
               rgba(15,23,42,0.92);
    border-radius:26px;
    border:1px solid rgba(148,163,184,0.35);
    box-shadow:0 32px 80px rgba(15,23,42,0.85);
    padding:18px 18px 14px;
    display:flex;
    flex-direction:column;
    position:relative;
    overflow:hidden;
}

/* Header Layout */

.card-header{
display:flex;
align-items:center;
justify-content:space-between;
position:relative;
}

.card-header-center{
position:absolute;
left:50%;
transform:translateX(-50%);
}


.card-title{
    font-size:18px;
    font-weight:600;
    display:flex;
    align-items:center;
    gap:8px;
}

.card-title-pill{
    font-size:11px;
    padding:2px 8px;
    border-radius:999px;
    background:var(--accent-soft);
    color:#c7d2ff;
    border:1px solid rgba(129,140,248,0.55);
}

.card-sub{
    font-size:12px;
    color:var(--muted);
}

.card-header-right{
    display:flex;
    align-items:center;
    gap:10px;
}

/* Pixel Counter */

#stats{
padding:6px 14px;
border-radius:999px;
background:rgba(15,23,42,0.9);
border:1px solid rgba(55,65,81,0.9);
font-size:13px;
display:flex;
gap:6px;
align-items:center;
}


#stats span.label{
    color:var(--muted);
}

#stats span.value{
    font-variant-numeric:tabular-nums;
    font-weight:600;
}

/* cooldown badge */
#cooldown{
position:fixed;
bottom:85px;
left:50%;
transform:translateX(-50%);
padding:6px 14px;

background:rgba(15,23,42,0.85);
border:1px solid rgba(55,65,81,0.7);
border-radius:999px;

font-size:12px;
color:#f97373;

opacity:0.9;
pointer-events:none;
backdrop-filter:blur(8px);
z-index:60;
}

/* canvas wrapper */
.canvas-shell{
    flex:1;
    display:flex;
    justify-content:center;
    align-items:center;
    margin-top:10px;
    margin-bottom:12px;
}

.canvas-inner{
    position:relative;
    background:radial-gradient(circle at top,#020617,#020617 38%,#000 100%);
    padding:12px;
    border-radius:18px;
    border:1px solid rgba(30,64,175,0.8);
    box-shadow:0 18px 45px rgba(15,23,42,0.8);
}

#canvasWrapper{
    flex:1;
    display:flex;
    align-items:center;
    justify-content:center;
    background:radial-gradient(circle at top,#111827 0,#020617 55%,#000 100%);
}


canvas{
    width:95vw;
    height:85vh;
    image-rendering:pixelated;
    cursor:pointer;
    border-radius:18px;
    background:#020617;
    border:1px solid rgba(148,163,184,0.4);
}

/* bottom tools */
.tools-row{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
}

/* color picker */
#colorPicker{
    position:fixed;
    bottom:20px;
    left:50%;
    transform:translateX(-50%);
    display:flex;
    gap:8px;
    padding:8px 14px;
    background:rgba(15,23,42,0.85);
    border-radius:999px;
    border:1px solid rgba(55,65,81,0.9);
    backdrop-filter:blur(20px);
    z-index:50;
}

.color{
    width:30px;
    height:30px;
    border-radius:999px;
    cursor:pointer;
    border:2px solid transparent;
    box-shadow:0 0 0 1px rgba(15,23,42,0.9);
    transition:transform 0.12s ease, box-shadow 0.12s ease, border-color 0.12s ease;
}

.color:hover{
    transform:translateY(-1px) scale(1.04);
    box-shadow:0 0 0 1px rgba(249,250,251,0.3);
}

.color.active{
    border-color:#f9fafb;
    box-shadow:0 0 0 2px rgba(249,250,251,0.4);
}

/* footer hint */
.footer-hint{
    font-size:11px;
    color:var(--muted);
    text-align:right;
    margin-top:4px;
}

/* responsive */
@media (max-width:768px){
    .card{
        border-radius:20px;
        padding:14px 12px 12px;
    }
    canvas{
        width:80vmin;
        height:80vmin;
    }
    .tools-row{
        flex-direction:column;
        align-items:flex-start;
    }
    #stats{
        order:2;
    }
    #cooldown{
        order:3;
        text-align:left;
    }
    #colorPicker{
        order:1;
        justify-content:flex-start;
        overflow-x:auto;
    }
}

/* Pixel Koordinaten */

#pixelInfo{
position:fixed;
top:90px;
right:20px;

background:rgba(15,23,42,0.85);
padding:6px 10px;

border-radius:8px;
font-size:12px;

border:1px solid #334155;
backdrop-filter:blur(8px);

z-index:80;
}


/* Leaderboard */

#leaderboard{
position:fixed;
right:20px;
top:50%;
transform:translateY(-50%);

background:rgba(15,23,42,0.9);
padding:12px;

border-radius:12px;
width:180px;

font-size:13px;
border:1px solid #334155;

backdrop-filter:blur(10px);
z-index:80;
}


#cooldown{
animation:fadein .2s ease;
}

@keyframes fadein{
from{opacity:0; transform:translate(-50%,10px);}
to{opacity:1; transform:translate(-50%,0);}
}

/* Cooldown Ready Effekt */
#readyFlash{
position:fixed;
top:0;
left:0;
width:100%;
height:100%;
pointer-events:none;
z-index:999;

box-shadow:
inset 0 0 0px rgba(34,197,94,0);

transition:box-shadow .8s ease;
}

#readyFlash.active{
box-shadow:
inset 0 0 220px rgba(34,197,94,0.35),
inset 0 0 420px rgba(34,197,94,0.18),
0 0 160px rgba(34,197,94,0.35);
}

.floating-button{
position:fixed;

bottom:20px;
left:20px;

width:52px;
height:52px;

border-radius:50%;

background:rgba(15,23,42,0.9);
border:1px solid rgba(55,65,81,0.9);

display:flex;
align-items:center;
justify-content:center;

font-size:20px;
color:white;
text-decoration:none;

backdrop-filter:blur(10px);

z-index:999;

transition:all .2s ease;
}

.floating-button:hover{
transform:scale(1.08);
background:rgba(30,41,59,0.95);
}
</style>
</head>
<div id="readyFlash"></div>

<div id="pixelInfo">x:0 y:0</div>
<div id="leaderboard"></div>

<a href="/index.php" class="floating-button">
🏠
</a>

<div class="main">
    <div class="card">

        <div class="card-header">

            <div class="card-header-left">
                <div class="card-title">
                    GigaChat r/place
                    <span class="card-title-pill">200 × 200</span>
                </div>
                <div class="card-sub">
                    Setze Pixel und male gemeinsam.
                </div>
            </div>

            <div class="card-header-center">
                <div id="stats">
                    <span class="value" id="pixelCount">0</span>
                    <span class="label">Pixel</span>
                </div>
            </div>

            <div id="cooldown"></div>

        </div>

        <div class="canvas-shell">
            <div class="canvas-inner">
                <canvas id="place" width="200" height="200"></canvas>
            </div>
        </div>

        <div class="tools-row">

            <div id="colorPicker">
                <div class="color active" data-color="#ffffff" style="background:#ffffff"></div>
                <div class="color" data-color="#ff0000" style="background:#ff0000"></div>
                <div class="color" data-color="#00ff00" style="background:#00ff00"></div>
                <div class="color" data-color="#0000ff" style="background:#0000ff"></div>
                <div class="color" data-color="#000000" style="background:#000000"></div>
                <div class="color" data-color="#ffff00" style="background:#ffff00"></div>
            </div>

            <div class="footer-hint">
                Scroll = Zoom · Drag = Bewegen
            </div>

        </div>

    </div>
</div>
<script>
const canvas = document.getElementById("place");
const ctx = canvas.getContext("2d");

let cooldownTimer = 0
let hoverX = -1
let hoverY = -1

canvas.addEventListener("mousemove", e=>{

    const rect = canvas.getBoundingClientRect()

    const mouseX = e.clientX - rect.left
    const mouseY = e.clientY - rect.top

    const worldX = (mouseX - rect.width/2 - offsetX) / scale
    const worldY = (mouseY - rect.height/2 - offsetY) / scale

    hoverX = Math.floor(worldX + LOGICAL_SIZE/2)
    hoverY = Math.floor(worldY + LOGICAL_SIZE/2)

    drawCanvas()

})

const LOGICAL_SIZE = 200;       // 200x200 Pixel auf dem Server
let selectedColor = "#ffffff";

let scale = 12;                  // wie groß 1 Pixel dargestellt wird (4 = 4x4)
let offsetX = 0;
let offsetY = 0;

// Farben
document.querySelectorAll("#colorPicker .color").forEach(c=>{
    c.onclick=()=>{
        document.querySelectorAll("#colorPicker .color").forEach(x=>x.classList.remove("active"));
        c.classList.add("active");
        selectedColor = c.dataset.color;
    };
});

// Helper: Canvas vorbereiten (für scharfe Pixel)
function resizeCanvasForHiDPI() {

    const rect = canvas.getBoundingClientRect();
    const dpr = window.devicePixelRatio || 1;

    canvas.width = rect.width * dpr;
    canvas.height = rect.height * dpr;

    ctx.setTransform(dpr,0,0,dpr,0,0);

}
resizeCanvasForHiDPI();
window.addEventListener("resize", resizeCanvasForHiDPI);

// aktueller Pixel-Array
let currentPixels = new Array(LOGICAL_SIZE*LOGICAL_SIZE).fill("#ffffff");

// Canvas zeichnen (Pixel + Grid)
function drawCanvas() {
    const rect = canvas.getBoundingClientRect();
    const size = Math.min(rect.width, rect.height);

    const pixelSize = scale; // Skala in Screen-Pixeln
    ctx.clearRect(0,0,canvas.width,canvas.height);

    ctx.save();
    // Koordinatensystem: wir skalieren von logischen 200x200 auf Anzeige
    ctx.translate(rect.width/2 + offsetX, rect.height/2 + offsetY);
    ctx.scale(pixelSize, pixelSize);
    ctx.translate(-LOGICAL_SIZE/2, -LOGICAL_SIZE/2);

    // Pixel zeichnen
    for(let i=0;i<currentPixels.length;i++){
        const x = i % LOGICAL_SIZE;
        const y = Math.floor(i/LOGICAL_SIZE);
        ctx.fillStyle = currentPixels[i];
        ctx.fillRect(x+0.01, y+0.01, 0.98, 0.98);
    }

    // Grid nur anzeigen wenn weit genug reingezoomt
    if(scale >= 10){
    
        ctx.lineWidth = 0.03;
        ctx.strokeStyle = "rgba(15,23,42,0.6)";
    
        ctx.beginPath();
    
        for(let x=0; x<=LOGICAL_SIZE; x++){
            ctx.moveTo(x,0);
            ctx.lineTo(x,LOGICAL_SIZE);
        }
    
        for(let y=0; y<=LOGICAL_SIZE; y++){
            ctx.moveTo(0,y);
            ctx.lineTo(LOGICAL_SIZE,y);
        }
    
        ctx.stroke();
    
    }

    if(hoverX>=0 && hoverY>=0 && hoverX<LOGICAL_SIZE && hoverY<LOGICAL_SIZE){

        ctx.strokeStyle = "#ffffff"
        ctx.lineWidth = 0.15

        ctx.strokeRect(hoverX,hoverY,1,1)
        document.getElementById("pixelInfo").innerText =
        "x:"+hoverX+" y:"+hoverY
    }

    ctx.restore();
}

// Canvas-Daten vom Server laden
function loadCanvas(){
    fetch("get_canvas.php")
        .then(r=>r.json())
        .then(data=>{
            if(data && Array.isArray(data.pixels)){
                currentPixels = data.pixels;
                drawCanvas();
            }
        })
        .catch(console.error);
}

// Klick → logischen Pixel berechnen
canvas.addEventListener("click", e => {

    if(movedWhileDragging) return;

    const rect = canvas.getBoundingClientRect()

    const mouseX = e.clientX - rect.left
    const mouseY = e.clientY - rect.top

    const worldX = (mouseX - rect.width/2 - offsetX) / scale
    const worldY = (mouseY - rect.height/2 - offsetY) / scale

    const logicalX = Math.floor(worldX + LOGICAL_SIZE/2)
    const logicalY = Math.floor(worldY + LOGICAL_SIZE/2)

    console.log("pixel", logicalX, logicalY)

    if (logicalX < 0 || logicalX >= LOGICAL_SIZE || logicalY < 0 || logicalY >= LOGICAL_SIZE) {
        return
    }

    let form = new FormData()
    form.append("x", logicalX)
    form.append("y", logicalY)
    form.append("color", selectedColor)

    fetch("set_pixel.php",{
        method:"POST",
        body: form
    })
    .then(r => r.json())
    .then(res => {

        console.log(res)

    if(res.error){
    
        if(res.error === "cooldown"){
    
            cooldownTimer = res.seconds
            const cd = document.getElementById("cooldown")
    
            cd.innerText = "Cooldown: "+cooldownTimer+"s"
    
            const interval = setInterval(()=>{
    
                cooldownTimer--
    
                if(cooldownTimer <= 0){
                    cd.innerText=""
                    clearInterval(interval)

                    // READY Effekt
                    const flash = document.getElementById("readyFlash")
                    flash.classList.add("active")

                    setTimeout(()=>{
                    flash.classList.remove("active")
                    },1200)
                }else{
                    cd.innerText="Cooldown: "+cooldownTimer+"s"
                }
    
            },1000)
    
        }else{
            document.getElementById("cooldown").innerText = res.error
        }
    
    }

    })
    .catch(console.error)

})

// Zoom mit Mausrad
canvas.addEventListener("wheel", e=>{

    e.preventDefault()

    const zoomSpeed = 0.1

    const rect = canvas.getBoundingClientRect()

    const mouseX = e.clientX - rect.left
    const mouseY = e.clientY - rect.top

    const worldX = (mouseX - rect.width/2 - offsetX) / scale
    const worldY = (mouseY - rect.height/2 - offsetY) / scale

    const zoom = Math.exp(-e.deltaY * zoomSpeed * 0.01)

    scale *= zoom

    scale = Math.max(3,Math.min(60,scale))

    offsetX -= worldX*(zoom-1)*scale
    offsetY -= worldY*(zoom-1)*scale

    drawCanvas()

},{passive:false})

// Pan mit gedrückter Maus
let isPanning = false;
let movedWhileDragging = false;
let lastX = 0, lastY = 0;

canvas.addEventListener("mousedown", e=>{
    isPanning = true;
    movedWhileDragging = false;
    lastX = e.clientX;
    lastY = e.clientY;
});

window.addEventListener("mouseup", ()=>{ isPanning = false; });

window.addEventListener("mousemove", e=>{
    if(!isPanning) return;
    movedWhileDragging = true;
    const dx = e.clientX - lastX;
    const dy = e.clientY - lastY;
    lastX = e.clientX;
    lastY = e.clientY;
    offsetX += dx;
    offsetY += dy;
    drawCanvas();
});

// Stats
function loadStats(){
    fetch("get_stats.php")
        .then(r=>r.json())
        .then(data=>{
            if(data && typeof data.pixels_set !== "undefined"){
                document.getElementById("pixelCount").innerText=data.pixels_set;
            }
        })
        .catch(console.error);
}

setInterval(loadStats,5000);
let lastUpdate = 0;

function loadUpdates(){

    fetch("get_updates.php")
    .then(r=>r.json())
    .then(data=>{

        data.forEach(p=>{

            const index = p.y * LOGICAL_SIZE + p.x

            if(currentPixels[index] !== p.color){

                currentPixels[index] = p.color

                ctx.fillStyle = p.color
                ctx.fillRect(p.x, p.y, 1, 1)

            }

        })

        drawCanvas()

    })

}

function loadLeaderboard(){

fetch("get_leaderboard.php")
.then(r=>r.json())
.then(data=>{

let html="<b>Top Pixel Artists</b><br><br>"

data.forEach((u,i)=>{
html+=(i+1)+". "+u.username+" — "+u.pixels_set+"<br>"
})

document.getElementById("leaderboard").innerHTML=html

})
}
 
setInterval(loadLeaderboard,5000)

loadLeaderboard()

setInterval(loadUpdates,1000)

loadStats();
loadCanvas();

</script>
</body>
</html>
