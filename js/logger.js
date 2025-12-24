// ============================================
// LOGGER UTILITY
// Logging conditionnel basé sur l'environnement
// ============================================

const Logger = {
  isDevelopment: window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1',

  log: function(...args) {
    if (this.isDevelopment) {
      console.log(...args);
    }
  },

  error: function(...args) {
    if (this.isDevelopment) {
      console.error(...args);
    }
  },

  warn: function(...args) {
    if (this.isDevelopment) {
      console.warn(...args);
    }
  },

  info: function(...args) {
    if (this.isDevelopment) {
      console.info(...args);
    }
  }
};
