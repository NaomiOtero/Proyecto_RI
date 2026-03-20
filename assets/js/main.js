
let contadorInterval = null;
let segundosVisto    = 0;
let peliculaActualId = null;
let yaRegistro       = false;

// ── ENTRADA DESDE LOS BOTONES DEL GRID ───────────────────────────────────────

function verPeliculaBtn(btn) {
    verPelicula(btn.dataset.id, btn.dataset.url);
}

// ── ABRIR MODAL DE VIDEO ──────────────────────────────────────────────────────

function verPelicula(id, youtubeUrl) {
    peliculaActualId = id;
    segundosVisto    = 0;
    yaRegistro       = false;

    // Convertir URL normal a embed
    const matchV  = youtubeUrl.match(/[?&]v=([^&]+)/);
    const matchBe = youtubeUrl.match(/youtu\.be\/([^?]+)/);
    const videoId = matchV ? matchV[1] : (matchBe ? matchBe[1] : null);
    const embedUrl = videoId
        ? `https://www.youtube.com/embed/${videoId}?autoplay=1`
        : youtubeUrl;

    // Resetear UI
    document.getElementById('ytFrame').src = embedUrl;
    document.getElementById('contadorDisplay').textContent = '0s';
    document.getElementById('gustoLabel').classList.add('hidden');
    document.getElementById('recomendaciones').classList.add('hidden');
    document.getElementById('recGrid').innerHTML = '';
    document.getElementById('modalVideo').classList.remove('hidden');

    // Iniciar contador
    clearInterval(contadorInterval);
    contadorInterval = setInterval(() => {
        segundosVisto++;
        document.getElementById('contadorDisplay').textContent = segundosVisto + 's';

        // Registrar automáticamente al llegar a 15 segundos
        if (segundosVisto === 15 && !yaRegistro) {
            yaRegistro = true;
            enviarTiempo();
        }
    }, 1000);
}

// ── CERRAR MODAL DE VIDEO ─────────────────────────────────────────────────────

function cerrarVideo() {
    clearInterval(contadorInterval);
    document.getElementById('ytFrame').src = '';
    document.getElementById('modalVideo').classList.add('hidden');

    // Registrar si aún no se hizo (menos de 15 s)
    if (!yaRegistro && peliculaActualId) {
        enviarTiempo();
    }
}

// ── AJAX: REGISTRAR TIEMPO Y MOSTRAR RECOMENDACIONES ─────────────────────────

function enviarTiempo() {
    const fd = new FormData();
    fd.append('accion',      'registrar_tiempo');
    fd.append('id_pelicula', peliculaActualId);
    fd.append('segundos',    segundosVisto);

    fetch('index.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => mostrarResultadoTiempo(data))
        .catch(() => {});   // silencioso en caso de error de red
}

function mostrarResultadoTiempo(data) {
    const label = document.getElementById('gustoLabel');

    if (data.gusto) {
        label.textContent = '¡Le gustó!';
        label.className   = 'ml-auto text-sm font-semibold text-green-400';
    } else {
        label.textContent = '👎 No le gustó';
        label.className   = 'ml-auto text-sm font-semibold text-red-400';
    }
    label.classList.remove('hidden');

    if (data.recomendaciones && data.recomendaciones.length > 0) {
        renderizarRecomendaciones(data.recomendaciones);
    }
}

function renderizarRecomendaciones(lista) {
    const grid = document.getElementById('recGrid');
    grid.innerHTML = '';

    lista.forEach(p => {
        const div = document.createElement('div');
        div.className = 'bg-gray-800 rounded-lg overflow-hidden text-white text-center text-xs cursor-pointer hover:ring-2 hover:ring-red-500';
        div.innerHTML = `<img src="${p.img}" class="w-full h-28 object-cover"><p class="p-1">${p.nombre}</p>`;
        div.addEventListener('click', () => verPelicula(p.id, p.youtube_url || ''));
        grid.appendChild(div);
    });

    document.getElementById('recomendaciones').classList.remove('hidden');
}