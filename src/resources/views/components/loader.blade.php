<div id="globalLoader" class="global-loader">
    <div class="loader-backdrop"></div>
    <div class="loader-content">
        <div class="spinner-border spinner-lg text-primary" role="status">
            <span class="visually-hidden">Carregando...</span>
        </div>
        <div class="loader-text mt-3">Carregando...</div>
    </div>
</div>

<style>
    .global-loader {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        z-index: 10000;
        pointer-events: none;
        display: none; /* o JS alterna via style.display inline */
    }

    .global-loader.active {
        pointer-events: all;
    }

    .loader-backdrop {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.5);
        opacity: 0;
        transition: opacity 0.2s ease-in-out;
    }

    .global-loader.active .loader-backdrop {
        opacity: 1;
    }

    .loader-content {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        text-align: center;
        opacity: 0;
        transition: opacity 0.2s ease-in-out;
    }

    .global-loader.active .loader-content {
        opacity: 1;
    }

    .loader-text {
        color: #fff;
        font-size: 1rem;
        font-weight: 500;
    }

    [data-bs-theme="light"] .loader-text {
        color: var(--bs-body-color);
    }
</style>

<script>
(function() {
    console.log('[Loader] IMMEDIATE: Script executing');
    
    let loaderTimeout = null;
    let loaderElement = null;
    
    function getLoaderElement() {
        if (!loaderElement) {
            loaderElement = document.getElementById('globalLoader');
        }
        return loaderElement;
    }
    
    function showLoader(delay = 200) {
        const loader = getLoaderElement();
        if (!loader) {
            console.warn('[Loader] Element not ready yet');
            return;
        }
        
        console.log('[Loader] SHOWING LOADER with delay:', delay);
        clearTimeout(loaderTimeout);
        
        loaderTimeout = setTimeout(() => {
            loader.style.display = 'block';
            setTimeout(() => {
                loader.classList.add('active');
                console.log('[Loader] ✓ LOADER VISIBLE');
            }, 10);
        }, delay);
    }
    
    function hideLoader() {
        const loader = getLoaderElement();
        if (!loader) return;
        
        console.log('[Loader] HIDING LOADER');
        clearTimeout(loaderTimeout);
        
        loader.classList.remove('active');
        setTimeout(() => {
            loader.style.display = 'none';
        }, 200);
    }
    
    window.showLoader = showLoader;
    window.hideLoader = hideLoader;
    console.log('[Loader] Functions exposed globally');
    
    window.addEventListener('beforeunload', () => {
        console.log('[Loader] beforeunload - showing loader');
        showLoader(0);
    });
    
    document.addEventListener('click', (e) => {
        console.log('[Loader] CLICK EVENT:', e.target.tagName);
        
        const link = e.target.closest('a');
        if (!link) return;
        
        const href = link.getAttribute('href');
        console.log('[Loader] Link clicked:', href);
        
        if (!href || href.startsWith('#') || href.startsWith('javascript:')) return;
        if (link.target === '_blank' || link.hasAttribute('download')) return;
        if (link.hasAttribute('wire:navigate')) return;
        
        const isSameDomain = link.hostname === window.location.hostname || !link.hostname;
        if (isSameDomain && !e.ctrlKey && !e.metaKey && !e.shiftKey) {
            console.log('[Loader] ✓✓✓ SHOWING LOADER FOR NAVIGATION ✓✓✓');
            showLoader(0);
        }
    }, true);
    
    console.log('[Loader] ✓ Initialized immediately');
})();
</script>
