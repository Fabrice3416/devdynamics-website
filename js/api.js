// ============================================
// API CLIENT
// ============================================

// Détection automatique de l'environnement.
// En local (localhost / 127.0.0.1) on tape directement WAMP/Apache local.
// En production on utilise le même origin que la page courante, ce qui
// évite tout problème de CORS et fonctionne quel que soit le domaine.
const API_BASE_URL = (() => {
  const host = window.location.hostname;
  if (host === 'localhost' || host === '127.0.0.1') {
    return 'http://localhost/api';
  }
  return window.location.origin + '/api';
})();

class APIClient {
  constructor() {
    this.token = localStorage.getItem('auth_token');
  }

  setToken(token) {
    this.token = token;
    localStorage.setItem('auth_token', token);
  }

  getHeaders() {
    const headers = {
      'Content-Type': 'application/json'
    };
    if (this.token) {
      headers['Authorization'] = `Bearer ${this.token}`;
    }
    return headers;
  }

  async request(endpoint, options = {}) {
    const url = `${API_BASE_URL}${endpoint}`;
    const config = {
      ...options,
      headers: this.getHeaders()
    };

    try {
      const response = await fetch(url, config);
      const data = await response.json();

      if (!response.ok) {
        // Un 401 sur une page d'administration signifie une session expiree
        // ou revoquee. La laisser remonter comme une erreur ordinaire affiche
        // un message technique a la place de la seule action utile : se
        // reconnecter. Les pages publiques ne sont pas concernees.
        if (response.status === 401
            && /\/pages\/admin-/.test(window.location.pathname)
            && typeof renvoyerVersConnexion === 'function') {
          renvoyerVersConnexion();
          throw new Error('Session expiree');
        }

        const erreur = new Error(data.message || 'Erreur API');
        erreur.status = response.status;
        throw erreur;
      }

      return data;
    } catch (error) {
      console.error('Erreur API:', error);
      throw error;
    }
  }

  // Auth (Admin)
  async login(email, password) {
    return this.request('/auth/login', {
      method: 'POST',
      body: JSON.stringify({ email, password })
    });
  }

  async logout() {
    this.token = null;
    localStorage.removeItem('auth_token');
    localStorage.removeItem('user_data');
  }

  async createContactMessage(data) {
    return this.request('/contact', {
      method: 'POST',
      body: JSON.stringify(data)
    });
  }
}

const api = new APIClient();
