<?php
require_once __DIR__ . '/../config/auth.php';
require_login();

$DATA_DIR = realpath(__DIR__ . '/../data');

// Lista arquivos
$files = [];
foreach (scandir($DATA_DIR) as $f) {
    if ($f === '.' || $f === '..') continue;
    $path = $DATA_DIR . '/' . $f;
    if (!is_file($path)) continue;
    $ext  = strtolower(pathinfo($f, PATHINFO_EXTENSION));
    $files[] = [
        'name'     => $f,
        'size'     => filesize($path),
        'mtime'    => filemtime($path),
        'editable' => in_array($ext, ['html', 'htm', 'js', 'css', 'json', 'txt']),
        'ext'      => $ext,
    ];
}
usort($files, fn($a, $b) => strcmp($a['name'], $b['name']));

function fmt_size(int $bytes): string {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)    return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

$page_title = 'Arquivos Data';
$breadcrumb = [['label' => 'Arquivos Data']];
include __DIR__ . '/../includes/header.php';
?>

<!-- CodeMirror -->
<link  rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.css">
<link  rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/theme/dracula.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/xml/xml.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/htmlmixed/htmlmixed.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/javascript/javascript.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/css/css.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/edit/matchbrackets.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/edit/closetag.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/search/searchcursor.min.js"></script>
<style>
    #file-editor-layout { display: flex; gap: 1rem; align-items: flex-start; }
    #file-panel { width: 260px; flex-shrink: 0; }
    #editor-panel { flex: 1; min-width: 0; }
    .CodeMirror { height: calc(100vh - 240px); min-height: 450px; font-size: 13px; border-radius: 0 0 8px 8px; }
    .file-item { cursor: pointer; transition: background 0.15s; }
    .file-item:hover { background: #f0f2f5; }
    .file-item.active { background: #e8f0fe; border-left: 3px solid #0f3460; }
    .file-icon-html  { color: #e44d26; }
    .file-icon-js    { color: #f7df1e; }
    .file-icon-css   { color: #264de4; }
    .file-icon-ico   { color: #888; }
    .file-icon-other { color: #6c757d; }
    #editor-toolbar { background: #2b2b3b; border-radius: 8px 8px 0 0; padding: 8px 12px; display: flex; align-items: center; gap: 8px; }
    #editor-filename { color: #a9b1d6; font-family: monospace; font-size: 13px; }
    .btn-toolbar-action { background: rgba(255,255,255,0.1); border: none; color: #cdd6f4; padding: 4px 12px; border-radius: 5px; font-size: 12px; cursor: pointer; }
    .btn-toolbar-action:hover { background: rgba(255,255,255,0.2); }
    .btn-toolbar-save { background: #1abc9c; color: #fff; }
    .btn-toolbar-save:hover { background: #16a085; }
</style>

<div id="file-editor-layout">
    <!-- Lista de arquivos -->
    <div id="file-panel">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h6 class="mb-0 fw-bold"><i class="bi bi-folder-fill me-2 text-warning"></i>data/</h6>
                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#uploadModal">
                    <i class="bi bi-upload me-1"></i>Upload
                </button>
            </div>
            <div class="list-group list-group-flush" id="file-list">
                <?php foreach ($files as $f): ?>
                <?php
                    $icon = match($f['ext']) {
                        'html', 'htm' => 'bi-filetype-html file-icon-html',
                        'js'          => 'bi-filetype-js file-icon-js',
                        'css'         => 'bi-filetype-css file-icon-css',
                        'ico'         => 'bi-image file-icon-ico',
                        'json'        => 'bi-filetype-json file-icon-other',
                        default       => 'bi-file-text file-icon-other',
                    };
                ?>
                <div class="list-group-item list-group-item-action file-item px-3 py-2"
                     data-name="<?= h($f['name']) ?>"
                     data-editable="<?= $f['editable'] ? '1' : '0' ?>"
                     onclick="selectFile('<?= h($f['name']) ?>', <?= $f['editable'] ? 'true' : 'false' ?>)">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi <?= $icon ?> fs-5 flex-shrink-0"></i>
                        <div class="min-w-0 flex-grow-1">
                            <div class="small fw-semibold text-truncate"><?= h($f['name']) ?></div>
                            <div class="text-muted" style="font-size:0.7rem;">
                                <?= fmt_size($f['size']) ?> · <?= date('d/m H:i', $f['mtime']) ?>
                            </div>
                        </div>
                        <?php if ($f['editable']): ?>
                        <button class="btn btn-sm p-0 text-danger opacity-50" style="line-height:1;"
                                onclick="event.stopPropagation(); deleteFile('<?= h($f['name']) ?>')"
                                title="Excluir">
                            <i class="bi bi-trash" style="font-size:0.8rem;"></i>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if (empty($files)): ?>
                <div class="list-group-item text-muted small text-center py-3">Nenhum arquivo</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Editor -->
    <div id="editor-panel">
        <div id="editor-placeholder" class="card align-items-center justify-content-center text-muted" style="display:flex; min-height:calc(100vh - 240px);">
            <div class="text-center">
                <i class="bi bi-file-code display-4 d-block mb-3 opacity-25"></i>
                <p class="mb-0">Selecione um arquivo para editar</p>
            </div>
        </div>
        <div id="editor-container" class="d-none">
            <div id="editor-toolbar">
                <i class="bi bi-file-code text-info me-1"></i>
                <span id="editor-filename">—</span>
                <span id="editor-modified" class="badge bg-warning text-dark ms-1 d-none" style="font-size:10px;">modificado</span>
                <div class="ms-auto d-flex gap-2">
                    <button class="btn-toolbar-action" onclick="formatCode()" title="Formatar (Ctrl+Shift+F)">
                        <i class="bi bi-magic me-1"></i>Formatar
                    </button>
                    <button class="btn-toolbar-action btn-toolbar-save" id="btn_save" onclick="saveFile()">
                        <i class="bi bi-floppy me-1"></i>Salvar
                    </button>
                </div>
            </div>
            <textarea id="code-editor"></textarea>
        </div>
        <div id="editor-binary" class="card d-none">
            <div class="card-body text-center py-5 text-muted">
                <i class="bi bi-file-binary display-4 d-block mb-3 opacity-25"></i>
                <p>Este arquivo não é editável como texto.</p>
                <a id="binary-download" href="#" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-download me-1"></i>Download
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Upload Modal -->
<div class="modal fade" id="uploadModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-upload me-2"></i>Upload de Arquivo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info small">
                    <i class="bi bi-info-circle me-1"></i>
                    Tipos permitidos: <code>.html, .htm, .ico, .css, .js, .json, .txt</code><br>
                    Se o arquivo já existir, será substituído.
                </div>
                <div id="upload-drop-zone" class="border border-2 border-dashed rounded p-5 text-center text-muted"
                     style="cursor:pointer; transition:background 0.2s;"
                     ondragover="event.preventDefault();this.style.background='#e8f0fe'"
                     ondragleave="this.style.background=''"
                     ondrop="handleDrop(event)">
                    <i class="bi bi-cloud-upload display-5 d-block mb-2 opacity-50"></i>
                    <p class="mb-2">Arraste arquivos aqui ou</p>
                    <label class="btn btn-sm btn-outline-primary">
                        Escolher arquivo
                        <input type="file" id="upload-input" multiple accept=".html,.htm,.ico,.css,.js,.json,.txt" style="display:none;" onchange="uploadFiles(this.files)">
                    </label>
                </div>
                <div id="upload-progress" class="mt-3" style="display:none;"></div>
            </div>
        </div>
    </div>
</div>

<script>
let editor = null;
let currentFile = null;
let originalContent = '';

function initEditor() {
    const ta = document.getElementById('code-editor');
    if (typeof CodeMirror === 'undefined' || !ta) {
        console.warn('CodeMirror não disponível');
        return;
    }
    editor = CodeMirror.fromTextArea(ta, {
        mode: 'htmlmixed',
        theme: 'dracula',
        lineNumbers: true,
        matchBrackets: true,
        autoCloseTags: true,
        lineWrapping: false,
        tabSize: 2,
        indentWithTabs: false,
        extraKeys: {
            'Ctrl-S': function() { saveFile(); },
            'Cmd-S':  function() { saveFile(); },
        }
    });
    editor.on('change', function() {
        var modified = editor.getValue() !== originalContent;
        if (modified) show('editor-modified'); else hide('editor-modified');
    });
}

function getEditorValue() {
    if (editor) return editor.getValue();
    return document.getElementById('code-editor').value;
}

function setEditorValue(content) {
    if (editor) {
        editor.setValue(content);
        editor.clearHistory();
        setTimeout(function() { editor.refresh(); }, 50);
    } else {
        document.getElementById('code-editor').value = content;
    }
}

function hide(id) { document.getElementById(id).classList.add('d-none'); }
function show(id) { document.getElementById(id).classList.remove('d-none'); }

function selectFile(name, editable) {
    document.querySelectorAll('.file-item').forEach(function(el) { el.classList.remove('active'); });
    document.querySelectorAll('.file-item').forEach(function(el) {
        if (el.getAttribute('data-name') === name) el.classList.add('active');
    });

    document.getElementById('editor-placeholder').style.display = 'none';
    hide('editor-binary');
    hide('editor-container');

    if (!editable) {
        show('editor-binary');
        document.getElementById('binary-download').href = '/data/' + encodeURIComponent(name);
        return;
    }

    show('editor-container');
    document.getElementById('editor-filename').textContent = name;
    hide('editor-modified');
    document.getElementById('btn_save').disabled = true;
    document.getElementById('btn_save').innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Carregando...';
    currentFile = name;

    if (editor) {
        var ext = name.split('.').pop().toLowerCase();
        var modeMap = { html: 'htmlmixed', htm: 'htmlmixed', js: 'javascript', css: 'css', json: 'application/json' };
        editor.setOption('mode', modeMap[ext] || 'text/plain');
    }

    fetch('/api/data_files.php?action=read&file=' + encodeURIComponent(name))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            document.getElementById('btn_save').disabled = false;
            document.getElementById('btn_save').innerHTML = '<i class="bi bi-floppy me-1"></i>Salvar';
            if (!data.ok) { alert('Erro ao carregar: ' + data.error); return; }
            originalContent = data.content;
            setEditorValue(data.content);
        })
        .catch(function(err) {
            document.getElementById('btn_save').disabled = false;
            document.getElementById('btn_save').innerHTML = '<i class="bi bi-floppy me-1"></i>Salvar';
            alert('Erro de comunicação: ' + err.message);
        });
}

function saveFile() {
    if (!currentFile) return;
    var btn = document.getElementById('btn_save');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Salvando...';
    var content = getEditorValue();

    fetch('/api/data_files.php?action=save', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ file: currentFile, content: content })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        btn.disabled = false;
        if (!data.ok) {
            btn.innerHTML = '<i class="bi bi-floppy me-1"></i>Salvar';
            alert('Erro ao salvar: ' + data.error);
            return;
        }
        originalContent = content;
        hide('editor-modified');
        btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Salvo!';
        btn.style.background = '#27ae60';
        setTimeout(function() {
            btn.innerHTML = '<i class="bi bi-floppy me-1"></i>Salvar';
            btn.style.background = '';
        }, 1500);
    })
    .catch(function(err) {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-floppy me-1"></i>Salvar';
        alert('Erro: ' + err.message);
    });
}

function formatCode() {
    var lines = getEditorValue().split('\n').map(function(l) { return l.trimEnd(); });
    setEditorValue(lines.join('\n'));
}

function deleteFile(name) {
    if (!confirm(`Excluir "${name}"?\n\nEsta ação não pode ser desfeita.`)) return;
    fetch('/api/data_files.php?action=delete', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ file: name })
    })
    .then(r => r.json())
    .then(data => {
        if (!data.ok) { alert('Erro: ' + data.error); return; }
        if (currentFile === name) {
            currentFile = null;
            document.getElementById('editor-placeholder').style.display = '';
            document.getElementById('editor-container').style.display = 'none';
        }
        location.reload();
    });
}

function handleDrop(e) {
    e.preventDefault();
    e.currentTarget.style.background = '';
    uploadFiles(e.dataTransfer.files);
}

function uploadFiles(files) {
    const progress = document.getElementById('upload-progress');
    progress.style.display = '';
    progress.innerHTML = '';
    let pending = files.length;

    Array.from(files).forEach(file => {
        const row = document.createElement('div');
        row.className = 'small d-flex align-items-center gap-2 mb-1';
        row.innerHTML = `<i class="bi bi-file-earmark me-1"></i><span class="flex-grow-1">${file.name}</span><span class="text-muted">enviando...</span>`;
        progress.appendChild(row);

        const fd = new FormData();
        fd.append('file', file);
        fetch('/api/data_files.php?action=upload', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                const status = row.querySelector('span:last-child');
                if (data.ok) {
                    status.className = 'text-success fw-semibold';
                    status.textContent = 'ok';
                } else {
                    status.className = 'text-danger';
                    status.textContent = data.error;
                }
                pending--;
                if (pending === 0) setTimeout(() => location.reload(), 800);
            });
    });
}

// Ctrl+S global
document.addEventListener('keydown', function(e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        e.preventDefault();
        saveFile();
    }
});

// Inicializa editor após todos os scripts carregarem
window.addEventListener('load', function() {
    initEditor();
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
