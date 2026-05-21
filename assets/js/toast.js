
(function (global) {
    'use strict';

    const STYLE = `
        #toast-container {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 99999;
            display: flex;
            flex-direction: column;
            gap: 10px;
            pointer-events: none;
        }
        .vd-toast {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 14px 18px;
            border-radius: 14px;
            min-width: 280px;
            max-width: 380px;
            box-shadow: 0 8px 32px rgba(0,0,0,.18);
            pointer-events: all;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            backdrop-filter: blur(8px);
            animation: toastIn .3s cubic-bezier(.34,1.56,.64,1) forwards;
            font-family: 'DM Sans', 'Plus Jakarta Sans', 'Lato', system-ui, sans-serif;
        }
        .vd-toast.dismissing {
            animation: toastOut .25s ease forwards;
        }
        @keyframes toastIn {
            from { opacity:0; transform: translateX(40px) scale(.92); }
            to   { opacity:1; transform: none; }
        }
        @keyframes toastOut {
            from { opacity:1; transform: none; max-height: 120px; margin-bottom: 0; }
            to   { opacity:0; transform: translateX(40px) scale(.92); max-height: 0; margin-bottom: -10px; padding: 0; }
        }
        .vd-toast-progress {
            position: absolute;
            bottom: 0; left: 0;
            height: 3px;
            border-radius: 0 0 14px 14px;
            animation: toastProgress linear forwards;
        }
        @keyframes toastProgress {
            from { width: 100%; }
            to   { width: 0%; }
        }
        .vd-toast-icon {
            font-size: 18px;
            line-height: 1;
            flex-shrink: 0;
            margin-top: 1px;
        }
        .vd-toast-body { flex: 1; min-width: 0; }
        .vd-toast-title {
            font-size: 13px;
            font-weight: 700;
            line-height: 1.3;
            margin-bottom: 2px;
        }
        .vd-toast-msg {
            font-size: 12px;
            line-height: 1.5;
            opacity: .82;
        }
        .vd-toast-close {
            background: none;
            border: none;
            font-size: 16px;
            cursor: pointer;
            opacity: .5;
            padding: 0;
            line-height: 1;
            flex-shrink: 0;
            margin-top: -1px;
            transition: opacity .15s;
        }
        .vd-toast-close:hover { opacity: 1; }

        /* Variants */
        .vd-toast-success  { background: #f0fdf4; color: #14532d; border: 1px solid #bbf7d0; }
        .vd-toast-success  .vd-toast-progress { background: #22c55e; }
        .vd-toast-error    { background: #fef2f2; color: #7f1d1d; border: 1px solid #fecaca; }
        .vd-toast-error    .vd-toast-progress { background: #ef4444; }
        .vd-toast-warning  { background: #fffbeb; color: #78350f; border: 1px solid #fde68a; }
        .vd-toast-warning  .vd-toast-progress { background: #f59e0b; }
        .vd-toast-info     { background: #f0f9ff; color: #0c4a6e; border: 1px solid #bae6fd; }
        .vd-toast-info     .vd-toast-progress { background: #0ea5e9; }
        .vd-toast-loading  { background: #1e2d42; color: #e2e8f0; border: 1px solid rgba(255,255,255,.1); }
        .vd-toast-loading  .vd-toast-icon { animation: spin .8s linear infinite; display: inline-block; }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* Full-screen loading overlay */
        #vd-overlay {
            display: none;
            position: fixed; inset: 0; z-index: 99998;
            background: rgba(14,22,33,.55);
            backdrop-filter: blur(3px);
            align-items: center;
            justify-content: center;
            flex-direction: column;
            gap: 16px;
        }
        #vd-overlay.show { display: flex; }
        #vd-overlay .spinner {
            width: 52px; height: 52px;
            border: 4px solid rgba(255,255,255,.2);
            border-top-color: #00c9a7;
            border-radius: 50%;
            animation: spin .7s linear infinite;
        }
        #vd-overlay .overlay-msg {
            color: #fff;
            font-size: 14px;
            font-weight: 600;
            font-family: 'DM Sans', system-ui, sans-serif;
        }
    `;

    let styleEl = document.createElement('style');
    styleEl.textContent = STYLE;
    document.head.appendChild(styleEl);

    
    let container = document.getElementById('toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        document.body.appendChild(container);
    }

    let overlay = document.getElementById('vd-overlay');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'vd-overlay';
        overlay.innerHTML = '<div class="spinner"></div><div class="overlay-msg" id="vd-overlay-msg">Processing…</div>';
        document.body.appendChild(overlay);
    }

    
    
    const ICONS = {
        success: '✓',
        error:   '✕',
        warning: '⚠',
        info:    'ℹ',
        loading: '↻',
    };

    let counter = 0;

    function create(type, title, message, duration) {
        const id  = 'toast-' + (++counter);
        const dur = duration ?? (type === 'loading' ? 0 : type === 'error' ? 6000 : 4000);

        const el  = document.createElement('div');
        el.className = `vd-toast vd-toast-${type}`;
        el.id = id;
        el.setAttribute('role', type === 'error' ? 'alert' : 'status');
        el.setAttribute('aria-live', type === 'error' ? 'assertive' : 'polite');

        el.innerHTML = `
            <span class="vd-toast-icon">${ICONS[type] || 'ℹ'}</span>
            <div class="vd-toast-body">
                ${title ? `<div class="vd-toast-title">${escHtml(title)}</div>` : ''}
                ${message ? `<div class="vd-toast-msg">${escHtml(message)}</div>` : ''}
            </div>
            <button class="vd-toast-close" aria-label="Dismiss">×</button>
            ${dur > 0 ? `<div class="vd-toast-progress" style="animation-duration:${dur}ms"></div>` : ''}
        `;

        el.querySelector('.vd-toast-close').onclick = () => dismiss(id);
        el.onclick = (e) => { if (e.target.tagName !== 'BUTTON') dismiss(id); };

        container.appendChild(el);

        if (dur > 0) {
            setTimeout(() => dismiss(id), dur);
        }
        return id;
    }

    function dismiss(id) {
        const el = document.getElementById(id);
        if (!el || el.classList.contains('dismissing')) return;
        el.classList.add('dismissing');
        setTimeout(() => el.remove(), 280);
    }

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    const Toast = {
        success(msg, title)  { return create('success', title || 'Success',  msg); },
        error(msg, title)    { return create('error',   title || 'Error',    msg); },
        warning(msg, title)  { return create('warning', title || 'Warning',  msg); },
        info(msg, title)     { return create('info',    title || 'Info',     msg); },
        loading(msg)         { return create('loading', msg || 'Loading…',   null, 0); },
        dismiss,
        clear() { container.innerHTML = ''; },

        showOverlay(msg) {
            document.getElementById('vd-overlay-msg').textContent = msg || 'Processing…';
            overlay.classList.add('show');
        },
        hideOverlay() {
            overlay.classList.remove('show');
        },
    };

    const LoadingBtn = {
        start(btn, loadingText) {
            if (!btn) return;
            btn._originalHTML    = btn.innerHTML;
            btn._originalDisabled = btn.disabled;
            btn.disabled  = true;
            btn.innerHTML = `<span style="display:inline-block;animation:spin .7s linear infinite;margin-right:6px">↻</span>${escHtml(loadingText || 'Loading…')}`;
            btn.style.opacity = '.8';
        },
        stop(btn) {
            if (!btn || !btn._originalHTML) return;
            btn.innerHTML = btn._originalHTML;
            btn.disabled  = btn._originalDisabled;
            btn.style.opacity = '';
        },
    };


    document.addEventListener('submit', function (e) {
        const form = e.target;
        if (form.dataset.loading === 'true') {
            const msg = form.dataset.loadingMsg || 'Submitting…';
            Toast.showOverlay(msg);
        }
    });


    document.addEventListener('click', function (e) {
        const btn = e.target.closest('[data-toast]');
        if (!btn) return;
        const type = btn.dataset.toast;
        const msg  = btn.dataset.toastMsg || '';
        if (Toast[type]) Toast[type](msg);
    });


    (function checkURLToast() {
        const params = new URLSearchParams(window.location.search);
        const type   = params.get('toast');
        const msg    = params.get('toast_msg') || params.get('success') || params.get('error');
        if (type && Toast[type]) Toast[type](decodeURIComponent(msg || ''));
        if (!type) {
            if (params.get('success')) Toast.success(decodeURIComponent(params.get('success')));
            if (params.get('error'))   Toast.error(decodeURIComponent(params.get('error')));
        }
    })();

  
    global.Toast      = Toast;
    global.LoadingBtn = LoadingBtn;

})(window);