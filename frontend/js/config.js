// ============================================
// CONFIGURATION AUTOMATIQUE API
// ============================================

/**
 * Détecte automatiquement l'environnement et retourne l'URL API appropriée
 */
function getAPIBaseURL() {
    const hostname = window.location.hostname;

    // Production
    if (hostname !== 'localhost' && hostname !== '127.0.0.1') {
        // L'API se trouve dans le même domaine, dans le dossier /api
        return window.location.origin + '/api';
    }

    // Local - Backend PHP (WAMP)
    if (window.location.port === '' || window.location.port === '80') {
        return 'http://localhost/api';
    }

    // Local - Backend Node.js
    return 'http://localhost:5000/api';
}

// Export de la configuration
const CONFIG = {
    API_BASE_URL: getAPIBaseURL(),
    ENV: window.location.hostname === 'localhost' ? 'development' : 'production'
};

console.log('🔧 Configuration API:', CONFIG.API_BASE_URL);
console.log('🌍 Environnement:', CONFIG.ENV);
